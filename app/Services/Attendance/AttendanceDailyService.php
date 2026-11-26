<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds one stored result per employee and work day from the effective punch directions
 * (approved manual correction, then applied automatic correction, then the device original).
 *
 * Stored facts: scheduled bounds, first entry, last exit, chargeable late minutes (beyond grace), early minutes.
 * Arrival lateness is stored as soon as a verified entry exists, with or without an exit.
 * Time-dependent display states (working / awaiting exit / absent so far) are derived by AttendanceDayView.
 *
 * Status codes kept for KPI and dashboards: present, late, early, incomplete_punches, missing_punches, off, ...
 * Added: in_progress (entry without exit while the shift window is open), not_started (no punch before the shift),
 * no_duty («لا يوجد دوام مقرر» by an approved exemption), exempt_punch («معفى من البصمة»: works without punching),
 * not_enrolled (no punch and not registered on any device: not counted as absent).
 *
 * The shift comes from AttendanceCalendar (the canonical roster) with its own grace, windows and kind. Approved
 * permissions explain part of the lateness or early exit; the stored late_minutes are the chargeable minutes after
 * the permission and the grace, while the explanation keeps the gross and excused minutes. Raw punches never change.
 */
final class AttendanceDailyService
{
    /** Punches this many hours around the scheduled shift belong to it; the day closes after the same margin. */
    public const WINDOW_HOURS = 4;

    /** A wrong-button punch this close to the shift start (or end) is flagged as a possible entry (or exit). */
    public const SUSPECT_MINUTES = 75;

    /** Whether the permission register exists yet (memoized per process). */
    private static ?bool $permissionTable = null;

    public function __construct(
        private AttendanceCalendar $calendar,
        private AttendancePolicy $policy,
        private AttendanceAbsenceResolver $absences,
    ) {}

    public function recalculateAroundPunch(int $employeeId, CarbonImmutable $occurredAt): void
    {
        $local = $occurredAt->setTimezone(config('app.timezone'));
        $this->recalculate($employeeId, $local->subDay()->toDateString());
        $this->recalculate($employeeId, $local->toDateString());
    }

    public function refreshExisting(?int $employeeId = null, ?string $from = null, ?string $until = null): void
    {
        if (! Schema::hasTable('attendance_daily_results')) {
            return;
        }
        DB::table('attendance_daily_results')
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($from !== null, fn ($query) => $query->where('work_date', '>=', $from))
            ->when($until !== null, fn ($query) => $query->where('work_date', '<=', $until))
            ->select(['id', 'employee_id', 'work_date'])->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $this->recalculate((int) $row->employee_id, (string) $row->work_date);
                }
            });
    }

    /**
     * Calculates and stores the day. Update first (no sequence value used), insert only when the day is new;
     * a concurrent insert of the same day is ignored by the unique key and then updated.
     *
     * @return array{employee_id: int, work_date: string, status: string, late_minutes: ?int, early_minutes: ?int}
     */
    public function recalculate(int $employeeId, string $date): array
    {
        $row = $this->calculate($employeeId, $date) + ['updated_at' => now()];
        $day = DB::table('attendance_daily_results')->where('employee_id', $employeeId)->where('work_date', $date);
        if ((clone $day)->update($row) === 0
            && DB::table('attendance_daily_results')->insertOrIgnore(['employee_id' => $employeeId, 'work_date' => $date] + $row + ['created_at' => now()]) === 0) {
            $day->update($row);
        }

        return ['employee_id' => $employeeId, 'work_date' => $date, 'status' => $row['status'], 'late_minutes' => $row['late_minutes'], 'early_minutes' => $row['early_minutes']];
    }

    /**
     * Recalculates the stored days of one employee from $from to $to, never beyond today (future days are calculated
     * when they arrive). Used after an approval that changes this employee's roster or permissions.
     *
     * @return int days recalculated
     */
    public function refreshDays(int $employeeId, string $from, string $to): int
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $count = 0;
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= min($to, $today) && $count < 400; $day = $day->addDay()) {
            $this->recalculate($employeeId, $day->toDateString());
            $count++;
        }

        return $count;
    }

    /**
     * Pure calculation without writing (used by recalculation and by dry-run previews).
     *
     * @return array{expected_in: ?string, expected_out: ?string, actual_in: ?string, actual_out: ?string, late_minutes: ?int, early_minutes: ?int, status: string, grace_minutes: ?int, explanation: string}
     */
    public function calculate(int $employeeId, string $date, ?CarbonImmutable $now = null): array
    {
        $tz = config('app.timezone');
        $now ??= CarbonImmutable::now($tz);
        $day = CarbonImmutable::parse($date);
        $slots = $this->calendar->resolve([$employeeId], $day->subDay()->toDateString(), $day->addDay()->toDateString())[$employeeId] ?? [];
        $slot = $slots[$date] ?? null;
        $absence = $this->absences->resolve($employeeId, $date);
        $settings = $slot['schedule'] ?? null;
        $grace = $settings['grace_minutes'] ?? $this->policy->graceMinutes();
        $work = ($slot['status'] ?? null) === 'work';
        $flexible = ($settings['kind'] ?? 'fixed') === 'flexible';
        $needsExit = (int) ($settings['required_punches'] ?? 2) === 2;
        [$start, $end] = $this->window($date, $slot, $slots[$day->subDay()->toDateString()] ?? null, $slots[$day->addDay()->toDateString()] ?? null);
        $permissions = $this->approvedPermissions($employeeId, $date);

        $punches = $this->effectivePunches($employeeId, $start, $end);
        $ins = $punches->where('direction', 'in')->values();
        $outs = $punches->where('direction', 'out')->values();
        $in = $ins->first();
        $out = $outs->last();
        $potentialEntry = null;
        $potentialExit = null;

        if ($work && ! $in) {
            $first = $punches->first(fn (object $punch): bool => in_array($punch->direction, ['in', 'out'], true));
            if ($first && $first->direction === 'out' && $this->isNear($first->at, $slot['in'])
                && $first->at->lessThan($slot['in']->addSeconds(intdiv($slot['out']->getTimestamp() - $slot['in']->getTimestamp(), 2)))) {
                // Likely the exit button pressed on arrival: never a confirmed entry, and not the day's exit either.
                $potentialEntry = $first;
                $out = $outs->reject(fn (object $punch): bool => $punch->id === $first->id)->last();
            }
        }
        if ($work && $in && ! $out) {
            $last = $ins->last();
            if ($last->id !== $in->id && $this->isNear($last->at, $slot['out']) && $this->wholeMinutes($in->at, $last->at) >= 120) {
                $potentialExit = $last;
            }
        }

        $actualIn = $in?->at;
        $actualOut = $out?->at;
        $ordered = $actualIn && $actualOut && $actualOut->greaterThan($actualIn);
        $late = null;
        $early = null;
        $grossLate = null;
        $excusedLate = 0;
        $excusedEarly = 0;
        if ($work && $grace !== null) {
            if ($actualIn) {
                // Gross lateness stays as punched; an approved permission explains part of it, then the grace applies.
                $grossLate = $flexible ? 0 : max(0, $this->wholeMinutes($slot['in'], $actualIn));
                $excusedLate = min($grossLate, $this->permissionOverlap($permissions, 'late', $slot['in'], $actualIn));
                $late = max(0, $grossLate - $excusedLate - $grace);
            }
            if ($actualOut && (! $actualIn || $ordered)) {
                if ($flexible && $ordered) {
                    $grossEarly = max(0, (int) $settings['required_minutes'] - $this->wholeMinutes($actualIn, $actualOut));
                    $excusedEarly = min($grossEarly, (int) collect($permissions)->where('effect', 'early')->sum('minutes'));
                } else {
                    $grossEarly = $flexible ? 0 : max(0, $this->wholeMinutes($actualOut, $slot['out']));
                    $excusedEarly = min($grossEarly, $this->permissionOverlap($permissions, 'early', $actualOut, $slot['out']));
                }
                $early = max(0, $grossEarly - $excusedEarly);
            }
        }
        $open = $work && $now->lessThan($end);

        if ($absence) {
            $status = $punches->isEmpty() ? $absence['status'] : 'holiday_or_leave_work_review';
        } elseif (! $slot) {
            $status = 'schedule_missing';
        } elseif ($slot['status'] === 'off') {
            $status = $punches->isEmpty() ? 'off' : 'off_day_punch';
        } elseif ($slot['status'] === 'no_duty') {
            $status = $punches->isEmpty() ? 'no_duty' : 'off_day_punch';
        } elseif ($slot['status'] === 'no_punch') {
            $status = 'exempt_punch';
        } elseif ($slot['status'] === 'exception') {
            $status = 'exception';
        } elseif ($slot['status'] === 'invalid') {
            $status = 'invalid_schedule';
        } elseif ($grace === null) {
            $status = 'grace_unapproved';
        } elseif ($punches->isEmpty()) {
            // Someone who cannot punch (not registered on any device) is not absent: HR reviews the enrollment.
            $status = ! $this->enrolled($employeeId) ? 'not_enrolled' : ($now->greaterThanOrEqualTo($slot['in']) ? 'missing_punches' : 'not_started');
        } elseif ($ordered || ($actualIn && ! $actualOut && ! $needsExit)) {
            $status = $late > 0 ? 'late' : ($early > 0 ? 'early' : 'present');
        } elseif ($actualIn && ! $actualOut && $open && ! $potentialExit) {
            $status = 'in_progress';
        } else {
            $status = 'incomplete_punches';
        }

        $instant = fn (?CarbonImmutable $value): ?string => $value?->format('Y-m-d H:i:sP');
        $timed = isset($slot['in'], $slot['out']);

        return [
            'expected_in' => $timed ? $instant($slot['in']) : null,
            'expected_out' => $timed ? $instant($slot['out']) : null,
            'actual_in' => $instant($actualIn),
            'actual_out' => $instant($actualOut),
            'late_minutes' => $late,
            'early_minutes' => $early,
            'status' => $status,
            'grace_minutes' => $grace,
            'explanation' => json_encode([
                'source' => $slot['source'] ?? null, 'reference_id' => $slot['id'] ?? null,
                'shift' => $settings ? ['id' => $settings['id'], 'name' => $settings['name'], 'kind' => $settings['kind'], 'location' => $settings['location'],
                    'break_minutes' => $settings['break_minutes'], 'break_paid' => $settings['break_paid'], 'required_minutes' => $settings['required_minutes'],
                    'required_punches' => $settings['required_punches']] : null,
                'tier' => $slot['tier'] ?? null, 'assignment_id' => $slot['assignment_id'] ?? null, 'override' => $slot['override'] ?? null,
                'grace_source' => $grace === null ? null : (isset($settings['grace_minutes']) ? 'shift' : 'policy'),
                'dst' => $slot['dst'] ?? null,
                'punch_count' => $punches->count(), 'absence' => $absence,
                'unknown_punches' => $punches->reject(fn (object $punch): bool => in_array($punch->direction, ['in', 'out'], true))->count(),
                'potential_entry' => $potentialEntry ? ['punch_id' => (int) $potentialEntry->id, 'at' => $instant($potentialEntry->at)] : null,
                'potential_exit' => $potentialExit ? ['punch_id' => (int) $potentialExit->id, 'at' => $instant($potentialExit->at)] : null,
                'gross_late' => $grossLate, 'excused_late' => $excusedLate ?: null, 'excused_early' => $excusedEarly ?: null,
                'permissions' => $permissions ?: null,
                'window' => [$instant($start), $instant($end)],
                'rule' => 'daily_v3',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * The punch window of a day. A timed shift owns its scheduled bounds widened by its windows (4 hours by default);
     * any other day owns its calendar day. Where a neighbouring shift's window overlaps, the boundary is the midpoint
     * between the two shifts (or the neighbour's window edge for a day without a shift), so a punch belongs to exactly
     * one day: the exit of a 20:00–08:00 shift is never also the next day's entry or an off-day punch.
     *
     * @param  array<string, mixed>|null  $slot
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $next
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(string $date, ?array $slot, ?array $previous, ?array $next): array
    {
        $tz = config('app.timezone');
        $timed = fn (?array $value): bool => $value !== null && isset($value['in'], $value['out']) && in_array($value['status'], ['work', 'no_punch'], true);
        $before = fn (array $value): int => (int) ($value['schedule']['window_before'] ?? AttendanceCalendar::DEFAULT_WINDOW_MINUTES);
        $after = fn (array $value): int => (int) ($value['schedule']['window_after'] ?? AttendanceCalendar::DEFAULT_WINDOW_MINUTES);
        $midpoint = fn (CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable => CarbonImmutable::createFromTimestamp(intdiv($a->getTimestamp() + $b->getTimestamp(), 2), $tz);
        if ($timed($slot)) {
            $start = $slot['in']->subMinutes($before($slot));
            $end = $slot['out']->addMinutes($after($slot));
        } else {
            $start = CarbonImmutable::parse($date.' 00:00:00', $tz);
            $end = CarbonImmutable::parse(CarbonImmutable::parse($date)->addDay()->toDateString().' 00:00:00', $tz);
        }
        if ($timed($previous) && $previous['out']->addMinutes($after($previous))->greaterThan($start)) {
            $boundary = $timed($slot) ? $midpoint($previous['out'], $slot['in']) : $previous['out']->addMinutes($after($previous));
            $start = $boundary->greaterThan($start) ? $boundary : $start;
        }
        if ($timed($next) && $next['in']->subMinutes($before($next))->lessThan($end)) {
            $boundary = $timed($slot) ? $midpoint($slot['out'], $next['in']) : $next['in']->subMinutes($before($next));
            $end = $boundary->lessThan($end) ? $boundary : $end;
        }

        return [$start, $end->greaterThan($start) ? $end : $start];
    }

    /**
     * Approved permissions of the day («طلب إذن»). Pending requests never excuse anything.
     *
     * @return list<array{id: int, type: string, label: string, effect: string, excuses: bool, from: string, to: string, minutes: int}>
     */
    public function approvedPermissions(int $employeeId, string $date): array
    {
        if (! self::hasPermissionRequests()) {
            return [];
        }

        return DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')
            ->where('r.employee_id', $employeeId)->where('r.work_date', $date)->where('r.status', 'approved')->orderBy('r.starts_at')
            ->get(['r.id', 'r.starts_at', 'r.ends_at', 'r.minutes', 'r.approved_minutes', 't.code', 't.name_ar', 't.metadata'])
            ->map(function (object $row): array {
                $rules = json_decode((string) $row->metadata, true) ?: [];

                return ['id' => (int) $row->id, 'type' => (string) $row->code, 'label' => (string) $row->name_ar, 'effect' => (string) ($rules['effect'] ?? 'temporary_exit'),
                    'excuses' => (bool) ($rules['excuses'] ?? true), 'from' => CarbonImmutable::parse($row->starts_at)->format('Y-m-d H:i:sP'),
                    'to' => CarbonImmutable::parse($row->ends_at)->format('Y-m-d H:i:sP'), 'minutes' => (int) ($row->approved_minutes ?? $row->minutes)];
            })->all();
    }

    /**
     * Minutes of [$from, $to) covered by approved, excusing permissions of one effect.
     *
     * @param  list<array<string, mixed>>  $permissions
     */
    private function permissionOverlap(array $permissions, string $effect, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $minutes = 0;
        foreach ($permissions as $permission) {
            if ($permission['effect'] !== $effect || ! $permission['excuses']) {
                continue;
            }
            $start = max($from->getTimestamp(), CarbonImmutable::parse($permission['from'])->getTimestamp());
            $end = min($to->getTimestamp(), CarbonImmutable::parse($permission['to'])->getTimestamp());
            $minutes += $end > $start ? min((int) floor(($end - $start) / 60), $permission['minutes']) : 0;
        }

        return $minutes;
    }

    /** Registered on a device, or has ever punched on one. */
    private function enrolled(int $employeeId): bool
    {
        return DB::table('attendance_employee_device_ids')->where('employee_id', $employeeId)->exists()
            || DB::table('attendance_punches')->where('employee_id', $employeeId)->whereNotNull('device_id')->exists();
    }

    private static function hasPermissionRequests(): bool
    {
        return self::$permissionTable ??= Schema::hasTable('attendance_permission_requests');
    }

    /**
     * Matched punches in the window with their effective direction.
     *
     * @return Collection<int, object{id: int, direction: string, at: CarbonImmutable}>
     */
    private function effectivePunches(int $employeeId, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DB::table('attendance_punches as p')
            ->leftJoin('attendance_punch_corrections as correction', function ($join): void {
                $join->on('correction.punch_id', '=', 'p.id')->where('correction.status', '=', 'approved');
            })
            ->leftJoin('attendance_auto_corrections as auto_correction', function ($join): void {
                $join->on('auto_correction.punch_id', '=', 'p.id')->where('auto_correction.status', '=', 'applied');
            })
            ->where('p.employee_id', $employeeId)
            ->where(fn ($query) => $query->whereNull('p.raw_payload')->orWhereIn('p.match_status', ['matched', 'reviewed']))
            ->where('p.occurred_at', '>=', $start->format('Y-m-d H:i:sP'))
            ->where('p.occurred_at', '<', $end->format('Y-m-d H:i:sP'))
            ->orderBy('p.occurred_at')->orderBy('p.id')
            ->get(['p.id', DB::raw('COALESCE(correction.corrected_direction, auto_correction.corrected_direction, p.direction) as direction'), 'p.occurred_at'])
            ->map(fn (object $punch): object => (object) ['id' => (int) $punch->id, 'direction' => (string) $punch->direction, 'at' => CarbonImmutable::parse($punch->occurred_at)]);
    }

    private function isNear(CarbonImmutable $at, CarbonImmutable $reference): bool
    {
        return abs($at->getTimestamp() - $reference->getTimestamp()) <= self::SUSPECT_MINUTES * 60;
    }

    /** Whole minutes from $from to $to (negative when $to is earlier); 08:10:59 against 08:00 is 10 minutes. */
    private function wholeMinutes(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}
