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
 * Added: in_progress (entry without exit while the shift window is open), not_started (no punch before the shift).
 */
final class AttendanceDailyService
{
    /** Punches this many hours around the scheduled shift belong to it; the day closes after the same margin. */
    public const WINDOW_HOURS = 4;

    /** A wrong-button punch this close to the shift start (or end) is flagged as a possible entry (or exit). */
    public const SUSPECT_MINUTES = 75;

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
     * Pure calculation without writing (used by recalculation and by dry-run previews).
     *
     * @return array{expected_in: ?string, expected_out: ?string, actual_in: ?string, actual_out: ?string, late_minutes: ?int, early_minutes: ?int, status: string, grace_minutes: ?int, explanation: string}
     */
    public function calculate(int $employeeId, string $date, ?CarbonImmutable $now = null): array
    {
        $tz = config('app.timezone');
        $now ??= CarbonImmutable::now($tz);
        $slot = $this->calendar->expected($employeeId, $date);
        $absence = $this->absences->resolve($employeeId, $date);
        $grace = $this->policy->graceMinutes();
        $work = ($slot['status'] ?? null) === 'work';
        $start = $work ? $slot['in']->subHours(self::WINDOW_HOURS) : CarbonImmutable::parse($date.' 00:00:00', $tz);
        $end = $work ? $slot['out']->addHours(self::WINDOW_HOURS) : CarbonImmutable::parse($date.' 00:00:00', $tz)->addDay();

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
        if ($work && $grace !== null) {
            if ($actualIn) {
                $late = max(0, $this->wholeMinutes($slot['in'], $actualIn) - $grace);
            }
            if ($actualOut && (! $actualIn || $ordered)) {
                $early = max(0, $this->wholeMinutes($actualOut, $slot['out']));
            }
        }
        $open = $work && $now->lessThan($slot['out']->addHours(self::WINDOW_HOURS));

        if ($absence) {
            $status = $punches->isEmpty() ? $absence['status'] : 'holiday_or_leave_work_review';
        } elseif (! $slot) {
            $status = 'schedule_missing';
        } elseif ($slot['status'] === 'off') {
            $status = $punches->isEmpty() ? 'off' : 'off_day_punch';
        } elseif ($slot['status'] === 'exception') {
            $status = 'exception';
        } elseif ($slot['status'] === 'invalid') {
            $status = 'invalid_schedule';
        } elseif ($grace === null) {
            $status = 'grace_unapproved';
        } elseif ($punches->isEmpty()) {
            $status = $now->greaterThanOrEqualTo($slot['in']) ? 'missing_punches' : 'not_started';
        } elseif ($ordered) {
            $status = $late > 0 ? 'late' : ($early > 0 ? 'early' : 'present');
        } elseif ($actualIn && ! $actualOut && $open && ! $potentialExit) {
            $status = 'in_progress';
        } else {
            $status = 'incomplete_punches';
        }

        $instant = fn (?CarbonImmutable $value): ?string => $value?->format('Y-m-d H:i:sP');

        return [
            'expected_in' => $work ? $instant($slot['in']) : null,
            'expected_out' => $work ? $instant($slot['out']) : null,
            'actual_in' => $instant($actualIn),
            'actual_out' => $instant($actualOut),
            'late_minutes' => $late,
            'early_minutes' => $early,
            'status' => $status,
            'grace_minutes' => $grace,
            'explanation' => json_encode([
                'source' => $slot['source'] ?? null, 'reference_id' => $slot['id'] ?? null,
                'punch_count' => $punches->count(), 'absence' => $absence,
                'unknown_punches' => $punches->reject(fn (object $punch): bool => in_array($punch->direction, ['in', 'out'], true))->count(),
                'potential_entry' => $potentialEntry ? ['punch_id' => (int) $potentialEntry->id, 'at' => $instant($potentialEntry->at)] : null,
                'potential_exit' => $potentialExit ? ['punch_id' => (int) $potentialExit->id, 'at' => $instant($potentialExit->at)] : null,
                'window' => [$instant($start), $instant($end)],
                'rule' => 'daily_v2',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
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
