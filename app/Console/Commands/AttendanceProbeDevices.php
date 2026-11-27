<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDeviceBackoff;
use App\Services\Attendance\AttendanceDeviceProber;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $backoff = AttendanceDeviceBackoff::ready();
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
            // A device that keeps failing is probed less often (AttendanceDeviceBackoff); "probe now" still runs at once.
            if (! $hasProbeRequest && $backoff && $device->probe_retry_at && $probeNow->lessThan(CarbonImmutable::parse($device->probe_retry_at))) {
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
                $failures = (int) ($device->probe_failure_count ?? 0);
                if ($result['result'] === 'connected') {
                    $status['last_success_at'] = now();
                    if ($backoff) {
                        $status += ['probe_failure_count' => 0, 'probe_retry_at' => null];
                        if ($failures > 0) {
                            Log::info('Attendance device reachable again', ['device_id' => (int) $device->id, 'failed_probes' => $failures]);
                        }
                    }
                } else {
                    if ($result['error_code'] !== 'device_busy' && Schema::hasColumn('attendance_devices', 'last_failure_at')) {
                        $status['last_failure_at'] = now();
                        $status['last_failure_code'] = $result['error_code'];
                    }
                    // A busy device is syncing, not unreachable: it neither counts as a failure nor delays the next probe.
                    if ($backoff && $result['error_code'] !== 'device_busy') {
                        $retryIn = AttendanceDeviceBackoff::delay($failures + 1, $interval);
                        $status += ['probe_failure_count' => $failures + 1, 'probe_retry_at' => now()->addSeconds($retryIn)];
                        if ($failures === 0 || $device->probe_error_code !== $result['error_code']) {
                            Log::warning('Attendance device unreachable', ['device_id' => (int) $device->id, 'code' => $result['error_code'], 'retry_in_seconds' => $retryIn]);
                        }
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

        // An unreachable device is a recorded state of the device (shown on the attendance page and logged once above),
        // not a failure of this command: failing it would log a stack trace on every scheduler run.
        return $backoff || $failedProbes === 0 ? self::SUCCESS : self::FAILURE;
    }
}
