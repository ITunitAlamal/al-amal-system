<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The canonical roster: who is expected to work, when, and under which rules. The daily calculation, the HR
 * roster, the employee file and the exports all read it; nothing else decides a shift.
 *
 * Precedence for one employee and day (deterministic):
 *  1. an approved exception for this employee (hours, rest day, exemption, emergency change),
 *  2. an approved shift swap (stored as employee exceptions linked to the swap),
 *  2b. the department head's monthly roster for this employee and day («جدول المناوبات»): it replaces the general
 *     hours (tiers 3–5); department and organization exemptions («لا دوام» / «معفى من البصمة») still apply over it,
 *     their hours/rest-day exceptions do not (the head planned that day knowingly),
 *  3. an approved exception of the employee's department (nearest department first), then an organization-wide one,
 *  4. the employee's own assignment, then the nearest department's, then an organization-wide assignment,
 *  5. the default template,
 *  6. nothing: «لا يوجد دوام مقرر» (null) — never an automatic absence.
 *
 * Within a tier the latest approval wins (assignments: the latest start date). An exemption («لا يوجد دوام مقرر» or
 * «معفى من البصمة») applies to the roster beneath it, and only on a work day: a rest day stays a rest day.
 *
 * Slots are anchored to the shift, not to calendar midnight: a 20:00–08:00 shift is one slot dated by its start.
 * Wall times are read in the shift's time zone; durations always come from instants, so a daylight-saving change
 * inside a shift is counted in real minutes and flagged.
 */
final class AttendanceCalendar
{
    public const DEFAULT_WINDOW_MINUTES = 240;

    public const TIER_LABELS = [
        'override' => 'استثناء معتمد للموظف', 'swap' => 'تبديل مناوبة معتمد', 'department_override' => 'استثناء معتمد للقسم',
        'organization_override' => 'استثناء عام معتمد', 'employee' => 'تكليف فردي', 'department' => 'تكليف القسم',
        'organization' => 'تكليف عام', 'default' => 'الدوام الافتراضي', 'exemption' => 'إعفاء معتمد', 'roster' => 'جدول المناوبات الشهري',
    ];

    public const EFFECT_LABELS = ['schedule' => 'أوقات دوام خاصة', 'off' => 'يوم راحة', 'no_duty' => 'لا يوجد دوام مقرر', 'no_punch' => 'معفى من البصمة'];

    public const KIND_LABELS = ['fixed' => 'دوام ثابت', 'flexible' => 'دوام مرن', 'rotation' => 'مناوبات دورية'];

    /** Whether the exception columns of 2026_10_29_120423 exist (memoized per process). */
    private static ?bool $extended = null;

    /** Whether the monthly roster table of 2026_11_30_000019 exists (memoized per process). */
    private static ?bool $monthly = null;

    /**
     * One employee and day; null when no roster applies («لا يوجد دوام مقرر»).
     *
     * @return array<string, mixed>|null
     */
    public function expected(int $employeeId, string $date): ?array
    {
        return $this->resolve([$employeeId], $date, $date)[$employeeId][$date] ?? null;
    }

    /**
     * Slots for many employees and days in a handful of queries.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, array<string, mixed>|null>> employee id => (Y-m-d => slot or null)
     */
    public function resolve(array $employeeIds, string $from, string $to): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        $dates = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        $result = array_fill_keys($employeeIds, array_fill_keys($dates, null));
        if ($employeeIds === [] || $dates === []) {
            return $result;
        }

        $employees = DB::table('employees')->whereIn('id', $employeeIds)->whereNull('deleted_at')->get(['id', 'department_id'])->keyBy('id');
        $chains = $this->departmentChains();
        $departmentIds = $employees->pluck('department_id')->filter()->flatMap(fn ($id): array => $chains[(int) $id] ?? [(int) $id])->unique()->values()->all();
        $inScope = function ($query) use ($employeeIds, $departmentIds): void {
            $query->whereIn('employee_id', $employeeIds)->orWhere(function ($general) use ($departmentIds): void {
                $general->whereNull('employee_id')->where(fn ($department) => $department->whereNull('department_id')->orWhereIn('department_id', $departmentIds ?: [0]));
            });
        };

        $extended = self::$extended ??= Schema::hasColumn('attendance_overrides', 'effect');
        $overrides = DB::table('attendance_overrides as o')
            ->when($extended, fn ($query) => $query->leftJoin('master_data_values as reason', 'reason.id', '=', 'o.reason_value_id')->whereNull('o.revoked_at'))
            ->where('o.status', 'approved')->where('o.starts_on', '<=', $to)->where('o.ends_on', '>=', $from)
            ->where($inScope) // employee_id and department_id exist only on the exceptions table
            ->get(['o.*', ...($extended ? ['reason.name_ar as reason_label', 'reason.code as reason_code'] : [])]);
        $assignments = DB::table('attendance_assignments')->where('status', 'approved')->where('starts_on', '<=', $to)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $from))->where($inScope)->get();
        $schedules = DB::table('attendance_schedules')->orderBy('id')->get()->keyBy('id');
        $days = DB::table('attendance_schedule_days')->get()->groupBy('schedule_id')
            ->map(fn (Collection $rows): Collection => $rows->keyBy(fn (object $row): int => (int) $row->weekday));
        $default = $schedules->first(fn (object $schedule): bool => (bool) $schedule->is_default && (bool) $schedule->is_active);
        $entries = (self::$monthly ??= Schema::hasTable('attendance_roster_entries'))
            ? DB::table('attendance_roster_entries as r')->leftJoin('attendance_shift_types as s', 's.id', '=', 'r.shift_type_id')
                ->whereIn('r.employee_id', $employeeIds)->whereBetween('r.work_date', [$from, $to])
                ->get(['r.id', 'r.employee_id', 'r.work_date', 'r.kind', 'r.start_time', 'r.end_time', 'r.ends_next_day', 'r.note', 's.name as shift_name'])
                ->keyBy(fn (object $row): string => $row->employee_id.'|'.substr((string) $row->work_date, 0, 10))
            : collect();

        foreach ($employees as $employee) {
            $chain = $employee->department_id ? ($chains[(int) $employee->department_id] ?? [(int) $employee->department_id]) : [];
            $ownOverrides = $overrides->filter(fn (object $row): bool => $this->rank($row, (int) $employee->id, $chain) !== null)
                ->sort(fn (object $a, object $b): int => [$this->rank($a, (int) $employee->id, $chain), -$a->id] <=> [$this->rank($b, (int) $employee->id, $chain), -$b->id]);
            $ownAssignments = $assignments->filter(fn (object $row): bool => $this->rank($row, (int) $employee->id, $chain) !== null)
                ->sort(fn (object $a, object $b): int => [$this->rank($a, (int) $employee->id, $chain), $b->starts_on, -$a->id] <=> [$this->rank($b, (int) $employee->id, $chain), $a->starts_on, -$b->id]);
            foreach ($dates as $date) {
                $result[(int) $employee->id][$date] = $this->day($date, $ownOverrides, $ownAssignments, $schedules, $days, $default, $entries->get($employee->id.'|'.$date));
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, object>  $overrides  sorted by precedence
     * @param  Collection<int, object>  $assignments  sorted by precedence
     * @return array<string, mixed>|null
     */
    private function day(string $date, Collection $overrides, Collection $assignments, Collection $schedules, Collection $days, ?object $default, ?object $entry = null): ?array
    {
        $roster = null;
        foreach ($assignments as $assignment) {
            if ($assignment->starts_on > $date || ($assignment->ends_on !== null && $assignment->ends_on < $date)) {
                continue;
            }
            $schedule = $schedules->get($assignment->schedule_id);
            if ($schedule && $this->usable($schedule, $date)) {
                $roster = $this->scheduleSlot($schedule, $date, $days, $assignment->employee_id ? 'employee' : ($assignment->department_id ? 'department' : 'organization'), (int) $assignment->id);
                break;
            }
        }
        if (! $roster && $default && $this->usable($default, $date)) {
            $roster = $this->scheduleSlot($default, $date, $days, 'default', null);
        }
        if ($entry) {
            // The head's plan for this day replaces the general hours; the general rules (grace, windows) still apply.
            $roster = $this->rosterSlot($entry, $date, $roster);
            $overrides = $overrides->filter(fn (object $row): bool => $row->employee_id !== null || in_array($row->effect ?? 'schedule', ['no_duty', 'no_punch'], true));
        }

        $applicable = $overrides->filter(fn (object $row): bool => $row->starts_on <= $date && $row->ends_on >= $date)->values();
        $first = $applicable->first();
        if (! $first) {
            return $roster;
        }
        $isExemption = fn (object $row): bool => in_array($row->effect ?? 'schedule', ['no_duty', 'no_punch'], true);
        if (! $isExemption($first)) {
            return $this->overrideSlot($first, $date, $roster);
        }
        $beneath = $applicable->first(fn (object $row): bool => ! $isExemption($row));
        $base = $beneath ? $this->overrideSlot($beneath, $date, $roster) : $roster;
        $exemption = $this->overrideMeta($first);
        if ($base === null) {
            // No roster at all: a «no duty» exemption still states it; a punch exemption has nothing to exempt.
            return $first->effect === 'no_duty' ? ['status' => 'no_duty', 'source' => 'override', 'id' => (int) $first->id, 'tier' => 'exemption',
                'schedule' => null, 'override' => $exemption, 'assignment_id' => null] : null;
        }
        if ($base['status'] !== 'work') {
            return $base;
        }

        return ['status' => $first->effect, 'source' => 'override', 'id' => (int) $first->id, 'tier' => 'exemption', 'override' => $exemption, 'beneath' => $base['tier']] + $base;
    }

    /**
     * @param  array<string, mixed>|null  $roster  the roster beneath, for its rules (grace, windows)
     * @return array<string, mixed>
     */
    private function overrideSlot(object $override, string $date, ?array $roster): array
    {
        $tier = $override->employee_id ? (($override->swap_id ?? null) ? 'swap' : 'override') : ($override->department_id ? 'department_override' : 'organization_override');
        $meta = ['source' => 'override', 'id' => (int) $override->id, 'tier' => $tier, 'override' => $this->overrideMeta($override),
            'schedule' => $roster['schedule'] ?? null, 'assignment_id' => $roster['assignment_id'] ?? null];
        if (($override->effect ?? 'schedule') === 'off') {
            return ['status' => 'off'] + $meta;
        }
        if (! $override->start_time || ! $override->end_time) {
            return ['status' => 'exception'] + $meta;
        }

        return $this->slot($date, (string) $override->start_time, (string) $override->end_time, (bool) $override->ends_next_day, $roster['schedule']['timezone'] ?? null) + $meta;
    }

    /**
     * A monthly roster entry, with the rules of the schedule it replaces (or neutral defaults when none applied).
     *
     * @param  array<string, mixed>|null  $beneath
     * @return array<string, mixed>
     */
    private function rosterSlot(object $entry, string $date, ?array $beneath): array
    {
        $settings = ($beneath['schedule'] ?? null) ?: ['id' => null, 'name' => null, 'kind' => 'fixed', 'grace_minutes' => null, 'break_minutes' => 0, 'break_paid' => true,
            'required_minutes' => null, 'required_punches' => 2, 'window_before' => self::DEFAULT_WINDOW_MINUTES, 'window_after' => self::DEFAULT_WINDOW_MINUTES,
            'location' => null, 'timezone' => null];
        $settings['name'] = $entry->kind === 'off' ? 'راحة (جدول المناوبات)' : ($entry->shift_name ?: 'مناوبة خاصة');
        // A flexible schedule beneath has no fixed hours; a planned shift is fixed.
        $settings['kind'] = 'fixed';
        $settings['required_minutes'] = null;
        $meta = ['source' => 'roster', 'id' => (int) $entry->id, 'tier' => 'roster', 'schedule' => $settings, 'override' => null, 'assignment_id' => $beneath['assignment_id'] ?? null,
            'roster_entry' => ['id' => (int) $entry->id, 'label' => $settings['name'], 'note' => $entry->note]];
        if ($entry->kind === 'off') {
            return ['status' => 'off'] + $meta;
        }

        return $this->slot($date, (string) $entry->start_time, (string) $entry->end_time, (bool) $entry->ends_next_day, $settings['timezone']) + $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private function overrideMeta(object $override): array
    {
        return ['id' => (int) $override->id, 'kind' => (string) $override->kind, 'label' => (string) $override->label, 'effect' => (string) ($override->effect ?? 'schedule'),
            'reason_label' => $override->reason_label ?? null, 'reason_code' => $override->reason_code ?? null, 'swap_id' => isset($override->swap_id) ? (int) $override->swap_id : null,
            'location' => $override->location ?? null, 'starts_on' => (string) $override->starts_on, 'ends_on' => (string) $override->ends_on];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleSlot(object $schedule, string $date, Collection $days, string $tier, ?int $assignmentId): array
    {
        $kind = (string) ($schedule->kind ?? 'fixed');
        $key = CarbonImmutable::parse($date)->dayOfWeekIso;
        if ($kind === 'rotation' && ($schedule->cycle_days ?? null) && ($schedule->cycle_anchor ?? null)) {
            $length = (int) $schedule->cycle_days;
            $offset = (int) floor((CarbonImmutable::parse($date)->getTimestamp() - CarbonImmutable::parse((string) $schedule->cycle_anchor)->getTimestamp()) / 86400 + 0.5);
            $key = (($offset % $length) + $length) % $length + 1;
        }
        $settings = [
            'id' => (int) $schedule->id, 'name' => (string) $schedule->name, 'kind' => $kind,
            'grace_minutes' => isset($schedule->grace_minutes) ? (int) $schedule->grace_minutes : null,
            'break_minutes' => (int) ($schedule->break_minutes ?? 0), 'break_paid' => (bool) ($schedule->break_paid ?? true),
            'required_minutes' => isset($schedule->required_minutes) ? (int) $schedule->required_minutes : null,
            'required_punches' => (int) ($schedule->required_punches ?? 2),
            'window_before' => (int) ($schedule->window_before_minutes ?? self::DEFAULT_WINDOW_MINUTES),
            'window_after' => (int) ($schedule->window_after_minutes ?? self::DEFAULT_WINDOW_MINUTES),
            'location' => $schedule->location ?? null, 'timezone' => $schedule->timezone ?? null,
        ];
        $meta = ['source' => 'schedule', 'id' => (int) $schedule->id, 'tier' => $tier, 'schedule' => $settings, 'override' => null, 'assignment_id' => $assignmentId];
        $day = $days->get($schedule->id)?->get($key);

        return $day ? $this->slot($date, (string) $day->start_time, (string) $day->end_time, (bool) $day->ends_next_day, $settings['timezone']) + $meta : ['status' => 'off'] + $meta;
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(string $date, string $start, string $end, bool $nextDay, ?string $timezone): array
    {
        $tz = $timezone ?: config('app.timezone', 'Asia/Gaza');
        $startWall = substr($start, 0, 8);
        $endWall = substr($end, 0, 8);
        $in = CarbonImmutable::parse($date.' '.$startWall, $tz);
        $out = CarbonImmutable::parse(CarbonImmutable::parse($date)->addDays($nextDay ? 1 : 0)->toDateString().' '.$endWall, $tz);
        if ($out->lessThanOrEqualTo($in)) {
            return ['status' => 'invalid'];
        }
        $dst = null;
        if ($in->format('H:i:s') !== str_pad($startWall, 8, ':00') || $out->format('H:i:s') !== str_pad($endWall, 8, ':00')) {
            $dst = 'nonexistent_local_time';
        } elseif ($in->getOffset() !== $out->getOffset()) {
            $dst = 'clock_change_inside_shift';
        }

        return ['status' => 'work', 'in' => $in, 'out' => $out, 'dst' => $dst];
    }

    private function usable(object $schedule, string $date): bool
    {
        return (bool) $schedule->is_active
            && (($schedule->effective_from ?? null) === null || (string) $schedule->effective_from <= $date)
            && (($schedule->effective_to ?? null) === null || (string) $schedule->effective_to >= $date);
    }

    /**
     * Precedence of an exception or assignment for this employee; null when it does not apply.
     *
     * @param  list<int>  $chain  the employee's department and its parents, nearest first
     */
    private function rank(object $row, int $employeeId, array $chain): ?int
    {
        if ($row->employee_id !== null) {
            return (int) $row->employee_id === $employeeId ? (($row->swap_id ?? null) ? 1 : 0) : null;
        }
        if ($row->department_id !== null) {
            $depth = array_search((int) $row->department_id, $chain, true);

            return $depth === false ? null : 10 + $depth;
        }

        return 100;
    }

    /**
     * Each department with its parents, nearest first (a unit inherits its department's roster).
     *
     * @return array<int, list<int>>
     */
    private function departmentChains(): array
    {
        $parents = DB::table('departments')->pluck('parent_id', 'id')->map(fn ($id): ?int => $id === null ? null : (int) $id)->all();
        $chains = [];
        foreach (array_keys($parents) as $id) {
            $chain = [];
            for ($node = (int) $id; $node !== null && ! in_array($node, $chain, true) && count($chain) < 12; $node = $parents[$node] ?? null) {
                $chain[] = $node;
            }
            $chains[(int) $id] = $chain;
        }

        return $chains;
    }
}
