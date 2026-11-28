<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «طلب إذن»: a late-arrival, early-departure or temporary-exit permission for one shift.
 *
 * Types and their rules live in system constants (attendance_permission_types; rules in each value's metadata):
 * the effect, a maximum duration, a lead time, how far back a request may go, the attachment rule, monthly caps,
 * whether an approved permission excuses the minutes, and the pay effect — always "none" until HR defines a policy.
 *
 * An employee's request runs on the central workflow (ATTENDANCE_PERMISSION). HR may also register an approved
 * permission on an employee's behalf with a justification, and revoke an approved one with a reason; both are audited.
 * Only approved permissions change the day's interpretation; the punches themselves never change.
 */
final class AttendancePermissionService
{
    public const STATUS = ['submitted' => 'قيد الاعتماد', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى', 'revoked' => 'ألغته الموارد البشرية'];

    public const EFFECTS = ['late' => 'يفسّر التأخير عند بداية الدوام', 'early' => 'يفسّر الانصراف قبل نهاية الدوام', 'temporary_exit' => 'خروج مؤقت أثناء الدوام (للعلم)'];

    public const ATTACHMENT_RULES = ['none' => 'بلا مرفق', 'optional' => 'مرفق اختياري', 'required' => 'مرفق إلزامي'];

    /** @var array<string, mixed> */
    public const DEFAULT_RULES = ['effect' => 'temporary_exit', 'max_minutes' => null, 'lead_hours' => null, 'retro_days' => null, 'attachment' => 'optional',
        'monthly_cap_count' => null, 'monthly_cap_minutes' => null, 'excuses' => true, 'pay_effect' => 'none'];

    public function __construct(
        private AttendanceCalendar $calendar,
        private AttendanceDailyService $daily,
        private NotificationCenter $notifications,
    ) {}

    public function available(): bool
    {
        return AttendanceLedger::hasTable('attendance_permission_requests');
    }

    /**
     * Active permission types in force today, with their rules.
     *
     * @return list<array{id: int, code: string, name: string, rules: array<string, mixed>}>
     */
    public function types(bool $includeInactive = false): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'attendance_permission_types')
            ->when(! $includeInactive, fn ($query) => $query->where('t.is_active', true)->where('v.is_active', true)
                ->where(fn ($from) => $from->whereNull('v.effective_from')->orWhere('v.effective_from', '<=', $today))
                ->where(fn ($until) => $until->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $today)))
            ->orderBy('v.sort_order')->orderBy('v.id')->get(['v.id', 'v.code', 'v.name_ar', 'v.metadata', 'v.is_active'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name_ar,
                'is_active' => (bool) $row->is_active, 'rules' => self::rules($row->metadata)])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(?string $metadata): array
    {
        return array_replace(self::DEFAULT_RULES, array_intersect_key(json_decode((string) $metadata, true) ?: [], self::DEFAULT_RULES));
    }

    /**
     * Validates a request and returns its instants. A permission belongs to a scheduled shift; for a shift crossing
     * midnight, times after midnight are read on the next calendar day.
     *
     * @param  array{type_value_id: int|string, work_date: string, from: string, to: string}  $input
     * @return array{type: array<string, mixed>, starts_at: CarbonImmutable, ends_at: CarbonImmutable, minutes: int}
     */
    public function validate(Employee $employee, array $input, bool $byHr, ?UploadedFile $file): array
    {
        $type = collect($this->types())->firstWhere('id', (int) $input['type_value_id'])
            ?? throw ValidationException::withMessages(['type_value_id' => 'نوع الإذن غير متاح.']);
        $rules = $type['rules'];
        $tz = config('app.timezone');
        $date = (string) $input['work_date'];
        $today = CarbonImmutable::now($tz)->startOfDay();
        if ($employee->employment_status !== 'active' || $employee->deleted_at !== null) {
            throw ValidationException::withMessages(['work_date' => 'ملف الموظف غير نشط.']);
        }
        $slot = $this->calendar->expected((int) $employee->id, $date);
        if (($slot['status'] ?? null) !== 'work') {
            throw ValidationException::withMessages(['work_date' => 'لا يوجد دوام مقرر للموظف في هذا اليوم؛ لا حاجة إلى إذن.']);
        }
        $start = CarbonImmutable::parse($date.' '.$input['from'], $tz);
        $end = CarbonImmutable::parse($date.' '.$input['to'], $tz);
        if ($slot['out']->toDateString() !== $date && $start->lessThan($slot['in'])) {
            $start = $start->addDay();
            $end = $end->addDay();
        }
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }
        $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        if ($end->lessThanOrEqualTo($slot['in']) || $start->greaterThanOrEqualTo($slot['out'])) {
            throw ValidationException::withMessages(['from' => 'يجب أن تقع فترة الإذن ضمن الوردية المقررة ('.$slot['in']->format('H:i').'–'.$slot['out']->format('H:i').').']);
        }
        if ($minutes < 1 || $minutes > 1440) {
            throw ValidationException::withMessages(['to' => 'مدة الإذن غير صالحة.']);
        }
        if ($rules['max_minutes'] !== null && $minutes > (int) $rules['max_minutes']) {
            throw ValidationException::withMessages(['to' => 'المدة تتجاوز الحد الأقصى لهذا النوع ('.(int) $rules['max_minutes'].' دقيقة).']);
        }
        if (! $byHr) {
            if ($rules['lead_hours'] !== null && CarbonImmutable::now($tz)->addHours((int) $rules['lead_hours'])->greaterThan($start)) {
                throw ValidationException::withMessages(['from' => 'يُقدَّم هذا النوع قبل '.(int) $rules['lead_hours'].' ساعة على الأقل من بدايته.']);
            }
            if ($rules['retro_days'] !== null && $date < $today->subDays((int) $rules['retro_days'])->toDateString()) {
                throw ValidationException::withMessages(['work_date' => 'لا يُقبل هذا النوع لأيام مضى عليها أكثر من '.(int) $rules['retro_days'].' يوم.']);
            }
        }
        if ($date > $today->addDays(90)->toDateString()) {
            throw ValidationException::withMessages(['work_date' => 'لا يُقدم الإذن لأكثر من 90 يومًا مقدمًا.']);
        }
        if (DB::table('employee_leave_requests')->where('employee_id', $employee->id)->whereIn('status', ['approved', 'pending_registration'])
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists()) {
            throw ValidationException::withMessages(['work_date' => 'لدى الموظف إجازة معتمدة في هذا اليوم.']);
        }
        if (DB::table('attendance_permission_requests')->where('employee_id', $employee->id)->whereIn('status', ['submitted', 'approved'])
            ->where('starts_at', '<', $end->format('Y-m-d H:i:sP'))->where('ends_at', '>', $start->format('Y-m-d H:i:sP'))->exists()) {
            throw ValidationException::withMessages(['from' => 'يوجد طلب إذن آخر قيد الاعتماد أو معتمد يتداخل مع هذه الفترة.']);
        }
        if ($rules['monthly_cap_count'] !== null || $rules['monthly_cap_minutes'] !== null) {
            $month = DB::table('attendance_permission_requests')->where('employee_id', $employee->id)->where('type_value_id', $type['id'])
                ->whereIn('status', ['submitted', 'approved'])
                ->whereBetween('work_date', [CarbonImmutable::parse($date)->startOfMonth()->toDateString(), CarbonImmutable::parse($date)->endOfMonth()->toDateString()])
                ->selectRaw('COUNT(*) as requests, COALESCE(SUM(COALESCE(approved_minutes, minutes)), 0) as minutes')->first();
            if ($rules['monthly_cap_count'] !== null && (int) $month->requests + 1 > (int) $rules['monthly_cap_count']) {
                throw ValidationException::withMessages(['work_date' => 'بلغ الموظف الحد الشهري لعدد طلبات هذا النوع ('.(int) $rules['monthly_cap_count'].').']);
            }
            if ($rules['monthly_cap_minutes'] !== null && (int) $month->minutes + $minutes > (int) $rules['monthly_cap_minutes']) {
                throw ValidationException::withMessages(['to' => 'تتجاوز المدة الرصيد الشهري لهذا النوع ('.(int) $rules['monthly_cap_minutes'].' دقيقة).']);
            }
        }
        if ($rules['attachment'] === 'required' && ! $file) {
            throw ValidationException::withMessages(['attachment' => 'هذا النوع يتطلب إرفاق مستند.']);
        }
        if ($rules['attachment'] === 'none' && $file) {
            throw ValidationException::withMessages(['attachment' => 'هذا النوع لا يقبل مرفقات.']);
        }

        return ['type' => $type, 'starts_at' => $start, 'ends_at' => $end, 'minutes' => $minutes];
    }

    /**
     * The employee's own request, sent into its workflow.
     *
     * @param  array{type_value_id: int|string, work_date: string, from: string, to: string, reason: string}  $input
     */
    public function submit(User $requester, Employee $employee, array $input, ?UploadedFile $file): object
    {
        $departmentId = (int) ($employee->department_id ?: $requester->department_id);
        if ($departmentId <= 0) {
            throw ValidationException::withMessages(['work_date' => 'يجب ربط ملف الموظف بقسم قبل تقديم طلب إذن؛ راجع الموارد البشرية.']);
        }

        return $this->store($requester, $employee, $input, fn (): array => $this->validate($employee, $input, false, $file), $file, function (int $id) use ($requester, $departmentId): array {
            $instance = app(WorkflowEngine::class)->startForSubject($requester, 'ATTENDANCE_PERMISSION', AttendancePermissionWorkflow::SUBJECT_TYPE, $id, $departmentId);

            return ['status' => 'submitted', 'source' => 'employee', 'workflow_instance_id' => $instance->id];
        });
    }

    /**
     * HR registers an approved permission on the employee's behalf (for example a documented emergency). Audited with
     * the justification; the employee is told.
     *
     * @param  array{type_value_id: int|string, work_date: string, from: string, to: string, reason: string}  $input
     */
    public function registerByHr(User $hr, Employee $employee, array $input, string $justification, ?UploadedFile $file): object
    {
        $row = $this->store($hr, $employee, $input, fn (): array => $this->validate($employee, $input, true, $file), $file,
            fn (int $id, array $period): array => ['status' => 'approved', 'source' => 'hr', 'approved_minutes' => $period['minutes'],
                'decided_by' => $hr->id, 'decided_at' => now(), 'decision_note' => $justification]);
        $this->afterDecision($row, 'approved', $hr->id, $justification);

        return $row;
    }

    /**
     * The overlap and monthly-cap checks run under the employee row lock, as leave submission does
     * (LeaveSubmissionRules), so two requests sent at the same moment cannot both pass them.
     *
     * @param  array<string, mixed>  $input
     * @param  callable(): array{type: array<string, mixed>, starts_at: CarbonImmutable, ends_at: CarbonImmutable, minutes: int}  $validate
     * @param  callable(int, array<string, mixed>): array<string, mixed>  $finish  columns written after the row exists (status, workflow)
     */
    private function store(User $actor, Employee $employee, array $input, callable $validate, ?UploadedFile $file, callable $finish): object
    {
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $employee, $input, $validate, $file, $finish, &$stored): object {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
                $period = $validate();
                $id = DB::table('attendance_permission_requests')->insertGetId([
                    'employee_id' => $employee->id, 'requested_by' => $actor->id, 'type_value_id' => $period['type']['id'], 'work_date' => $input['work_date'],
                    'starts_at' => $period['starts_at']->format('Y-m-d H:i:sP'), 'ends_at' => $period['ends_at']->format('Y-m-d H:i:sP'),
                    'minutes' => $period['minutes'], 'reason' => trim((string) $input['reason']), 'status' => 'submitted', 'source' => 'employee',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($file) {
                    $stored = $file->storeAs('attendance/permissions/'.$id, 'attachment-'.bin2hex(random_bytes(6)).'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'), 'local');
                    DB::table('attendance_permission_requests')->where('id', $id)->update(['attachment_path' => $stored,
                        'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'attachment_mime' => $file->getMimeType(), 'attachment_size' => $file->getSize()]);
                }
                $columns = $finish($id, $period);
                DB::table('attendance_permission_requests')->where('id', $id)->update($columns + ['updated_at' => now()]);
                AttendanceAudit::record($actor->id, 'permission', $id, $columns['status'] === 'approved' ? 'registered_by_hr' : 'submitted', [
                    'employee_id' => $employee->id, 'type' => $period['type']['code'], 'work_date' => $input['work_date'], 'minutes' => $period['minutes'],
                    'from' => $period['starts_at']->format('Y-m-d H:i:sP'), 'to' => $period['ends_at']->format('Y-m-d H:i:sP'),
                    'justification' => $columns['decision_note'] ?? null,
                ]);

                return DB::table('attendance_permission_requests')->where('id', $id)->first();
            });
        } catch (Throwable $exception) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $exception;
        }
    }

    /**
     * The requester withdraws a request still in process.
     */
    public function cancel(User $user, object $request): void
    {
        abort_unless((int) $request->requested_by === (int) $user->id, 403, 'سحب الطلب متاح لمقدمه فقط.');
        if ($request->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'لا يمكن سحب طلب تم البت فيه.']);
        }
        DB::transaction(function () use ($user, $request): void {
            if ($request->workflow_instance_id && DB::table('workflow_instances')->where('id', $request->workflow_instance_id)->value('status') === 'in_progress') {
                app(WorkflowEngine::class)->cancel((int) $request->workflow_instance_id, $user, 'سحب الموظف طلب الإذن');
            }
            DB::table('attendance_permission_requests')->where('id', $request->id)->where('status', 'submitted')->update(['status' => 'cancelled', 'updated_at' => now()]);
            AttendanceAudit::record($user->id, 'permission', (int) $request->id, 'cancelled');
        });
    }

    /**
     * HR revokes an approved permission with a reason; the day is recalculated without it.
     */
    public function revoke(User $hr, object $request, string $reason): void
    {
        if ($request->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'يُلغى الإذن المعتمد فقط.']);
        }
        DB::transaction(function () use ($hr, $request, $reason): void {
            DB::table('attendance_permission_requests')->where('id', $request->id)->where('status', 'approved')
                ->update(['status' => 'revoked', 'revoked_by' => $hr->id, 'revoked_at' => now(), 'revocation_reason' => $reason, 'updated_at' => now()]);
            AttendanceAudit::record($hr->id, 'permission', (int) $request->id, 'revoked', ['reason' => $reason]);
            $this->daily->refreshDays((int) $request->employee_id, (string) $request->work_date, (string) $request->work_date);
            $this->notifyEmployee($request, 'أُلغي إذن معتمد', 'ألغت الموارد البشرية الإذن ليوم '.$request->work_date.': '.$reason, 'revoked');
        });
    }

    /**
     * Final decision from the workflow (inside its transaction).
     */
    public function decided(object $request, string $outcome, int $actorId, ?string $comment): void
    {
        DB::table('attendance_permission_requests')->where('id', $request->id)->update([
            'status' => $outcome, 'approved_minutes' => $outcome === 'approved' ? $request->minutes : null,
            'decided_by' => $actorId, 'decided_at' => now(), 'decision_note' => $comment, 'updated_at' => now(),
        ]);
        AttendanceAudit::record($actorId, 'permission', (int) $request->id, $outcome, ['comment' => $comment]);
        $this->afterDecision(DB::table('attendance_permission_requests')->where('id', $request->id)->first(), $outcome, $actorId, $comment);
    }

    private function afterDecision(object $request, string $outcome, int $actorId, ?string $comment): void
    {
        if ($outcome === 'approved') {
            $this->daily->refreshDays((int) $request->employee_id, (string) $request->work_date, (string) $request->work_date);
        }
        $type = DB::table('master_data_values')->where('id', $request->type_value_id)->value('name_ar');
        $this->notifyEmployee($request, $outcome === 'approved' ? 'اعتُمد طلب '.$type : 'رُفض طلب '.$type,
            'ليوم '.$request->work_date.($comment ? ' — '.$comment : ''), $outcome);
    }

    private function notifyEmployee(object $request, string $title, string $body, string $outcome): void
    {
        $userId = DB::table('employees')->where('id', $request->employee_id)->value('user_id');
        $this->notifications->notifyMany(array_filter([(int) $request->requested_by, $userId ? (int) $userId : null]), 'attendance_permission_decided', [
            'title' => $title, 'body' => $body, 'url' => self::url((int) $request->id), 'dedupe' => 'attendance-permission:'.$request->id.':'.$outcome,
        ]);
    }

    public static function url(int $id): string
    {
        return '/attendance/requests/permission/'.$id;
    }
}
