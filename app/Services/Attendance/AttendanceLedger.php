<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single read path for attendance days: the HR ledger, the employee file and the Excel export all use it,
 * so the three always show the same values.
 */
final class AttendanceLedger
{
    /** Filters accepted by rows(): a punctuality, a day state, or one of these extra groups. */
    public const FILTERS = ['on_time', 'grace', 'late', 'excused', 'flexible', 'unknown', 'working', 'awaiting_exit', 'complete', 'incomplete', 'absent',
        'leave', 'holiday', 'rest_day', 'review', 'not_started', 'no_duty', 'exempt', 'not_enrolled', 'early', 'corrected', 'permission', 'swap', 'hr_action'];

    public const HR_ACTION_STATUS = ['draft' => 'مسودة', 'in_review' => 'قيد المراجعة', 'issued' => 'صادر', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى'];

    private const DIRECTIONS = ['in' => 'دخول', 'out' => 'خروج', 'unknown' => 'غير معروف'];

    /** @var array<string, bool> table name => exists (memoized per process) */
    private static array $tables = [];

    public function __construct(private AttendanceDayView $view) {}

    public static function hasTable(string $table): bool
    {
        return self::$tables[$table] ??= Schema::hasTable($table);
    }

    /**
     * Presented days, newest first then by employee name. Pending permission and swap requests are annotated; the
     * administrative-action flag is added only for viewers entitled to the confidential register.
     *
     * @param  array{from: string, to: string, employee_ids?: ?list<int>, name?: ?string}  $scope
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $scope, bool $withDirections = false, bool $withHrActions = false): Collection
    {
        $employeeIds = $scope['employee_ids'] ?? null;
        if (! empty($scope['name'])) {
            $named = $this->employeeIdsByName((string) $scope['name']);
            $employeeIds = $employeeIds === null ? $named : array_values(array_intersect($employeeIds, $named));
        }
        if ($employeeIds === []) {
            return collect();
        }

        $records = DB::table('attendance_daily_results as r')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->whereNull('e.deleted_at')
            ->whereBetween('r.work_date', [$scope['from'], $scope['to']])
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('r.employee_id', $employeeIds))
            ->orderByDesc('r.work_date')->orderBy('e.first_name_ar')->orderBy('e.family_name_ar')->orderBy('r.employee_id')
            ->get(['r.id', 'r.employee_id', 'r.work_date', 'r.expected_in', 'r.expected_out', 'r.actual_in', 'r.actual_out',
                'r.late_minutes', 'r.early_minutes', 'r.status', 'r.grace_minutes', 'r.explanation',
                'e.employee_number', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar', 'e.department_id']);
        $listed = $records->pluck('employee_id')->unique()->values()->all();
        $corrections = $this->corrections($listed, $scope['from'], $scope['to'], $withDirections);
        $requests = $this->requests($listed, $scope['from'], $scope['to']);
        $actions = $withHrActions ? $this->hrActions($listed, $scope['from'], $scope['to']) : [];
        // Days stored before «غير مسجل على جهاز البصمة» existed: say so instead of a bare "absent".
        $punched = $listed ? DB::table('attendance_punches')->whereIn('employee_id', $listed)->distinct()->pluck('employee_id')->flip() : collect();
        $now = CarbonImmutable::now(config('app.timezone'));

        return $records->map(function (object $record) use ($corrections, $requests, $actions, $punched, $now): array {
            $day = $this->view->present($record, $now);
            if ($day['day_state'] === 'absent' && ! $punched->has($record->employee_id)) {
                $day['day_hint'] = 'لا توجد أي بصمة مسجلة لهذا الموظف؛ تحقق من تسجيله على جهاز البصمة.';
            }
            $key = $record->employee_id.'|'.$record->work_date;

            return ['id' => (int) $record->id, 'employee_id' => (int) $record->employee_id, 'employee_number' => $record->employee_number,
                'employee_name' => self::fullName($record), 'department_id' => $record->department_id ? (int) $record->department_id : null,
            ] + $day + ['corrections' => $corrections[$key] ?? [], 'requests' => $requests[$key] ?? [], 'hr_actions' => $actions[$key] ?? []];
        })->values();
    }

    /**
     * Pending and approved permission requests, and swap requests still in process, per "employee|day".
     *
     * @param  list<int>  $employeeIds
     * @return array<string, list<array{kind: string, id: int, status: string, label: string}>>
     */
    public function requests(array $employeeIds, string $from, string $to): array
    {
        $grouped = [];
        if ($employeeIds === []) {
            return $grouped;
        }
        $tz = config('app.timezone');
        if (self::hasTable('attendance_permission_requests')) {
            foreach (DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')
                ->whereIn('r.employee_id', $employeeIds)->whereBetween('r.work_date', [$from, $to])->whereIn('r.status', ['submitted', 'approved'])
                ->orderBy('r.starts_at')->get(['r.id', 'r.employee_id', 'r.work_date', 'r.status', 'r.starts_at', 'r.ends_at', 't.name_ar']) as $request) {
                $grouped[$request->employee_id.'|'.$request->work_date][] = ['kind' => 'permission', 'id' => (int) $request->id, 'status' => $request->status,
                    'label' => ($request->status === 'approved' ? '' : 'قيد الاعتماد: ').$request->name_ar.' '
                        .CarbonImmutable::parse($request->starts_at)->setTimezone($tz)->format('H:i').'–'.CarbonImmutable::parse($request->ends_at)->setTimezone($tz)->format('H:i')];
            }
        }
        if (self::hasTable('attendance_shift_swaps')) {
            $swaps = DB::table('attendance_shift_swaps')->whereIn('status', ['awaiting_counterpart', 'pending_approval'])
                ->where(fn ($query) => $query->whereIn('requester_employee_id', $employeeIds)->orWhereIn('counterpart_employee_id', $employeeIds))
                ->get(['id', 'requester_employee_id', 'counterpart_employee_id', 'status', 'days']);
            foreach ($swaps as $swap) {
                foreach (json_decode((string) $swap->days, true) ?: [] as $swapDay) {
                    if ($swapDay['date'] < $from || $swapDay['date'] > $to) {
                        continue;
                    }
                    foreach ([(int) $swap->requester_employee_id, (int) $swap->counterpart_employee_id] as $employeeId) {
                        $grouped[$employeeId.'|'.$swapDay['date']][] = ['kind' => 'swap', 'id' => (int) $swap->id, 'status' => $swap->status,
                            'label' => $swap->status === 'awaiting_counterpart' ? 'طلب تبديل مناوبة بانتظار موافقة الزميل' : 'طلب تبديل مناوبة قيد الاعتماد'];
                    }
                }
            }
        }

        return $grouped;
    }

    /**
     * Administrative actions under review or issued, per "employee|day" (the attendance day they came from, else the
     * incident date). Confidential: call only for viewers entitled to the register.
     *
     * With $withReturned, an action its reviewer sent back to its author is listed too, as «مُعاد للتعديل»: it is a
     * draft again in the register, but it is not a new draft and the day it came from still shows it (day detail only;
     * the ledger's rows, filter and export keep counting actions under review or issued).
     *
     * @param  list<int>  $employeeIds
     * @return array<string, list<array{id: int, status: string, label: string}>>
     */
    public function hrActions(array $employeeIds, string $from, string $to, bool $withReturned = false): array
    {
        if ($employeeIds === [] || ! self::hasTable('employee_hr_actions')) {
            return [];
        }
        $grouped = [];
        foreach (DB::table('employee_hr_actions as a')->join('master_data_values as t', 't.id', '=', 'a.type_value_id')
            ->whereIn('a.employee_id', $employeeIds)
            ->where(fn ($query) => $query->whereIn('a.status', ['in_review', 'issued'])
                ->when($withReturned, fn ($returned) => $returned->orWhere(fn ($draft) => $draft->where('a.status', 'draft')->whereNotNull('a.reviewed_at'))))
            ->whereRaw('COALESCE(a.attendance_date, a.incident_date) BETWEEN ? AND ?', [$from, $to])
            ->get(['a.id', 'a.employee_id', 'a.status', 'a.reviewed_at', 'a.reference_no', DB::raw('COALESCE(a.attendance_date, a.incident_date) as day'), 't.name_ar']) as $action) {
            $status = HrActionService::isReturned($action) ? HrActionService::RETURNED_LABEL : (self::HR_ACTION_STATUS[$action->status] ?? $action->status);
            $grouped[$action->employee_id.'|'.$action->day][] = ['id' => (int) $action->id, 'status' => $action->status,
                'label' => $action->name_ar.' — '.$status.($action->reference_no ? ' ('.$action->reference_no.')' : '')];
        }

        return $grouped;
    }

    /**
     * Keeps the rows that match one filter group.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function filter(Collection $rows, ?string $filter): Collection
    {
        if (! $filter) {
            return $rows;
        }

        return $rows->filter(fn (array $row): bool => match ($filter) {
            'on_time', 'grace', 'late', 'excused', 'flexible', 'unknown' => $row['punctuality'] === $filter,
            'early' => ($row['early_minutes'] ?? 0) > 0,
            'review' => $row['needs_review'],
            'corrected' => $row['corrections'] !== [],
            'permission' => ($row['permissions'] ?? []) !== [] || collect($row['requests'] ?? [])->contains('kind', 'permission'),
            'swap' => ($row['swap'] ?? false) || collect($row['requests'] ?? [])->contains('kind', 'swap'),
            'hr_action' => ($row['hr_actions'] ?? []) !== [],
            default => $row['day_state'] === $filter,
        })->values();
    }

    /**
     * Counts for the overview cards.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(Collection $rows): array
    {
        $count = fn (callable $test): int => $rows->filter($test)->count();

        return [
            'total' => $rows->count(),
            'on_time' => $count(fn (array $row): bool => $row['punctuality'] === 'on_time'),
            'grace' => $count(fn (array $row): bool => $row['punctuality'] === 'grace'),
            'late' => $count(fn (array $row): bool => $row['punctuality'] === 'late'),
            'working' => $count(fn (array $row): bool => in_array($row['day_state'], ['working', 'awaiting_exit'], true)),
            'complete' => $count(fn (array $row): bool => $row['day_state'] === 'complete'),
            'absent' => $count(fn (array $row): bool => $row['day_state'] === 'absent'),
            'incomplete' => $count(fn (array $row): bool => $row['day_state'] === 'incomplete'),
            'review' => $count(fn (array $row): bool => $row['needs_review']),
            'early' => $count(fn (array $row): bool => ($row['early_minutes'] ?? 0) > 0),
            'leave' => $count(fn (array $row): bool => in_array($row['day_state'], ['leave', 'holiday', 'rest_day'], true)),
            'excused' => $count(fn (array $row): bool => $row['punctuality'] === 'excused'),
            'no_duty' => $count(fn (array $row): bool => in_array($row['day_state'], ['no_duty', 'exempt'], true)),
            'not_enrolled' => $count(fn (array $row): bool => $row['day_state'] === 'not_enrolled'),
        ];
    }

    /**
     * Punches behind one day with original and effective directions and their corrections.
     *
     * @return array<string, mixed>
     */
    public function day(int $employeeId, string $date, bool $withAudit, bool $withHrActions = false): array
    {
        $key = $employeeId.'|'.$date;
        $tz = config('app.timezone');
        $record = DB::table('attendance_daily_results')->where('employee_id', $employeeId)->where('work_date', $date)->first();
        $explanation = $record ? (json_decode((string) $record->explanation, true) ?: []) : [];
        $window = $explanation['window'] ?? null;
        $start = $window ? CarbonImmutable::parse($window[0]) : CarbonImmutable::parse($date.' 00:00:00', $tz);
        $end = $window ? CarbonImmutable::parse($window[1]) : $start->addDay();
        $punches = DB::table('attendance_punches as p')
            ->leftJoin('attendance_punch_corrections as m', fn ($join) => $join->on('m.punch_id', '=', 'p.id')->where('m.status', '=', 'approved'))
            ->leftJoin('attendance_auto_corrections as a', fn ($join) => $join->on('a.punch_id', '=', 'p.id')->where('a.status', '=', 'applied'))
            ->where('p.employee_id', $employeeId)
            ->where('p.occurred_at', '>=', $start->format('Y-m-d H:i:sP'))->where('p.occurred_at', '<', $end->format('Y-m-d H:i:sP'))
            ->orderBy('p.occurred_at')->orderBy('p.id')
            ->get(['p.id', 'p.direction', 'p.occurred_at', 'p.source', 'p.match_status', 'p.raw_payload', 'p.reason',
                DB::raw('COALESCE(m.corrected_direction, a.corrected_direction, p.direction) as effective_direction')]);
        $ids = $punches->pluck('id')->all();
        $auto = DB::table('attendance_auto_corrections as c')->leftJoin('users as u', 'u.id', '=', 'c.reverted_by')
            ->whereIn('c.punch_id', $ids)->orderBy('c.id')
            ->get(['c.id', 'c.punch_id', 'c.original_direction', 'c.corrected_direction', 'c.status', 'c.rule_code', 'c.rule_version',
                'c.reason_ar', 'c.evidence', 'c.applied_at', 'c.reverted_at', 'c.reversion_reason', 'u.name as reverted_by_name'])->groupBy('punch_id');
        $manual = DB::table('attendance_punch_corrections as c')->leftJoin('users as r', 'r.id', '=', 'c.requested_by')->leftJoin('users as v', 'v.id', '=', 'c.reviewed_by')
            ->whereIn('c.punch_id', $ids)->orderBy('c.id')
            ->get(['c.id', 'c.punch_id', 'c.original_direction', 'c.corrected_direction', 'c.status', 'c.reason', 'c.review_note', 'c.created_at', 'c.reviewed_at',
                'c.revocation_reason', 'r.name as requested_by_name', 'v.name as reviewed_by_name'])->groupBy('punch_id');
        $local = fn (mixed $value): ?string => $value ? CarbonImmutable::parse($value)->setTimezone($tz)->format('Y-m-d H:i') : null;
        $entryId = $explanation['potential_entry']['punch_id'] ?? null;
        $exitId = $explanation['potential_exit']['punch_id'] ?? null;

        return [
            'employee_id' => $employeeId,
            'work_date' => $date,
            'day' => $record ? $this->view->present($record) : null,
            'requests' => $this->requests([$employeeId], $date, $date)[$key] ?? [],
            'hr_actions' => $withHrActions ? ($this->hrActions([$employeeId], $date, $date, true)[$key] ?? []) : [],
            'punches' => $punches->map(function (object $punch) use ($auto, $manual, $local, $tz, $date, $entryId, $exitId): array {
                $raw = json_decode((string) $punch->raw_payload, true) ?: [];
                $time = CarbonImmutable::parse($punch->occurred_at)->setTimezone($tz);

                return [
                    'id' => (int) $punch->id,
                    'time' => $time->format('H:i:s').($time->toDateString() !== $date ? ' ('.$time->format('m-d').')' : ''),
                    'original_direction' => $punch->direction, 'original_label' => self::DIRECTIONS[$punch->direction] ?? $punch->direction,
                    'effective_direction' => $punch->effective_direction, 'effective_label' => self::DIRECTIONS[$punch->effective_direction] ?? $punch->effective_direction,
                    'corrected' => $punch->effective_direction !== $punch->direction,
                    'source' => $punch->source === 'manual' ? 'إدخال يدوي' : 'جهاز البصمة',
                    'device_code' => isset($raw['punch']) ? (string) $raw['punch'] : null,
                    'match_status' => $punch->match_status,
                    'counted' => $punch->raw_payload === null || in_array($punch->match_status, ['matched', 'reviewed'], true),
                    'flag' => (int) $punch->id === $entryId ? 'دخول محتمل' : ((int) $punch->id === $exitId ? 'خروج محتمل' : null),
                    'reason' => $punch->reason,
                    'auto' => ($auto[$punch->id] ?? collect())->map(fn (object $c): array => [
                        'id' => (int) $c->id, 'status' => $c->status, 'original' => self::DIRECTIONS[$c->original_direction] ?? $c->original_direction,
                        'corrected' => self::DIRECTIONS[$c->corrected_direction] ?? $c->corrected_direction, 'rule' => $c->rule_code.' v'.$c->rule_version,
                        'note' => $c->reason_ar, 'evidence' => json_decode((string) $c->evidence, true), 'applied_at' => $local($c->applied_at),
                        'reverted_at' => $local($c->reverted_at), 'reversion_reason' => $c->reversion_reason, 'reverted_by' => $c->reverted_by_name,
                    ])->values()->all(),
                    'manual' => ($manual[$punch->id] ?? collect())->map(fn (object $c): array => [
                        'id' => (int) $c->id, 'status' => $c->status, 'original' => self::DIRECTIONS[$c->original_direction] ?? $c->original_direction,
                        'corrected' => self::DIRECTIONS[$c->corrected_direction] ?? $c->corrected_direction, 'reason' => $c->reason,
                        'review_note' => $c->review_note, 'requested_by' => $c->requested_by_name, 'requested_at' => $local($c->created_at),
                        'reviewed_by' => $c->reviewed_by_name, 'reviewed_at' => $local($c->reviewed_at), 'revocation_reason' => $c->revocation_reason,
                    ])->values()->all(),
                ];
            })->values()->all(),
            'audit' => $withAudit && $ids ? DB::table('attendance_audit_events as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
                ->where('a.entity_type', 'punch')->whereIn('a.entity_id', $ids)->orderBy('a.id')
                ->get(['a.id', 'a.entity_id', 'a.action', 'a.details', 'a.created_at', 'u.name as actor'])
                ->map(fn (object $event): array => ['id' => (int) $event->id, 'punch_id' => (int) $event->entity_id, 'action' => $event->action,
                    'details' => json_decode((string) $event->details, true), 'at' => $local($event->created_at), 'actor' => $event->actor])->all() : [],
        ];
    }

    /** Arabic name normalisation: no diacritics or tatweel, one alef, ya and ha forms. */
    public static function normalize(string $value): string
    {
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($value)) ?? $value);
    }

    public static function fullName(object $employee): string
    {
        return trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter([$employee->first_name_ar ?? null, $employee->father_name_ar ?? null, $employee->family_name_ar ?? null]))) ?? '');
    }

    /**
     * Employees whose name contains every searched word (or whose number equals the search).
     *
     * @return list<int>
     */
    public function employeeIdsByName(string $search): array
    {
        $words = array_values(array_filter(explode(' ', self::normalize($search))));
        if ($words === []) {
            return [];
        }

        return DB::table('employees')->whereNull('deleted_at')
            ->get(['id', 'employee_number', 'first_name_ar', 'father_name_ar', 'grandfather_name_ar', 'family_name_ar'])
            ->filter(function (object $employee) use ($words, $search): bool {
                if (trim($search) === (string) $employee->employee_number) {
                    return true;
                }
                $name = self::normalize(implode(' ', array_filter([$employee->first_name_ar, $employee->father_name_ar, $employee->grandfather_name_ar, $employee->family_name_ar])));

                return collect($words)->every(fn (string $word): bool => str_contains($name, $word));
            })->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Automatic and manual direction corrections per "employee|local day".
     *
     * @param  list<int>  $employeeIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function corrections(array $employeeIds, string $from, string $to, bool $withDirections): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $tz = config('app.timezone');
        $start = CarbonImmutable::parse($from.' 00:00:00', $tz)->subDay()->format('Y-m-d H:i:sP');
        $end = CarbonImmutable::parse($to.' 00:00:00', $tz)->addDays(2)->format('Y-m-d H:i:sP');
        $auto = DB::table('attendance_auto_corrections as c')->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')
            ->whereIn('p.employee_id', $employeeIds)->where('p.occurred_at', '>=', $start)->where('p.occurred_at', '<', $end)
            ->orderBy('c.id')->get(['c.id', 'p.employee_id', 'p.occurred_at', 'c.original_direction', 'c.corrected_direction', 'c.status', 'c.reason_ar', 'c.reversion_reason'])
            ->map(fn (object $c): array => ['kind' => 'auto', 'id' => (int) $c->id, 'employee_id' => (int) $c->employee_id, 'occurred_at' => $c->occurred_at,
                'status' => $c->status, 'original_direction' => $c->original_direction, 'corrected_direction' => $c->corrected_direction,
                'note' => $c->reason_ar, 'reversion_reason' => $c->reversion_reason]);
        $manual = DB::table('attendance_punch_corrections as c')->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')
            ->whereIn('p.employee_id', $employeeIds)->whereIn('c.status', ['pending', 'approved'])
            ->where('p.occurred_at', '>=', $start)->where('p.occurred_at', '<', $end)
            ->orderBy('c.id')->get(['c.id', 'p.employee_id', 'p.occurred_at', 'c.original_direction', 'c.corrected_direction', 'c.status', 'c.reason'])
            ->map(fn (object $c): array => ['kind' => 'manual', 'id' => (int) $c->id, 'employee_id' => (int) $c->employee_id, 'occurred_at' => $c->occurred_at,
                'status' => $c->status === 'approved' ? 'applied' : 'pending', 'original_direction' => $c->original_direction, 'corrected_direction' => $c->corrected_direction,
                'note' => ($c->status === 'approved' ? 'تصحيح يدوي معتمد: ' : 'طلب تصحيح يدوي بانتظار الاعتماد: ').$c->reason, 'reversion_reason' => null]);

        $grouped = [];
        foreach ($auto->concat($manual) as $correction) {
            $time = CarbonImmutable::parse($correction['occurred_at'])->setTimezone($tz);
            $day = $time->toDateString();
            if ($day < $from || $day > $to) {
                continue;
            }
            $grouped[$correction['employee_id'].'|'.$day][] = [
                'kind' => $correction['kind'], 'id' => $correction['id'], 'status' => $correction['status'], 'at' => $time->format('H:i'),
                'note' => $correction['note'], 'reversion_reason' => $correction['reversion_reason'],
                'directions' => $withDirections ? (self::DIRECTIONS[$correction['original_direction']] ?? $correction['original_direction']).' ← '
                    .(self::DIRECTIONS[$correction['corrected_direction']] ?? $correction['corrected_direction']) : null,
            ];
        }

        return $grouped;
    }
}
