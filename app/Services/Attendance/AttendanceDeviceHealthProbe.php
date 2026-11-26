<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AttendanceDeviceHealthProbe implements AttendanceDeviceProber
{
    public function probe(object $device): array
    {
        $lock = Cache::lock('attendance-device-io:'.$device->id, 180);
        if (! $lock->get()) {
            return ['result' => 'disconnected', 'latency_ms' => null, 'error_code' => 'device_busy'];
        }
        try {
            $payload = app(AttendanceDeviceReader::class)->read($device, true);
            if (Schema::hasColumn('attendance_devices', 'firmware')) {
                $metadata = ['firmware' => isset($payload['firmware']) ? substr($payload['firmware'], 0, 150) : null,
                    'platform' => isset($payload['platform']) ? substr($payload['platform'], 0, 80) : null];
                if (! empty($payload['device_time']) && ! empty($device->timezone)) {
                    $metadata['clock_drift_seconds'] = (int) now()->diffInSeconds(CarbonImmutable::parse($payload['device_time'], $device->timezone), false);
                }
                DB::table('attendance_devices')->where('id', $device->id)->where('host', $device->host)->where('port', $device->port)->update($metadata);
            }

            return ['result' => 'connected', 'latency_ms' => (int) ($payload['latency_ms'] ?? 0), 'error_code' => null];
        } catch (Throwable $exception) {
            $allowed = ['host_not_approved', 'probe_timeout', 'probe_runtime_unavailable', 'invalid_configuration'];

            return ['result' => 'disconnected', 'latency_ms' => null, 'error_code' => in_array($exception->getMessage(), $allowed, true) ? $exception->getMessage() : 'probe_failed'];
        } finally {
            $lock->release();
        }
    }
}
