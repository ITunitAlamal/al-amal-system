<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDeviceProber;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AttendanceProbeDevices extends Command
{
    protected $signature = 'attendance:probe-devices';

    protected $description = 'Update cached health status for approved attendance devices without reading punches';

    public function handle(AttendanceDeviceProber $probe): int
    {
        if (config('attendance.health_probe_enabled', false) !== true) {
            $this->error('Health probing is disabled. Enable ATTENDANCE_HEALTH_PROBE_ENABLED only for the LAN pilot.');

            return self::FAILURE;
        }

        $agentId = (string) config('attendance.agent_id', 'local');
        $now = CarbonImmutable::now();
        $devices = DB::table('attendance_devices')->where('assigned_agent', $agentId)->orderBy('id')->get();

        DB::table('attendance_devices')->where('assigned_agent', $agentId)->update([
            'agent_last_seen_at' => $now,
            'updated_at' => $now,
        ]);

        $failedProbes = 0;
        foreach ($devices as $device) {
            if (! $device->is_active || ! $device->probe_enabled) {
                continue;
            }

            $heartbeatAt = now();
            DB::table('attendance_devices')
                ->where('id', $device->id)
                ->where('assigned_agent', $agentId)
                ->update(['agent_last_seen_at' => $heartbeatAt, 'updated_at' => $heartbeatAt]);

            $interval = max(30, min(3600, (int) ($device->probe_interval_seconds ?: config('attendance.health_probe_interval_seconds', 30))));
            $interval = (int) (ceil($interval / 30) * 30);
            $probeNow = CarbonImmutable::now();
            $hasProbeRequest = $device->probe_requested_at !== null;
            if (! $hasProbeRequest && $device->last_probe_at && CarbonImmutable::parse($device->last_probe_at)->isAfter($probeNow->subSeconds($interval))) {
                continue;
            }

            $timeout = max(3, min(15, (int) config('attendance.health_probe_timeout_seconds', 10)));
            $leaseExpiredAt = $probeNow->subSeconds($timeout + 5);
            $lockToken = (string) Str::uuid();
            $lockQuery = DB::table('attendance_devices')
                ->where('id', $device->id)
                ->where('is_active', true)
                ->where('probe_enabled', true)
                ->where('assigned_agent', $agentId)
                ->where('host', $device->host)
                ->where('port', $device->port)
                ->where('protocol', $device->protocol)
                ->where(function ($query) use ($leaseExpiredAt): void {
                    $query->whereNull('probe_started_at')->orWhere('probe_started_at', '<=', $leaseExpiredAt);
                });
            if ($hasProbeRequest) {
                $lockQuery->where('probe_requested_at', $device->probe_requested_at);
            } else {
                $lockQuery->whereNull('probe_requested_at');
            }
            $acquired = $lockQuery->update([
                'probe_started_at' => $probeNow,
                'probe_lock_token' => $lockToken,
                'updated_at' => $probeNow,
            ]);

            if ($acquired !== 1) {
                continue;
            }

            try {
                $result = $probe->probe($device);
                $status = [
                    'last_probe_at' => now(),
                    'probe_latency_ms' => $result['latency_ms'],
                    'probe_result' => $result['result'],
                    'probe_error_code' => $result['error_code'],
                    'updated_at' => now(),
                ];
                if ($result['result'] === 'connected') {
                    $status['last_success_at'] = now();
                } else {
                    if ($result['error_code'] !== 'device_busy' && Schema::hasColumn('attendance_devices', 'last_failure_at')) {
                        $status['last_failure_at'] = now();
                        $status['last_failure_code'] = $result['error_code'];
                    }
                    $failedProbes++;
                    $this->warn("Device {$device->id} probe failed ({$result['error_code']}).");
                }

                $statusQuery = DB::table('attendance_devices')
                    ->where('id', $device->id)
                    ->where('probe_lock_token', $lockToken)
                    ->where('is_active', true)
                    ->where('probe_enabled', true)
                    ->where('assigned_agent', $agentId)
                    ->where('host', $device->host)
                    ->where('port', $device->port)
                    ->where('protocol', $device->protocol);
                if ($hasProbeRequest) {
                    $status['probe_requested_at'] = null;
                    $statusQuery->where('probe_requested_at', $device->probe_requested_at);
                }
                $statusQuery->update($status);
            } finally {
                DB::table('attendance_devices')->where('id', $device->id)->where('probe_lock_token', $lockToken)->update([
                    'probe_started_at' => null,
                    'probe_lock_token' => null,
                    'updated_at' => now(),
                ]);
            }
        }

        return $failedProbes === 0 ? self::SUCCESS : self::FAILURE;
    }
}
