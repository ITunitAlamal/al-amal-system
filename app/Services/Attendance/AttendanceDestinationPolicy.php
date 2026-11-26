<?php

namespace App\Services\Attendance;

final class AttendanceDestinationPolicy
{
    public function allows(object $device): bool
    {
        if (($device->driver ?? 'pyzk') !== 'pyzk' || ($device->assigned_agent ?? '') !== (string) config('attendance.agent_id', 'local')
            || ! filter_var($device->host ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || ! in_array($device->protocol ?? '', ['tcp', 'udp', 'auto'], true)) {
            return false;
        }
        $host = $device->host;
        if (str_starts_with($host, '127.') || str_starts_with($host, '169.254.') || (int) explode('.', $host)[0] >= 224 || str_starts_with($host, '0.')) {
            return false;
        }
        $rules = config('attendance.agent_destinations', []);
        if (! is_array($rules) || ! array_is_list($rules) || (int) $device->port < 1 || (int) $device->port > 65535) {
            return false;
        }
        if ($rules !== []) {
            foreach ($rules as $rule) {
                if (! is_array($rule)) {
                    return false;
                }
                if (($rule['agent'] ?? null) === $device->assigned_agent && ($rule['host'] ?? null) === $host
                    && (int) ($rule['port'] ?? 0) === (int) $device->port
                    && (! isset($rule['device_id']) || (int) $rule['device_id'] === (int) ($device->id ?? 0))) {
                    return true;
                }
            }

            return false;
        }
        $hosts = array_map('trim', explode(',', (string) config('attendance.zk_allowed_hosts', '')));
        $ports = array_map('intval', explode(',', (string) config('attendance.zk_allowed_ports', '4370')));

        return in_array($host, $hosts, true) && in_array((int) $device->port, $ports, true);
    }
}
