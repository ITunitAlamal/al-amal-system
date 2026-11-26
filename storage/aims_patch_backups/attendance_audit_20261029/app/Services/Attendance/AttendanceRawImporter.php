<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AttendanceRawImporter
{
    public function configurationDigest(object $device): string
    {
        return hash('sha256', json_encode(array_map(fn (string $key): mixed => $device->{$key} ?? null,
            ['id', 'host', 'port', 'protocol', 'assigned_agent', 'driver', 'timezone', 'communication_password']), JSON_THROW_ON_ERROR));
    }

    /** @return array{received: int, inserted: int, duplicates: int, unmatched: int, failed: int} */
    public function ingest(object $device, array $events, int $runId, bool $dryRun): array
    {
        if (count($events) > 100000 || ! in_array($device->timezone ?? '', timezone_identifiers_list(), true)) {
            throw new RuntimeException('invalid_snapshot_configuration');
        }

        return DB::transaction(function () use ($device, $events, $runId, $dryRun): array {
            $current = DB::table('attendance_devices')->where('id', $device->id)->lockForUpdate()->first();
            if (! $current || $this->configurationDigest($current) !== $this->configurationDigest($device)
                || ! $current->is_active || $current->sync_mode !== 'pull' || (! $dryRun && ! $current->import_approved_at)) {
                throw new RuntimeException('import_not_authorized');
            }
            $employees = DB::table('employees')->whereNull('deleted_at')
                ->get(['id', 'employee_number'])->groupBy(fn (object $employee): string => 'number:'.$employee->employee_number);
            $mappings = DB::table('attendance_employee_device_ids')->where('device_id', $device->id)
                ->get()->keyBy(fn (object $mapping): string => 'number:'.$mapping->device_user_id);
            $existing = DB::table('attendance_punches')->where('device_id', $device->id)
                ->whereNotNull('raw_payload')->pluck('source_key')->flip();
            $counts = ['received' => count($events), 'inserted' => 0, 'duplicates' => 0, 'unmatched' => 0, 'failed' => 0];
            $occurrences = [];
            $rows = [];
            foreach ($events as $event) {
                if (! is_array($event) || ! is_string($event['uid'] ?? null) || $event['uid'] === '' || strlen($event['uid']) > 100
                    || ! is_string($event['timestamp'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $event['timestamp'])) {
                    throw new RuntimeException('invalid_snapshot_event');
                }
                $raw = array_intersect_key($event, array_flip(['uid', 'timestamp', 'status', 'punch', 'type', 'device_event_id']));
                ksort($raw);
                foreach (['status', 'punch', 'type', 'device_event_id'] as $field) {
                    if (isset($raw[$field]) && ! is_int($raw[$field]) && ! is_string($raw[$field])) {
                        throw new RuntimeException('invalid_snapshot_event');
                    }
                }
                $json = json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $hash = hash('sha256', $device->id.'|'.$json);
                $occurrence = $occurrences[$hash] = ($occurrences[$hash] ?? 0) + 1;
                $key = 'zkraw:'.$hash.':'.$occurrence;
                $matches = $employees->get('number:'.$event['uid'], collect());
                $employeeId = $matches->count() === 1 ? (int) $matches->first()->id : null;
                $mapping = $mappings->get('number:'.$event['uid']);
                if ($mapping && (int) $mapping->employee_id !== $employeeId) {
                    $employeeId = null;
                }
                $counts['unmatched'] += $employeeId === null ? 1 : 0;
                if ($existing->has($key)) {
                    $counts['duplicates']++;

                    continue;
                }
                $local = substr($event['timestamp'], 0, 19);
                $time = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $local, $device->timezone);
                if (! $time || $time->format('Y-m-d H:i:s') !== $local) {
                    throw new RuntimeException('invalid_local_timestamp');
                }
                if ($time->subHour()->format('Y-m-d H:i:s') === $local || $time->addHour()->format('Y-m-d H:i:s') === $local) {
                    throw new RuntimeException('ambiguous_local_timestamp');
                }
                // Device 1 evidence: punch 0 is normally entry, 1 is normally exit.
                // Other device modes stay unknown for explicit HR review.
                // Preserve the original device code unchanged in raw_payload.
                $punchCode = (string) ($event['punch'] ?? '');
                $direction = match ($punchCode) {
                    '0' => 'in',
                    '1' => 'out',
                    default => 'unknown',
                };
                $rows[] = [
                    'employee_id' => $employeeId, 'device_id' => $device->id, 'source' => 'device', 'direction' => $direction,
                    'occurred_at' => $time->utc()->format('Y-m-d H:i:sP'), 'source_key' => $key, 'raw_payload' => $json,
                    'raw_user_id' => $event['uid'], 'device_local_time' => $event['timestamp'], 'device_timezone' => $device->timezone,
                    'occurrence' => $occurrence, 'sync_run_id' => $runId, 'match_status' => $employeeId ? 'matched' : 'unmatched',
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $counts['inserted']++;
            }
            if (! $dryRun) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('attendance_punches')->insert($chunk);
                }
            }

            return $counts;
        });
    }
}
