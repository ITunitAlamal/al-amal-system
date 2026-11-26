<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttendanceDailyService
{
    public function __construct(private AttendanceCalendar $calendar, private AttendancePolicy $policy) {}

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

    public function recalculate(int $employeeId, string $date): array
    {
        $slot = $this->calendar->expected($employeeId, $date);
        $absence = app(AttendanceAbsenceResolver::class)->resolve($employeeId, $date);
        $grace = $this->policy->graceMinutes();
        $start = CarbonImmutable::parse($date.' 00:00:00', config('app.timezone'));
        $end = $start->addDay();
        if ($slot && ($slot['status'] ?? null) === 'work') {
            // Include overnight punches and allow a narrow window around scheduled bounds.
            $start = $slot['in']->subHours(4);
            $end = $slot['out']->addHours(4);
        }
        $punches = DB::table('attendance_punches')->where('employee_id', $employeeId)
            ->when(Schema::hasColumn('attendance_punches', 'raw_payload'), fn ($query) => $query->where(function ($q) {
                $q->whereNull('raw_payload')->orWhere('match_status', 'matched');
            }))
            ->whereIn('direction', ['in', 'out'])
            ->where('occurred_at', '>=', $start->utc()->toDateTimeString())->where('occurred_at', '<', $end->utc()->toDateTimeString())
            ->orderBy('occurred_at')->get(['direction', 'occurred_at']);
        $in = $punches->firstWhere('direction', 'in');
        $out = $punches->filter(fn ($p) => $p->direction === 'out')->last();
        $actualIn = $in ? CarbonImmutable::parse($in->occurred_at, 'UTC') : null;
        $actualOut = $out ? CarbonImmutable::parse($out->occurred_at, 'UTC') : null;
        $late = null;
        $early = null;
        $status = 'unscheduled';
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
        } elseif (! $actualIn && ! $actualOut) {
            $status = 'missing_punches';
        } elseif (! $actualIn || ! $actualOut || $actualOut->lessThan($actualIn)) {
            $status = 'incomplete_punches';
        } else {
            $late = max(0, (int) floor($slot['in']->diffInMinutes($actualIn, false)) - $grace);
            $early = max(0, (int) floor($actualOut->diffInMinutes($slot['out'], false)));
            $status = $late > 0 ? 'late' : ($early > 0 ? 'early' : 'present');
        }
        $row = ['expected_in' => ($slot['status'] ?? null) === 'work' ? $slot['in']->utc()->toDateTimeString() : null,
            'expected_out' => ($slot['status'] ?? null) === 'work' ? $slot['out']->utc()->toDateTimeString() : null,
            'actual_in' => $actualIn?->utc()->toDateTimeString(), 'actual_out' => $actualOut?->utc()->toDateTimeString(),
            'late_minutes' => $late, 'early_minutes' => $early, 'status' => $status, 'grace_minutes' => $grace,
            'explanation' => json_encode(['source' => $slot['source'] ?? null, 'reference_id' => $slot['id'] ?? null, 'punch_count' => $punches->count(), 'absence' => $absence], JSON_THROW_ON_ERROR),
            'updated_at' => now()];
        DB::table('attendance_daily_results')->updateOrInsert(['employee_id' => $employeeId, 'work_date' => $date], $row + ['created_at' => now()]);

        return ['employee_id' => $employeeId, 'work_date' => $date, 'status' => $status, 'late_minutes' => $late, 'early_minutes' => $early];
    }
}
