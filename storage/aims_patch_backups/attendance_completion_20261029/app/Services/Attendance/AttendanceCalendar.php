<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AttendanceCalendar
{
    public function expected(int $employeeId, string $date): ?array
    {
        $employee = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->first(['department_id']);
        if (! $employee) {
            return null;
        }
        $scope = function ($q) use ($employeeId, $employee) {
            $q->where('employee_id', $employeeId)->orWhere(function ($s) use ($employee) {
                $s->whereNull('employee_id')->where('department_id', $employee->department_id);
            });
        };
        $override = DB::table('attendance_overrides')->where('status', 'approved')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)
            ->where(function ($q) use ($employeeId, $employee) {
                $q->where('employee_id', $employeeId)->orWhere(function ($s) use ($employee) {
                    $s->whereNull('employee_id')->where('department_id', $employee->department_id);
                });
                $q->orWhere(function ($s) {
                    $s->whereNull('employee_id')->whereNull('department_id');
                });
            })
            ->orderByRaw('CASE WHEN employee_id IS NOT NULL THEN 0 WHEN department_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderByDesc('id')->first();
        if ($override) {
            if (! $override->start_time || ! $override->end_time) {
                return ['status' => 'exception', 'source' => 'override', 'id' => $override->id];
            }

            return $this->slot($date, $override->start_time, $override->end_time, (bool) $override->ends_next_day, 'override', $override->id);
        }
        $assignment = DB::table('attendance_assignments')->where('status', 'approved')->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->where(function ($q) use ($employeeId, $employee) {
                $q->where('employee_id', $employeeId)->orWhere(function ($s) use ($employee) {
                    $s->whereNull('employee_id')->where('department_id', $employee->department_id);
                });
                $q->orWhere(function ($s) {
                    $s->whereNull('employee_id')->whereNull('department_id');
                });
            })
            ->orderByRaw('CASE WHEN employee_id IS NOT NULL THEN 0 WHEN department_id IS NOT NULL THEN 1 ELSE 2 END')
            ->orderByDesc('starts_on')->orderByDesc('id')->first();
        $schedule = $assignment ? DB::table('attendance_schedules')->where('id', $assignment->schedule_id)->where('is_active', true)->first()
         : DB::table('attendance_schedules')->where('is_default', true)->where('is_active', true)->orderBy('id')->first();
        if (! $schedule) {
            return null;
        } // Do not invent a work schedule.
        $weekday = CarbonImmutable::parse($date)->dayOfWeekIso;
        $day = DB::table('attendance_schedule_days')->where('schedule_id', $schedule->id)->where('weekday', $weekday)->first();

        return $day ? $this->slot($date, $day->start_time, $day->end_time, (bool) $day->ends_next_day, 'schedule', $schedule->id) : ['status' => 'off', 'source' => 'schedule', 'id' => $schedule->id];
    }

    private function slot(string $date, string $start, string $end, bool $next, string $source, int $id): array
    {
        $in = CarbonImmutable::parse($date.' '.substr($start, 0, 8), config('app.timezone', 'Asia/Gaza'));
        $out = CarbonImmutable::parse($date.' '.substr($end, 0, 8), config('app.timezone', 'Asia/Gaza'))->addDays($next ? 1 : 0);
        if ($out->lessThanOrEqualTo($in)) {
            return ['status' => 'invalid', 'source' => $source, 'id' => $id];
        }

        return ['status' => 'work', 'in' => $in, 'out' => $out, 'source' => $source, 'id' => $id];
    }
}
