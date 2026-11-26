<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class AttendanceDeviceReader
{
    /** @return array<string, mixed> */
    public function read(object $device, bool $probeOnly = false): array
    {
        if (! app(AttendanceDestinationPolicy::class)->allows($device)) {
            throw new RuntimeException('host_not_approved');
        }
        $timeout = max(1, min(15, (int) ($device->connection_timeout ?? 6)));
        $arguments = [(string) config('attendance.zk_python'), base_path('attendance_bridge/zk_readonly.py'),
            '--host', $device->host, '--port', (string) $device->port, '--protocol', $device->protocol,
            '--timeout', (string) $timeout, '--credentials-stdin'];
        if ($probeOnly) {
            $arguments[] = '--probe-only';
        }
        $process = new Process($arguments);
        $password = empty($device->communication_password) ? '0' : Crypt::decryptString($device->communication_password);
        $process->setInput(json_encode(['password' => $password], JSON_THROW_ON_ERROR));
        $process->setTimeout($probeOnly ? $timeout * ($device->protocol === 'auto' ? 2 : 1) + 5 : 90);
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                $code = trim($process->getErrorOutput());
                throw new RuntimeException(in_array($code, ['pyzk_unavailable', 'probe_timeout', 'invalid_configuration'], true) ? $code : 'reader_failed');
            }
            $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($probeOnly ? ($payload['connected'] ?? false) !== true : ! is_array($payload['events'] ?? null))) {
                throw new RuntimeException('invalid_reader_response');
            }

            return $payload;
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('probe_timeout');
        } catch (ProcessStartFailedException) {
            throw new RuntimeException('probe_runtime_unavailable');
        } catch (\JsonException) {
            throw new RuntimeException('invalid_reader_response');
        }
    }
}
