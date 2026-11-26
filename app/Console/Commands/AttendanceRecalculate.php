<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDailyService;
use App\Services\Kpi\Sources\AttendanceDaysSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the stored daily results. «--recent» (today and yesterday) is what the scheduler runs; older periods are
 * recalculated only on an explicit date or range, and «--dry-run» previews the differences without writing.
 */
final class AttendanceRecalculate extends Command
{
    protected $signature = 'attendance:recalculate
        {date? : YYYY-MM-DD}
        {--recent : Today and yesterday (the scheduled run)}
        {--from= : First day of a range (YYYY-MM-DD)}
        {--to= : Last day of a range (YYYY-MM-DD)}
        {--employee= : Employee ID}
        {--dry-run : Show what would change, write nothing}';

    protected $description = 'Build attendance daily summaries; no payroll or leave balances are changed';

    public function handle(AttendanceDailyService $service): int
    {
        $dates = $this->dates();
        if ($dates === null) {
            return self::FAILURE;
        }
        // No result before the employee's start date: a later hire is not "absent" on earlier days.
        $starts = DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active')
            ->when($this->option('employee'), fn ($query) => $query->where('id', (int) $this->option('employee')))
            ->orderBy('id')->get(['id', DB::raw('COALESCE(work_start_date, hire_date) as starts_on')])
            ->mapWithKeys(fn (object $employee): array => [(int) $employee->id => $employee->starts_on ? substr((string) $employee->starts_on, 0, 10) : null]);

        if ($this->option('dry-run')) {
            return $this->preview($service, $dates, $starts->all());
        }

        $lock = Cache::lock('attendance-daily-recalculation', 600);
        if (! $lock->get()) {
            $this->warn('Another recalculation is running; skipped.');

            return self::SUCCESS;
        }
        try {
            foreach ($dates as $date) {
                $count = 0;
                foreach ($starts as $id => $startsOn) {
                    if ($startsOn !== null && $startsOn > $date) {
                        continue;
                    }
                    $service->recalculate((int) $id, $date);
                    $count++;
                }
                $this->info("Calculated $count employee(s) for $date");
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $dates
     * @param  array<int, ?string>  $starts  employee id => start date
     */
    private function preview(AttendanceDailyService $service, array $dates, array $starts): int
    {
        $stored = DB::table('attendance_daily_results')->whereIn('work_date', $dates)->whereIn('employee_id', array_keys($starts))
            ->get()->keyBy(fn (object $row): string => $row->employee_id.'|'.$row->work_date);
        $instant = fn (?string $value): ?int => $value ? CarbonImmutable::parse($value)->getTimestamp() : null;
        $transitions = [];
        $groups = [];
        $changed = 0;
        $checked = 0;
        $samples = [];
        $before = [];
        $after = [];
        foreach ($dates as $date) {
            foreach ($starts as $id => $startsOn) {
                if ($startsOn !== null && $startsOn > $date) {
                    continue;
                }
                $checked++;
                $new = $service->calculate((int) $id, $date);
                $old = $stored->get($id.'|'.$date);
                $before[$old->status ?? '(none)'] = ($before[$old->status ?? '(none)'] ?? 0) + 1;
                $after[$new['status']] = ($after[$new['status']] ?? 0) + 1;
                $differs = ! $old || $old->status !== $new['status'] || $old->late_minutes !== $new['late_minutes'] || $old->early_minutes !== $new['early_minutes']
                    || $instant($old->actual_in) !== $instant($new['actual_in']) || $instant($old->actual_out) !== $instant($new['actual_out']);
                if (! $differs) {
                    continue;
                }
                $changed++;
                $key = ($old->status ?? '(none)').' -> '.$new['status'];
                $transitions[$key] = ($transitions[$key] ?? 0) + 1;
                // Who the changed days belong to: roster source and device enrollment.
                $tier = json_decode($new['explanation'], true)['tier'] ?? 'none';
                $group = ($new['status'] === 'not_enrolled' ? 'not enrolled on a device' : 'enrolled').' / roster: '.$tier;
                $groups[$group] = ($groups[$group] ?? 0) + 1;
                if (count($samples) < 15) {
                    $samples[] = [$date, $id, $old->status ?? '(none)', $new['status'], $old->late_minutes ?? '-', $new['late_minutes'] ?? '-', $old->early_minutes ?? '-', $new['early_minutes'] ?? '-'];
                }
            }
        }
        $this->info("DRY RUN — nothing written. Days checked: $checked, would change: $changed");
        arsort($transitions);
        $this->table(['status change', 'days'], collect($transitions)->map(fn (int $count, string $key): array => [$key, $count])->values()->all());
        arsort($groups);
        $this->table(['changed days by employee group', 'days'], collect($groups)->map(fn (int $count, string $key): array => [$key, $count])->values()->all());
        if ($samples) {
            $this->table(['date', 'employee', 'old status', 'new status', 'old late', 'new late', 'old early', 'new early'], $samples);
        }
        $this->table(['KPI «الانتظام في الدوام» (attendance_days)', 'stored now', 'after recalculation'], $this->kpiImpact($before, $after));

        return self::SUCCESS;
    }

    /**
     * The attendance indicator's sample and counts on the stored results and on the recalculated ones.
     *
     * @param  array<string, int>  $before  status => days
     * @param  array<string, int>  $after  status => days
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function kpiImpact(array $before, array $after): array
    {
        $measure = function (array $counts): array {
            $sum = fn (array $statuses): int => array_sum(array_map(fn (string $status): int => $counts[$status] ?? 0, $statuses));
            $evaluated = $sum(AttendanceDaysSource::EVALUATED);

            return [$evaluated, $sum(['present']), $evaluated ? round(100 * $sum(['present']) / $evaluated, 2).'%' : '-',
                $sum(AttendanceDaysSource::NO_PUNCH), $sum(['not_enrolled'])];
        };
        $labels = ['scheduled days evaluated', 'on time', 'on-time rate', 'days without punches', 'of which not enrolled (unverified)'];
        [$old, $new] = [$measure($before), $measure($after)];

        return array_map(fn (int $index): array => [$labels[$index], (string) $old[$index], (string) $new[$index]], array_keys($labels));
    }

    /**
     * @return list<string>|null
     */
    private function dates(): ?array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        if ($this->option('recent')) {
            return [$today->subDay()->toDateString(), $today->toDateString()];
        }
        $valid = fn (?string $value): bool => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) && $parsed->format('Y-m-d') === $value;
        $from = $this->argument('date') ?? $this->option('from');
        $to = $this->argument('date') ?? $this->option('to') ?? $from;
        if (! $valid($from) || ! $valid($to) || $from > $to) {
            $this->error('Give a valid date, --from/--to range, or --recent.');

            return null;
        }
        $days = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $days[] = $day->toDateString();
            if (count($days) > 366) {
                $this->error('A range is limited to 366 days.');

                return null;
            }
        }

        return $days;
    }
}
