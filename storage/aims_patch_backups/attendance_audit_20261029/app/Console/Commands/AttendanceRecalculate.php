<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDailyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AttendanceRecalculate extends Command
{
    protected $signature = 'attendance:recalculate {date : YYYY-MM-DD} {--employee= : Employee ID}';

    protected $description = 'Build attendance daily summaries; no payroll or leave balances are changed';

    public function handle(AttendanceDailyService $service): int
    {
        $date = (string) $this->argument('date');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! \DateTimeImmutable::createFromFormat('!Y-m-d', $date) || \DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
            $this->error('Invalid date');

            return self::FAILURE;
        }
        $q = DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active');
        if ($this->option('employee')) {
            $q->where('id', (int) $this->option('employee'));
        }
        $count = 0;
        foreach ($q->orderBy('id')->pluck('id') as $id) {
            $service->recalculate((int) $id, $date);
            $count++;
        }
        $this->info("Calculated $count employee(s) for $date");

        return self::SUCCESS;
    }
}
