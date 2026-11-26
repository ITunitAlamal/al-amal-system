<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDeviceHealthProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AttendanceDeviceProbe extends Command
{
    protected $signature = 'attendance:device-probe {device : Registered device ID}';

    protected $description = 'Read-only protocol probe; no attendance retrieval';

    public function handle(AttendanceDeviceHealthProbe $probe): int
    {
        $device = DB::table('attendance_devices')->find((int) $this->argument('device'));
        if (! $device || ! $device->is_active) {
            $this->error('An active registered device is required.');

            return self::FAILURE;
        }
        $result = $probe->probe($device);
        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['result'] === 'connected' ? self::SUCCESS : self::FAILURE;
    }
}
