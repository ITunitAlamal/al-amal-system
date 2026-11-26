<?php

namespace App\Services\Attendance;

interface AttendanceDeviceProber
{
    /**
     * @return array{result: 'connected'|'disconnected', latency_ms: ?int, error_code: ?string}
     */
    public function probe(object $device): array;
}
