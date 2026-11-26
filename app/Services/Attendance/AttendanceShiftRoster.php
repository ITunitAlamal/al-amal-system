<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Services\Departments\DepartmentWorkspace;
use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «جدول المناوبات» — the monthly roster a department head plans for their staff (owner decisions 2026-11-02).
 *
 * - The appointed head of a department (and its units) edits it directly; it applies at once, every cell is audited,
 *   and the employee is notified. The HR head and the executive director read every department. The system
 *   administrator reads only: planning staff is a business decision.
 * - Only today and later days are edited: a past day is corrected through an HR exception, never by rewriting the plan.
 * - A cell is a shift (a named shift type, or hours written for this employee only) or a rest day. Clearing a cell
 *   returns the day to the general hours.
 * - Rest shorter than the HR minimum between two planned shifts is reported as a warning, not refused.
 */
final class AttendanceShiftRoster
{
    public const MAX_DAYS_AHEAD = 120;

    public const MAX_CHANGES = 2500;

    private const RESULT_MARKS = [
        'present' => ['ح', 'حضور'], 'late' => ['ت', 'تأخير'], 'early' => ['م', 'خروج مبكر'], 'in_progress' => ['…', 'قيد العمل'],
        'missing_punches' => ['غ', 'بلا بصمات'], 'incomplete_punches' => ['ن', 'بصمة ناقصة'], 'not_enrolled' => ['؟', 'غير مسجل على الجهاز'],
        'off_day_punch' => ['ب', 'بصمة في يوم بلا دوام'], 'emergency_holiday' => ['ع', 'عطلة طارئة'], 'holiday_or_leave_work_review' => ['ر', 'تحتاج مراجعة'],
    ];

    public function __construct(private readonly DepartmentWorkspace $workspace, private readonly AttendanceCalendar $calendar, private readonly AttendanceDailyService $daily,
        private readonly NotificationCenter $notifications, private readonly AttendanceSettings $settings) {}

    /** Departments (with their units) whose roster this user plans. @return list<int> */
    public function editableDepartments(User $user): array
    {
        return $user->is_active && ! $user->isSystemAdmin() ? $this->workspace->departments($user) : [];
    }

    /** @return list<int> */
    public function viewableDepartments(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($user->isSystemAdmin() || $user->isHrDepartmentHead() || app(AttendanceAccess::class)->isExecutive($user)) {
            return DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        return $this->editableDepartments($user);
    }

    public function canOpen(User $user): bool
    {
        return $this->viewableDepartments($user) !== [];
    }

    /** The month grid of one department (and its units). */
    public function board(User $user, int $departmentId, string $month): array
    {
        abort_unless(in_array($departmentId, $this->viewableDepartments($user), true), 403, 'هذا القسم خارج الأقسام التي ترأسها أو تتابعها.');
        $editable = in_array($departmentId, $this->editableDepartments($user), true);
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
        $from = $start->toDateString();
        $to = $start->endOfMonth()->toDateString();
        $today = $this->today();
        $scope = $this->withUnits([$departmentId]);
        $employees = DB::table('employees')->whereIn('department_id', $scope)->whereNull('deleted_at')->where('employment_status', 'active')
            ->orderBy('first_name_ar')->orderBy('family_name_ar')->get(['id', 'employee_number', 'first_name_ar', 'father_name_ar', 'family_name_ar', 'department_id']);
        $ids = $employees->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $slots = $this->calendar->resolve($ids, $from, $to);
        $entries = DB::table('attendance_roster_entries')->whereIn('employee_id', $ids ?: [0])->whereBetween('work_date', [$from, $to])->get()
            ->keyBy(fn ($e): string => $e->employee_id.'|'.substr((string) $e->work_date, 0, 10));
        $results = DB::table('attendance_daily_results')->whereIn('employee_id', $ids ?: [0])->whereBetween('work_date', [$from, min($to, $today)])->get(['employee_id', 'work_date', 'status'])
            ->keyBy(fn ($r): string => $r->employee_id.'|'.substr((string) $r->work_date, 0, 10));
        $dates = [];
        for ($d = $start; $d->toDateString() <= $to; $d = $d->addDay()) {
            $dates[] = ['date' => $d->toDateString(), 'day' => (int) $d->format('j'), 'weekday' => ['الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت', 'الأحد'][$d->dayOfWeekIso - 1],
                'weekend' => in_array($d->dayOfWeekIso, [5, 6], true), 'past' => $d->toDateString() < $today, 'today' => $d->toDateString() === $today];
        }
        $names = DB::table('departments')->whereIn('id', $scope)->pluck('name', 'id');

        return [
            'department' => ['id' => $departmentId, 'name' => $names[$departmentId] ?? ''], 'month' => $month, 'today' => $today, 'editable' => $editable, 'dates' => $dates,
            'shiftTypes' => $this->shiftTypes($departmentId),
            'rows' => $employees->map(fn ($e): array => ['id' => (int) $e->id, 'number' => $e->employee_number, 'name' => AttendanceLedger::fullName($e), 'unit' => $names[$e->department_id] ?? null,
                'cells' => collect($dates)->mapWithKeys(fn (array $d): array => [$d['date'] => $this->cell($slots[(int) $e->id][$d['date']] ?? null, $entries->get($e->id.'|'.$d['date']), $results->get($e->id.'|'.$d['date']))])->all(),
            ])->values()->all(),
        ];
    }

    /** Shift types usable in a department: its own, its parents', and the organization-wide ones. */
    public function shiftTypes(int $departmentId, bool $withInactive = false): array
    {
        $chain = $this->chain($departmentId);

        return DB::table('attendance_shift_types')->where(fn ($q) => $q->whereNull('department_id')->orWhereIn('department_id', $chain))
            ->when(! $withInactive, fn ($q) => $q->where('is_active', true))->orderBy('start_time')->orderBy('name')
            ->get(['id', 'department_id', 'name', 'start_time', 'end_time', 'ends_next_day', 'is_active'])
            ->map(fn ($t): array => ['id' => (int) $t->id, 'name' => $t->name, 'start' => substr($t->start_time, 0, 5), 'end' => substr($t->end_time, 0, 5),
                'next_day' => (bool) $t->ends_next_day, 'scope' => $t->department_id ? 'department' : 'organization', 'is_active' => (bool) $t->is_active])->all();
    }

    /**
     * Applies cell changes: [{employee_id, date, action: set|clear, kind: shift|off, shift_type_id?, start?, end?, note?}].
     *
     * @return array{changed: int, warnings: list<string>}
     */
    public function save(User $user, int $departmentId, array $changes): array
    {
        abort_unless(in_array($departmentId, $this->editableDepartments($user), true), 403, 'تعديل جدول المناوبات لرئيس القسم المعيّن فقط.');
        abort_if(count($changes) > self::MAX_CHANGES, 422, 'عدد التغييرات كبير؛ احفظ على دفعات.');
        $today = $this->today();
        $last = CarbonImmutable::parse($today)->addDays(self::MAX_DAYS_AHEAD)->toDateString();
        $allowed = DB::table('employees')->whereIn('department_id', $this->withUnits([$departmentId]))->whereNull('deleted_at')->where('employment_status', 'active')
            ->pluck('user_id', 'id');
        $types = collect($this->shiftTypes($departmentId))->keyBy('id');
        $errors = [];
        $clean = [];
        foreach (array_values($changes) as $i => $c) {
            $employeeId = (int) ($c['employee_id'] ?? 0);
            $date = (string) ($c['date'] ?? '');
            if (! $allowed->has($employeeId)) {
                $errors["changes.$i"] = 'الموظف ليس من موظفي هذا القسم النشطين.';

                continue;
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $today || $date > $last) {
                $errors["changes.$i"] = 'يُعدّل الجدول من اليوم حتى '.self::MAX_DAYS_AHEAD.' يومًا قادمًا؛ الأيام الماضية تُصحح باستثناء من الموارد البشرية.';

                continue;
            }
            $action = $c['action'] ?? 'set';
            if ($action === 'clear') {
                $clean[] = compact('employeeId', 'date') + ['action' => 'clear'];

                continue;
            }
            $note = isset($c['note']) ? mb_substr(trim((string) $c['note']), 0, 200) ?: null : null;
            if (($c['kind'] ?? '') === 'off') {
                $clean[] = compact('employeeId', 'date', 'note') + ['action' => 'set', 'kind' => 'off', 'type' => null, 'start' => null, 'end' => null, 'next' => false];

                continue;
            }
            if (filled($c['shift_type_id'] ?? null)) {
                $type = $types->get((int) $c['shift_type_id']);
                if (! $type) {
                    $errors["changes.$i"] = 'نوع المناوبة غير متاح لهذا القسم.';

                    continue;
                }
                $clean[] = compact('employeeId', 'date', 'note') + ['action' => 'set', 'kind' => 'shift', 'type' => $type['id'], 'start' => $type['start'], 'end' => $type['end'], 'next' => $type['next_day']];

                continue;
            }
            $start = (string) ($c['start'] ?? '');
            $end = (string) ($c['end'] ?? '');
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end) || $start === $end) {
                $errors["changes.$i"] = 'اكتب وقت بداية ونهاية صحيحين (مثل 08:00 و16:00).';

                continue;
            }
            // An end at or before the start is the next morning: at most 24 hours.
            $clean[] = compact('employeeId', 'date', 'note', 'start', 'end') + ['action' => 'set', 'kind' => 'shift', 'type' => null, 'next' => $end <= $start];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $touched = [];
        DB::transaction(function () use ($clean, $user, &$touched): void {
            foreach ($clean as $c) {
                $existing = DB::table('attendance_roster_entries')->where('employee_id', $c['employeeId'])->where('work_date', $c['date'])->lockForUpdate()->first();
                $before = $existing ? self::describe($existing) : null;
                if ($c['action'] === 'clear') {
                    if ($existing) {
                        DB::table('attendance_roster_entries')->where('id', $existing->id)->delete();
                        AttendanceAudit::record($user->id, 'roster_entry', (int) $existing->id, 'cleared', ['employee_id' => $c['employeeId'], 'date' => $c['date'], 'before' => $before]);
                        $touched[$c['employeeId']][] = $c['date'];
                    }

                    continue;
                }
                $row = ['kind' => $c['kind'], 'shift_type_id' => $c['type'], 'start_time' => $c['start'], 'end_time' => $c['end'], 'ends_next_day' => $c['next'], 'note' => $c['note'],
                    'updated_by' => $user->id, 'updated_at' => now()];
                if ($existing && self::describe((object) $row) === $before && $existing->note === $c['note']) {
                    continue;
                }
                $id = $existing ? (int) $existing->id : DB::table('attendance_roster_entries')->insertGetId(['employee_id' => $c['employeeId'], 'work_date' => $c['date'], ...$row,
                    'created_by' => $user->id, 'created_at' => now()]);
                if ($existing) {
                    DB::table('attendance_roster_entries')->where('id', $id)->update($row);
                }
                AttendanceAudit::record($user->id, 'roster_entry', $id, $existing ? 'changed' : 'planned', ['employee_id' => $c['employeeId'], 'date' => $c['date'],
                    'before' => $before, 'after' => self::describe((object) $row)]);
                $touched[$c['employeeId']][] = $c['date'];
            }
        });

        $today = $this->today();
        foreach ($touched as $employeeId => $dates) {
            if (in_array($today, $dates, true)) {
                $this->daily->refreshDays((int) $employeeId, $today, $today);
            }
            sort($dates);
            if ($userId = $allowed->get($employeeId)) {
                $this->notifications->notify((int) $userId, 'attendance_roster_changed', [
                    'title' => 'تحديث جدول مناوباتك',
                    'body' => 'عدّل رئيس القسم '.count($dates).' يومًا في جدولك، أولها '.$dates[0].'. راجع «دوامي الشهري».',
                    'url' => '/attendance/monthly?month='.substr($dates[0], 0, 7), 'dedupe' => 'attendance-roster:'.$employeeId.':'.md5(implode(',', $dates).now()->format('YmdHi')),
                ]);
            }
        }

        return ['changed' => array_sum(array_map('count', $touched)), 'warnings' => $this->restWarnings($touched)];
    }

    /** Creates or updates a shift type: the head for their department, the HR head for the whole organization. */
    public function saveShiftType(User $user, array $data, ?int $id = null): int
    {
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;
        $existing = $id ? DB::table('attendance_shift_types')->where('id', $id)->first() : null;
        abort_if($id && ! $existing, 404);
        $scopeDepartment = $existing ? ($existing->department_id ? (int) $existing->department_id : null) : $departmentId;
        $mayOrganization = $user->is_active && $user->isHrDepartmentHead();
        abort_unless($scopeDepartment === null ? $mayOrganization : in_array($scopeDepartment, $this->editableDepartments($user), true), 403,
            'أنواع المناوبات العامة لرئيس الموارد البشرية، وأنواع القسم لرئيسه.');
        $start = (string) $data['start'];
        $end = (string) $data['end'];
        if ($start === $end) {
            throw ValidationException::withMessages(['end' => 'وقت النهاية يساوي البداية.']);
        }
        $name = trim((string) $data['name']);
        $duplicate = DB::table('attendance_shift_types')->whereRaw('COALESCE(department_id, 0) = ?', [$scopeDepartment ?? 0])->where('name', $name)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'يوجد نوع مناوبة بهذا الاسم.']);
        }
        $row = ['name' => mb_substr($name, 0, 60), 'start_time' => $start, 'end_time' => $end, 'ends_next_day' => $end <= $start, 'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_by' => $user->id, 'updated_at' => now()];
        if ($existing) {
            DB::table('attendance_shift_types')->where('id', $id)->update($row);
        } else {
            $id = DB::table('attendance_shift_types')->insertGetId([...$row, 'department_id' => $scopeDepartment, 'created_by' => $user->id, 'created_at' => now()]);
        }
        AttendanceAudit::record($user->id, 'shift_type', (int) $id, $existing ? 'changed' : 'created', ['before' => $existing ? (array) $existing : null, 'after' => $row]);

        return (int) $id;
    }

    /** One cell: what the head planned, what applies, and (for past days) what happened. */
    private function cell(?array $slot, ?object $entry, ?object $result): array
    {
        $status = $slot['status'] ?? null;
        $applies = match (true) {
            $slot === null => 'لا دوام مقرر',
            $status === 'off' => 'راحة',
            in_array($status, ['work', 'no_punch'], true) && isset($slot['in'], $slot['out']) => $slot['in']->format('H:i').'–'.$slot['out']->format('H:i'),
            $status === 'no_duty' => 'لا دوام',
            default => 'استثناء',
        };

        return [
            'entry' => $entry ? ['kind' => $entry->kind, 'shift_type_id' => $entry->shift_type_id ? (int) $entry->shift_type_id : null, 'start' => $entry->start_time ? substr($entry->start_time, 0, 5) : null,
                'end' => $entry->end_time ? substr($entry->end_time, 0, 5) : null, 'note' => $entry->note] : null,
            'applies' => $applies, 'work' => in_array($status, ['work', 'no_punch'], true),
            'source' => isset($slot['tier']) ? (AttendanceCalendar::TIER_LABELS[$slot['tier']] ?? null) : null, 'from_roster' => ($slot['tier'] ?? null) === 'roster' || ($slot['beneath'] ?? null) === 'roster',
            'result' => $result && isset(self::RESULT_MARKS[$result->status]) ? ['mark' => self::RESULT_MARKS[$result->status][0], 'label' => self::RESULT_MARKS[$result->status][1], 'status' => $result->status] : null,
        ];
    }

    /** Rest between two consecutive planned working days shorter than the HR minimum (warning only). @return list<string> */
    private function restWarnings(array $touched): array
    {
        $minimum = (int) ($this->settings->get('swap_min_rest_hours') ?? 0);
        if ($minimum <= 0 || $touched === []) {
            return [];
        }
        $warnings = [];
        foreach ($touched as $employeeId => $dates) {
            sort($dates);
            $from = CarbonImmutable::parse($dates[0])->subDay()->toDateString();
            $to = CarbonImmutable::parse(end($dates))->addDay()->toDateString();
            $slots = array_values(array_filter($this->calendar->resolve([(int) $employeeId], $from, $to)[(int) $employeeId] ?? [], fn ($s) => isset($s['in'], $s['out'])));
            for ($i = 1; $i < count($slots); $i++) {
                $rest = ($slots[$i]['in']->getTimestamp() - $slots[$i - 1]['out']->getTimestamp()) / 3600;
                if ($rest < $minimum) {
                    $name = AttendanceLedger::fullName(DB::table('employees')->where('id', $employeeId)->first(['first_name_ar', 'father_name_ar', 'family_name_ar']));
                    $warnings[] = $name.': راحة '.max(0, round($rest, 1)).' ساعة فقط قبل مناوبة '.$slots[$i]['in']->format('Y-m-d H:i').' (الحد الأدنى في الإعدادات '.$minimum.').';
                }
            }
        }

        return array_slice($warnings, 0, 20);
    }

    private static function describe(object $row): string
    {
        return $row->kind === 'off' ? 'راحة' : substr((string) $row->start_time, 0, 5).'–'.substr((string) $row->end_time, 0, 5).($row->shift_type_id ? ' #'.$row->shift_type_id : '');
    }

    private function today(): string
    {
        return CarbonImmutable::now(config('app.timezone'))->toDateString();
    }

    /** @param list<int> $roots @return list<int> */
    private function withUnits(array $roots): array
    {
        $children = DB::table('departments')->whereNull('deleted_at')->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = $roots;
        for ($frontier = $roots; $frontier !== [];) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($children->get($id, collect()) as $child) {
                    if (! in_array((int) $child->id, $ids, true)) {
                        $ids[] = $next[] = (int) $child->id;
                    }
                }
            }
            $frontier = $next;
        }

        return $ids;
    }

    /** The department and its parents. @return list<int> */
    private function chain(int $departmentId): array
    {
        $parents = DB::table('departments')->pluck('parent_id', 'id');
        $chain = [];
        for ($node = $departmentId; $node && ! in_array($node, $chain, true) && count($chain) < 12; $node = (int) ($parents[$node] ?? 0)) {
            $chain[] = $node;
        }

        return $chain;
    }
}
