<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «تبديل المناوبة»: employee A proposes to exchange their shifts with colleague B on one or more dates.
 *
 * A proposes → B accepts or declines → the central workflow (ATTENDANCE_SHIFT_SWAP: head recommendation, HR approval)
 * → on final approval both rosters change atomically as employee exceptions linked to the swap, with before/after
 * snapshots. Nothing changes the roster or the attendance before that approval. A may withdraw before the decision;
 * HR may reverse an approved swap with a reason, which revokes the exceptions and recalculates only the affected days.
 * Each date exchanges the two employees' slots: a cross-date exchange is two dates where each works the other's shift.
 */
final class ShiftSwapService
{
    public const STATUS = ['awaiting_counterpart' => 'بانتظار موافقة الزميل', 'counterpart_declined' => 'اعتذر الزميل', 'pending_approval' => 'قيد الاعتماد',
        'approved' => 'معتمد ومطبق على الجدول', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى', 'reversed' => 'أُلغي بعد الاعتماد'];

    public const OPEN = ['awaiting_counterpart', 'pending_approval'];

    public function __construct(
        private AttendanceCalendar $calendar,
        private AttendanceDailyService $daily,
        private AttendanceAccess $access,
        private NotificationCenter $notifications,
        private AttendanceSettings $settings,
    ) {}

    public function available(): bool
    {
        return AttendanceLedger::hasTable('attendance_shift_swaps');
    }

    /**
     * @param  list<string>  $dates
     */
    public function propose(User $requester, Employee $employee, Employee $counterpart, array $dates, string $reason): object
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $max = $this->settings->get('swap_max_days');
        if ($dates === [] || count($dates) > $max) {
            throw ValidationException::withMessages(['dates' => "اختر من يوم إلى {$max} أيام للتبديل."]);
        }
        if ((int) $employee->id === (int) $counterpart->id) {
            throw ValidationException::withMessages(['counterpart_employee_id' => 'اختر زميلًا آخر.']);
        }
        if (! in_array((int) $counterpart->id, array_column($this->access->swapCandidates($employee), 'id'), true)) {
            throw ValidationException::withMessages(['counterpart_employee_id' => 'التبديل متاح مع زملاء القسم النشطين فقط.']);
        }
        if (! $counterpart->user_id || ! DB::table('users')->where('id', $counterpart->user_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['counterpart_employee_id' => 'الزميل لا يملك حسابًا نشطًا لقبول التبديل؛ تنفذه الموارد البشرية كتغيير طارئ إن لزم.']);
        }
        $days = $this->snapshot($employee, $counterpart, $dates);
        $this->ensureNoOpenSwap([(int) $employee->id, (int) $counterpart->id], $dates, null);
        $this->ensureRest($employee, $counterpart, $days);

        return DB::transaction(function () use ($requester, $employee, $counterpart, $days, $reason): object {
            $id = DB::table('attendance_shift_swaps')->insertGetId([
                'requester_employee_id' => $employee->id, 'counterpart_employee_id' => $counterpart->id, 'requested_by' => $requester->id,
                'status' => 'awaiting_counterpart', 'days' => json_encode($days, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'reason' => trim($reason),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            AttendanceAudit::record($requester->id, 'shift_swap', $id, 'proposed', ['days' => $days]);
            $this->notifications->notify((int) $counterpart->user_id, 'attendance_swap_requested', [
                'title' => 'طلب تبديل مناوبة من '.AttendanceLedger::fullName($employee),
                'body' => 'للأيام: '.implode('، ', array_column($days, 'date')).'. وافق أو اعتذر من صفحة الطلب.',
                'url' => self::url($id), 'dedupe' => 'attendance-swap:'.$id.':requested',
            ]);

            return DB::table('attendance_shift_swaps')->where('id', $id)->first();
        });
    }

    /**
     * The colleague accepts (the request then enters its approval workflow) or declines.
     */
    public function respond(User $user, object $swap, bool $accept, ?string $note): void
    {
        $counterpart = Employee::query()->findOrFail($swap->counterpart_employee_id);
        abort_unless((int) $counterpart->user_id === (int) $user->id, 403, 'الرد متاح للزميل المطلوب منه التبديل فقط.');
        DB::transaction(function () use ($user, $swap, $accept, $note, $counterpart): void {
            $row = DB::table('attendance_shift_swaps')->where('id', $swap->id)->lockForUpdate()->first();
            if ($row->status !== 'awaiting_counterpart') {
                throw ValidationException::withMessages(['status' => 'لم يعد الطلب بانتظار ردك.']);
            }
            if (! $accept) {
                DB::table('attendance_shift_swaps')->where('id', $row->id)->update(['status' => 'counterpart_declined', 'counterpart_note' => $note,
                    'counterpart_responded_at' => now(), 'updated_at' => now()]);
                AttendanceAudit::record($user->id, 'shift_swap', (int) $row->id, 'counterpart_declined', ['note' => $note]);
                $this->notifyParties($row, 'اعتذر الزميل عن تبديل المناوبة', $note ?: 'لم يُذكر سبب.', 'declined', [(int) $row->requested_by]);

                return;
            }
            $employee = Employee::query()->findOrFail($row->requester_employee_id);
            $this->ensureUnchanged($row, $employee, $counterpart);
            $requester = User::query()->findOrFail($row->requested_by);
            $departmentId = (int) ($employee->department_id ?: $requester->department_id);
            if ($departmentId <= 0) {
                throw ValidationException::withMessages(['status' => 'يجب ربط ملف مقدم الطلب بقسم قبل رفع التبديل للاعتماد.']);
            }
            DB::table('attendance_shift_swaps')->where('id', $row->id)->update(['status' => 'pending_approval', 'counterpart_note' => $note,
                'counterpart_responded_at' => now(), 'updated_at' => now()]);
            AttendanceAudit::record($user->id, 'shift_swap', (int) $row->id, 'counterpart_accepted', ['note' => $note]);
            $instance = app(WorkflowEngine::class)->startForSubject($requester, 'ATTENDANCE_SHIFT_SWAP', ShiftSwapWorkflow::SUBJECT_TYPE, (int) $row->id, $departmentId);
            DB::table('attendance_shift_swaps')->where('id', $row->id)->update(['workflow_instance_id' => $instance->id]);
            $this->notifyParties($row, 'وافق الزميل على تبديل المناوبة', 'رُفع الطلب للاعتماد ('.$instance->reference_no.').', 'accepted', [(int) $row->requested_by]);
        });
    }

    /**
     * The requester withdraws before the final decision.
     */
    public function cancel(User $user, object $swap): void
    {
        abort_unless((int) $swap->requested_by === (int) $user->id, 403, 'سحب الطلب متاح لمقدمه فقط.');
        DB::transaction(function () use ($user, $swap): void {
            $row = DB::table('attendance_shift_swaps')->where('id', $swap->id)->lockForUpdate()->first();
            if (! in_array($row->status, self::OPEN, true)) {
                throw ValidationException::withMessages(['status' => 'لا يمكن سحب طلب تم البت فيه.']);
            }
            if ($row->workflow_instance_id && DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->value('status') === 'in_progress') {
                app(WorkflowEngine::class)->cancel((int) $row->workflow_instance_id, $user, 'سحب مقدم الطلب طلب تبديل المناوبة');
            }
            DB::table('attendance_shift_swaps')->where('id', $row->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            AttendanceAudit::record($user->id, 'shift_swap', (int) $row->id, 'cancelled');
            $this->notifyParties($row, 'سُحب طلب تبديل المناوبة', 'سحب مقدم الطلب طلبه.', 'cancelled', []);
        });
    }

    /**
     * Final approval (inside the workflow transaction): re-checks everything and writes both rosters atomically.
     */
    public function apply(object $swap, int $actorId, ?string $comment): void
    {
        $row = DB::table('attendance_shift_swaps')->where('id', $swap->id)->lockForUpdate()->first();
        if ($row->status !== 'pending_approval') {
            throw ValidationException::withMessages(['transition' => 'طلب التبديل ليس بانتظار الاعتماد.']);
        }
        $employee = Employee::query()->findOrFail($row->requester_employee_id);
        $counterpart = Employee::query()->findOrFail($row->counterpart_employee_id);
        $days = $this->ensureUnchanged($row, $employee, $counterpart);
        $this->ensureRest($employee, $counterpart, $days);
        $overrides = [];
        foreach ($days as $day) {
            foreach ([[$employee, $day['counterpart_before'], $counterpart], [$counterpart, $day['requester_before'], $employee]] as [$person, $slot, $other]) {
                $overrides[] = DB::table('attendance_overrides')->insertGetId([
                    'label' => 'تبديل مناوبة #'.$row->id.' مع '.AttendanceLedger::fullName($other), 'kind' => 'swap', 'employee_id' => $person->id, 'department_id' => null,
                    'starts_on' => $day['date'], 'ends_on' => $day['date'],
                    'start_time' => $slot['status'] === 'work' ? $slot['in'] : null, 'end_time' => $slot['status'] === 'work' ? $slot['out'] : null,
                    'ends_next_day' => $slot['status'] === 'work' && $slot['next_day'], 'effect' => $slot['status'] === 'work' ? 'schedule' : 'off',
                    'status' => 'approved', 'reason' => $row->reason, 'created_by' => $row->requested_by, 'approved_by' => $actorId, 'approved_at' => now(),
                    'swap_id' => $row->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $applied = ['overrides' => $overrides, 'before' => $days, 'after' => array_map(fn (array $day): array => ['date' => $day['date'],
            'requester_after' => $day['counterpart_before'], 'counterpart_after' => $day['requester_before']], $days)];
        DB::table('attendance_shift_swaps')->where('id', $row->id)->update(['status' => 'approved', 'applied' => json_encode($applied, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'decided_by' => $actorId, 'decided_at' => now(), 'decision_note' => $comment, 'updated_at' => now()]);
        AttendanceAudit::record($actorId, 'shift_swap', (int) $row->id, 'approved_applied', $applied);
        $this->refresh($row, $days);
        $this->notifyParties($row, 'اعتُمد تبديل المناوبة وطُبق على الجدول', 'للأيام: '.implode('، ', array_column($days, 'date')).'.', 'approved', [], true);
    }

    public function rejected(object $swap, int $actorId, ?string $comment): void
    {
        DB::table('attendance_shift_swaps')->where('id', $swap->id)->update(['status' => 'rejected', 'decided_by' => $actorId, 'decided_at' => now(),
            'decision_note' => $comment, 'updated_at' => now()]);
        AttendanceAudit::record($actorId, 'shift_swap', (int) $swap->id, 'rejected', ['comment' => $comment]);
        $this->notifyParties($swap, 'رُفض طلب تبديل المناوبة', $comment ?: 'راجع سبب القرار في الطلب.', 'rejected', []);
    }

    /**
     * HR reverses an approved swap: its exceptions are revoked (kept for history) and the affected past days recalculated.
     */
    public function reverse(User $user, object $swap, string $reason): void
    {
        abort_unless($this->access->canDecideHr($user), 403, 'إلغاء تبديل معتمد لرئيس قسم الموارد البشرية أو المدير التنفيذي.');
        DB::transaction(function () use ($user, $swap, $reason): void {
            $row = DB::table('attendance_shift_swaps')->where('id', $swap->id)->lockForUpdate()->first();
            if ($row->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'يُلغى التبديل المعتمد فقط.']);
            }
            DB::table('attendance_overrides')->where('swap_id', $row->id)->whereNull('revoked_at')
                ->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $user->id, 'revocation_reason' => $reason, 'updated_at' => now()]);
            DB::table('attendance_shift_swaps')->where('id', $row->id)->update(['status' => 'reversed', 'reversed_by' => $user->id, 'reversed_at' => now(),
                'reversal_reason' => $reason, 'updated_at' => now()]);
            $days = json_decode((string) $row->days, true) ?: [];
            AttendanceAudit::record($user->id, 'shift_swap', (int) $row->id, 'reversed', ['reason' => $reason, 'days' => array_column($days, 'date')]);
            $this->refresh($row, $days);
            $this->notifyParties($row, 'أُلغي تبديل مناوبة معتمد', 'عاد جدولك كما كان للأيام: '.implode('، ', array_column($days, 'date')).' — '.$reason, 'reversed', [], true);
        });
    }

    /**
     * Both employees' slots on each date, as stored with the request.
     *
     * @param  list<string>  $dates
     * @return list<array{date: string, requester_before: array<string, mixed>, counterpart_before: array<string, mixed>}>
     */
    private function snapshot(Employee $employee, Employee $counterpart, array $dates): array
    {
        $today = CarbonImmutable::now(config('app.timezone'));
        $slots = $this->calendar->resolve([(int) $employee->id, (int) $counterpart->id], $dates[0], end($dates));
        $days = [];
        foreach ($dates as $date) {
            if ($date < $today->toDateString()) {
                throw ValidationException::withMessages(['dates' => "لا يمكن تبديل يوم مضى ({$date})."]);
            }
            $mine = $this->describe($slots[(int) $employee->id][$date] ?? null);
            $theirs = $this->describe($slots[(int) $counterpart->id][$date] ?? null);
            foreach ([[$mine, 'لديك'], [$theirs, 'لدى الزميل']] as [$slot, $who]) {
                if (! in_array($slot['status'], ['work', 'off'], true)) {
                    throw ValidationException::withMessages(['dates' => "{$who} في {$date} حالة لا تقبل التبديل ({$slot['label']})."]);
                }
                if ($slot['status'] === 'work' && $slot['starts_at'] <= $today->format('Y-m-d H:i:sP')) {
                    throw ValidationException::withMessages(['dates' => "بدأت وردية {$date} بالفعل؛ لا يمكن تبديلها."]);
                }
            }
            if ($mine['status'] === 'off' && $theirs['status'] === 'off') {
                throw ValidationException::withMessages(['dates' => "لا توجد وردية لأي منكما في {$date}."]);
            }
            if ($mine['label'] === $theirs['label'] && $mine['status'] === $theirs['status']) {
                throw ValidationException::withMessages(['dates' => "ورديتا {$date} متطابقتان؛ لا حاجة إلى التبديل."]);
            }
            foreach ([$employee, $counterpart] as $person) {
                if (DB::table('employee_leave_requests')->where('employee_id', $person->id)->whereIn('status', ['submitted', 'manager_approved', 'pending_registration', 'approved'])
                    ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists()) {
                    throw ValidationException::withMessages(['dates' => 'لدى '.AttendanceLedger::fullName($person)." إجازة معتمدة أو قيد الاعتماد في {$date}."]);
                }
                if (DB::table('attendance_permission_requests')->where('employee_id', $person->id)->where('work_date', $date)->whereIn('status', ['submitted', 'approved'])->exists()) {
                    throw ValidationException::withMessages(['dates' => 'لدى '.AttendanceLedger::fullName($person)." طلب إذن في {$date}؛ اسحبه أولًا."]);
                }
            }
            $days[] = ['date' => $date, 'requester_before' => $mine, 'counterpart_before' => $theirs];
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>|null  $slot
     * @return array{status: string, in: ?string, out: ?string, next_day: bool, starts_at: ?string, ends_at: ?string, label: string, shift: ?string}
     */
    private function describe(?array $slot): array
    {
        $status = $slot['status'] ?? 'none';
        $work = $status === 'work';

        return [
            'status' => $status,
            'in' => $work ? $slot['in']->format('H:i') : null,
            'out' => $work ? $slot['out']->format('H:i') : null,
            'next_day' => $work && $slot['out']->toDateString() !== $slot['in']->toDateString(),
            'starts_at' => $work ? $slot['in']->format('Y-m-d H:i:sP') : null,
            'ends_at' => $work ? $slot['out']->format('Y-m-d H:i:sP') : null,
            'label' => $work ? $slot['in']->format('H:i').'–'.$slot['out']->format('H:i').($slot['out']->toDateString() !== $slot['in']->toDateString() ? ' (+1)' : '')
                : (['off' => 'راحة', 'no_duty' => 'لا يوجد دوام مقرر', 'no_punch' => 'معفى من البصمة', 'none' => 'لا يوجد دوام مقرر'][$status] ?? 'استثناء'),
            'shift' => $slot['schedule']['name'] ?? null,
        ];
    }

    /**
     * The rosters must still be what both employees agreed to.
     *
     * @return list<array<string, mixed>>
     */
    private function ensureUnchanged(object $row, Employee $employee, Employee $counterpart): array
    {
        $stored = json_decode((string) $row->days, true) ?: [];
        $current = $this->snapshot($employee, $counterpart, array_column($stored, 'date'));
        $this->ensureNoOpenSwap([(int) $employee->id, (int) $counterpart->id], array_column($stored, 'date'), (int) $row->id);
        foreach ($stored as $index => $day) {
            foreach (['requester_before', 'counterpart_before'] as $side) {
                if ($day[$side]['label'] !== $current[$index][$side]['label'] || $day[$side]['status'] !== $current[$index][$side]['status']) {
                    throw ValidationException::withMessages(['transition' => "تغير جدول أحد الطرفين في {$day['date']} منذ تقديم الطلب؛ يلزم طلب جديد."]);
                }
            }
        }

        return $stored;
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  list<string>  $dates
     */
    private function ensureNoOpenSwap(array $employeeIds, array $dates, ?int $except): void
    {
        $open = DB::table('attendance_shift_swaps')->whereIn('status', [...self::OPEN, 'approved'])->when($except, fn ($query) => $query->where('id', '<>', $except))
            ->where(fn ($query) => $query->whereIn('requester_employee_id', $employeeIds)->orWhereIn('counterpart_employee_id', $employeeIds))
            ->get(['id', 'days', 'status']);
        foreach ($open as $swap) {
            $taken = array_intersect($dates, array_column(json_decode((string) $swap->days, true) ?: [], 'date'));
            if ($taken !== []) {
                throw ValidationException::withMessages(['dates' => 'يوجد طلب تبديل آخر ('.self::STATUS[$swap->status].') لأحد الطرفين في: '.implode('، ', $taken).'.']);
            }
        }
    }

    /**
     * After the exchange each person keeps the minimum rest between shifts and the maximum hours of a shift.
     *
     * @param  list<array<string, mixed>>  $days
     */
    private function ensureRest(Employee $employee, Employee $counterpart, array $days): void
    {
        $minRest = $this->settings->get('swap_min_rest_hours') * 3600;
        $maxShift = $this->settings->get('swap_max_hours_per_day') * 3600;
        $from = CarbonImmutable::parse($days[0]['date'])->subDay()->toDateString();
        $to = CarbonImmutable::parse(end($days)['date'])->addDay()->toDateString();
        $slots = $this->calendar->resolve([(int) $employee->id, (int) $counterpart->id], $from, $to);
        foreach ([[(int) $employee->id, 'counterpart_before'], [(int) $counterpart->id, 'requester_before']] as [$personId, $takes]) {
            $shifts = [];
            foreach ($slots[$personId] as $date => $slot) {
                $swapped = collect($days)->firstWhere('date', $date);
                $value = $swapped ? $swapped[$takes] : $this->describe($slot);
                if ($value['status'] === 'work') {
                    $shifts[] = [$date, CarbonImmutable::parse($value['starts_at']), CarbonImmutable::parse($value['ends_at'])];
                }
            }
            $name = AttendanceLedger::fullName(Employee::query()->find($personId));
            foreach ($shifts as $index => [$date, $in, $out]) {
                if ($out->getTimestamp() - $in->getTimestamp() > $maxShift) {
                    throw ValidationException::withMessages(['dates' => "وردية {$date} لـ{$name} تتجاوز الحد الأقصى للساعات."]);
                }
                $next = $shifts[$index + 1] ?? null;
                if ($next && $next[1]->getTimestamp() - $out->getTimestamp() < $minRest) {
                    throw ValidationException::withMessages(['dates' => 'بعد التبديل لا تتحقق راحة '.($minRest / 3600)." ساعات على الأقل لـ{$name} بين ورديتي {$date} و{$next[0]}."]);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function refresh(object $swap, array $days): void
    {
        foreach ($days as $day) {
            foreach ([(int) $swap->requester_employee_id, (int) $swap->counterpart_employee_id] as $employeeId) {
                $this->daily->refreshDays($employeeId, $day['date'], $day['date']);
            }
        }
    }

    /**
     * @param  list<int>  $only  user ids to tell; empty means both employees (and the requester's account)
     */
    private function notifyParties(object $swap, string $title, string $body, string $event, array $only, bool $scheduleChanged = false): void
    {
        $users = $only !== [] ? $only : array_values(array_filter([(int) $swap->requested_by,
            (int) DB::table('employees')->where('id', $swap->requester_employee_id)->value('user_id'),
            (int) DB::table('employees')->where('id', $swap->counterpart_employee_id)->value('user_id')]));
        $payload = ['title' => $title, 'body' => $body, 'url' => self::url((int) $swap->id), 'dedupe' => 'attendance-swap:'.$swap->id.':'.$event];
        $this->notifications->notifyMany($users, 'attendance_swap_decided', $payload);
        if ($scheduleChanged) {
            $this->notifications->notifyMany($users, 'attendance_schedule_changed', ['dedupe' => 'attendance-swap-schedule:'.$swap->id.':'.$event] + $payload);
        }
    }

    public static function url(int $id): string
    {
        return '/attendance/requests/swap/'.$id;
    }
}
