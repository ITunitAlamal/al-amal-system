<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Roster views over the canonical calendar: the HR grid («الورديات والإسناد»), an employee's upcoming shifts, and the
 * preview of a bulk assignment or exemption before it is sent for approval. Read-only.
 */
final class AttendanceRoster
{
    private const WEEKDAYS = [1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت', 7 => 'الأحد'];

    public function __construct(private AttendanceCalendar $calendar) {}

    /**
     * @param  list<int>  $employeeIds
     * @return array{days: list<array{date: string, weekday: string}>, rows: list<array<string, mixed>>}
     */
    public function grid(array $employeeIds, string $from, string $to): array
    {
        $slots = $this->calendar->resolve($employeeIds, $from, $to);
        $leaves = $this->leaves($employeeIds, $from, $to);
        $holidays = DB::table('leave_holidays')->whereBetween('holiday_date', [$from, $to])->pluck('name_ar', 'holiday_date')->all();
        $employees = DB::table('employees as e')->leftJoin('departments as d', 'd.id', '=', 'e.department_id')->whereIn('e.id', $employeeIds)
            ->orderBy('e.first_name_ar')->orderBy('e.family_name_ar')
            ->get(['e.id', 'e.employee_number', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar', 'd.name as department']);
        $days = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'weekday' => self::WEEKDAYS[$day->dayOfWeekIso]];
        }

        return [
            'days' => $days,
            'rows' => $employees->map(fn (object $employee): array => [
                'employee_id' => (int) $employee->id, 'name' => AttendanceLedger::fullName($employee), 'number' => $employee->employee_number,
                'department' => $employee->department,
                'cells' => array_map(fn (array $day): array => $this->cell($slots[(int) $employee->id][$day['date']] ?? null, $day['date'],
                    $leaves[(int) $employee->id][$day['date']] ?? null, $holidays[$day['date']] ?? null), $days),
            ])->all(),
        ];
    }

    /**
     * One employee's next days (employee file, «مساحتي»).
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(int $employeeId, int $days = 14): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $to = CarbonImmutable::parse($today)->addDays($days - 1)->toDateString();
        $grid = $this->grid([$employeeId], $today, $to);

        return array_map(fn (array $day, array $cell): array => $day + $cell, $grid['days'], $grid['rows'][0]['cells'] ?? array_fill(0, count($grid['days']), []));
    }

    /**
     * What a new assignment or exemption would change, per employee, before it is sent for approval.
     *
     * @param  list<int>  $employeeIds
     * @param  array{kind: string, schedule_id?: ?int, effect?: ?string}  $change  kind "assignment" or "exemption"
     * @return array{employees: int, days_changed: int, retroactive_days: int, rows: list<array<string, mixed>>, warnings: list<string>}
     */
    public function preview(array $employeeIds, string $from, string $to, array $change): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $to = min($to, CarbonImmutable::parse($from)->addDays(92)->toDateString());
        $current = $this->calendar->resolve($employeeIds, $from, $to);
        $schedule = ($change['schedule_id'] ?? null) ? DB::table('attendance_schedules')->where('id', $change['schedule_id'])->first() : null;
        $scheduleDays = $schedule ? DB::table('attendance_schedule_days')->where('schedule_id', $schedule->id)->get()->keyBy(fn (object $row): int => (int) $row->weekday) : collect();
        $pending = DB::table('attendance_assignments')->where('status', 'pending')->whereIn('employee_id', $employeeIds)->pluck('employee_id')->flip();
        $leaves = $this->leaves($employeeIds, $from, $to);
        $names = DB::table('employees')->whereIn('id', $employeeIds)->get(['id', 'first_name_ar', 'father_name_ar', 'family_name_ar'])->keyBy('id');
        $rows = [];
        $changed = 0;
        $retro = 0;
        foreach ($employeeIds as $employeeId) {
            $count = 0;
            $kept = 0;
            $leaveDays = 0;
            foreach ($current[$employeeId] ?? [] as $date => $slot) {
                $tier = $slot['tier'] ?? null;
                if ($change['kind'] === 'assignment') {
                    // Exceptions, swaps and exemptions stay above any assignment.
                    if (in_array($tier, ['override', 'swap', 'exemption', 'department_override', 'organization_override'], true)) {
                        $kept++;

                        continue;
                    }
                    $new = $this->describeDay($schedule, $scheduleDays, $date);
                    $differs = $new !== $this->describe($slot);
                } else {
                    $differs = ($slot['status'] ?? null) === 'work';
                }
                if ($differs) {
                    $count++;
                    $retro += $date < $today ? 1 : 0;
                    $leaveDays += isset($leaves[$employeeId][$date]) ? 1 : 0;
                }
            }
            $changed += $count;
            $rows[] = ['employee_id' => $employeeId, 'name' => isset($names[$employeeId]) ? AttendanceLedger::fullName($names[$employeeId]) : '#'.$employeeId,
                'days_changed' => $count, 'kept_by_exception' => $kept, 'leave_days' => $leaveDays, 'pending_assignment' => $pending->has($employeeId)];
        }
        $warnings = [];
        if ($retro > 0) {
            $warnings[] = "التغيير بأثر رجعي: {$retro} يومًا سابقًا سيُعاد احتسابها بعد الاعتماد.";
        }
        if (collect($rows)->contains('pending_assignment', true)) {
            $warnings[] = 'لدى بعض الموظفين تكليف آخر قيد الاعتماد؛ يُطبق الأحدث تاريخ بداية عند اعتماد كليهما.';
        }
        if (collect($rows)->sum('leave_days') > 0) {
            $warnings[] = 'تتقاطع الفترة مع إجازات معتمدة؛ تبقى الإجازة هي المعتمدة في تلك الأيام.';
        }
        $warnings[] = 'لا يترتب على هذا التغيير أي أثر على الرواتب أو الجزاءات تلقائيًا.';

        return ['employees' => count($employeeIds), 'days_changed' => $changed, 'retroactive_days' => $retro, 'rows' => $rows, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>|null  $slot
     * @param  array{label: string}|null  $leave
     * @return array<string, mixed>
     */
    private function cell(?array $slot, string $date, ?array $leave, ?string $holiday): array
    {
        $status = $slot['status'] ?? 'none';

        return [
            'status' => $holiday ? 'holiday' : ($leave ? 'leave' : $status),
            'label' => $holiday ?? ($leave['label'] ?? $this->describe($slot)),
            'times' => $this->describe($slot),
            'shift' => $slot['schedule']['name'] ?? null,
            'tier' => isset($slot['tier']) ? (AttendanceCalendar::TIER_LABELS[$slot['tier']] ?? null) : null,
            'swap' => ($slot['override']['swap_id'] ?? null) !== null,
            'exemption' => in_array($status, ['no_duty', 'no_punch'], true) ? ($slot['override']['reason_label'] ?? $slot['override']['label'] ?? null) : null,
            'override_label' => $slot['override']['label'] ?? null,
            'dst' => $slot['dst'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $slot
     */
    private function describe(?array $slot): string
    {
        $status = $slot['status'] ?? 'none';
        if (isset($slot['in'], $slot['out']) && in_array($status, ['work', 'no_punch'], true)) {
            return $slot['in']->format('H:i').'–'.$slot['out']->format('H:i').($slot['out']->toDateString() !== $slot['in']->toDateString() ? ' (+1)' : '')
                .($status === 'no_punch' ? ' · معفى من البصمة' : '');
        }

        return ['off' => 'راحة', 'no_duty' => 'لا يوجد دوام مقرر', 'exception' => 'استثناء بلا أوقات', 'invalid' => 'جدول غير صالح', 'none' => 'لا يوجد دوام مقرر'][$status] ?? $status;
    }

    private function describeDay(?object $schedule, $scheduleDays, string $date): string
    {
        if (! $schedule) {
            return 'لا يوجد دوام مقرر';
        }
        $key = CarbonImmutable::parse($date)->dayOfWeekIso;
        if (($schedule->kind ?? 'fixed') === 'rotation' && ($schedule->cycle_days ?? null) && ($schedule->cycle_anchor ?? null)) {
            $offset = (int) floor((CarbonImmutable::parse($date)->getTimestamp() - CarbonImmutable::parse((string) $schedule->cycle_anchor)->getTimestamp()) / 86400 + 0.5);
            $key = (($offset % (int) $schedule->cycle_days) + (int) $schedule->cycle_days) % (int) $schedule->cycle_days + 1;
        }
        $day = $scheduleDays->get($key);

        return $day ? substr((string) $day->start_time, 0, 5).'–'.substr((string) $day->end_time, 0, 5).($day->ends_next_day ? ' (+1)' : '') : 'راحة';
    }

    /**
     * Approved leave per employee and day.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, array{label: string}>>
     */
    private function leaves(array $employeeIds, string $from, string $to): array
    {
        $result = [];
        foreach (DB::table('employee_leave_requests as l')->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->whereIn('l.employee_id', $employeeIds)->whereIn('l.status', ['approved', 'pending_registration'])
            ->where('l.start_date', '<=', $to)->where('l.end_date', '>=', $from)->get(['l.employee_id', 'l.start_date', 'l.end_date', 't.name_ar']) as $leave) {
            for ($day = CarbonImmutable::parse(max($from, (string) $leave->start_date)); $day->toDateString() <= min($to, (string) $leave->end_date); $day = $day->addDay()) {
                $result[(int) $leave->employee_id][$day->toDateString()] = ['label' => $leave->name_ar ?? 'إجازة'];
            }
        }

        return $result;
    }
}
