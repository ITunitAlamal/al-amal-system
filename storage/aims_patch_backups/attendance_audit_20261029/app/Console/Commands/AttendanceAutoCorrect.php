<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceAutoCorrectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class AttendanceAutoCorrect extends Command
{
    protected $signature = 'attendance:auto-correct {--apply : Apply approved automatic policy} {--days=7 : Closed-day lookback}';

    protected $description = 'Review conservative automatic direction corrections; no writes without --apply and enabled policy';

    public function handle(AttendanceAutoCorrectionService $service): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        if ($this->option('apply') && ! config('attendance.auto_direction_enabled', false)) {
            $this->warn('AUTO_DISABLED: enable ATTENDANCE_AUTO_DIRECTION_ENABLED only after reviewing preview.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('attendance-auto-direction-correction', 240);
        if (! $lock->get()) {
            $this->warn('AUTO_BUSY');

            return self::SUCCESS;
        }
        try {
            $rows = $service->suggestions($days, 100);
            $applied = 0;
            foreach ($rows as $candidate) {
                $this->line(json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ($this->option('apply') && $service->apply($candidate)) {
                    $applied++;
                }
            }
            $this->info(json_encode(['suggestions' => count($rows), 'applied' => $applied, 'enabled' => (bool) config('attendance.auto_direction_enabled', false)], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
