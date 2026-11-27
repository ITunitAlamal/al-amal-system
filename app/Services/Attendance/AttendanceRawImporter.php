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

    /**
     * The instants a device wall time can stand for in its time zone, earliest first: one normally, two around a
     * daylight-saving change (the repeated hour read with either offset, or the skipped hour read with the offset
     * before or after the change). ingest() stores the earliest one and holds the punch as "time_review".
     *
     * @return list<CarbonImmutable> in UTC
     */
    public static function timeReadings(string $local, string $timezone): array
    {
        $wall = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $local) ? CarbonImmutable::createFromFormat('!Y-m-d H:i:s', substr($local, 0, 19), 'UTC') : null;
        if (! $wall || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return [];
        }
        $zone = new \DateTimeZone($timezone);
        $readings = [];
        // The offsets in force a few hours either side of the wall time (no zone changes twice within six hours).
        foreach ([-3, 3] as $hours) {
            $instant = $wall->subSeconds($zone->getOffset($wall->addHours($hours)->toDateTime()));
            $readings[$instant->getTimestamp()] = $instant;
        }
        ksort($readings);

        return array_values($readings);
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
                // A malformed record is counted and skipped: one bad record must not block every later synchronization.
                if (! is_array($event) || ! is_string($event['uid'] ?? null) || $event['uid'] === '' || strlen($event['uid']) > 100
                    || ! is_string($event['timestamp'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $event['timestamp'])) {
                    $counts['failed']++;

                    continue;
                }
                $raw = array_intersect_key($event, array_flip(['uid', 'timestamp', 'status', 'punch', 'type', 'device_event_id']));
                ksort($raw);
                foreach (['status', 'punch', 'type', 'device_event_id'] as $field) {
                    if (isset($raw[$field]) && ! is_int($raw[$field]) && ! is_string($raw[$field])) {
                        $counts['failed']++;

                        continue 2;
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
                if (! $time) {
                    $counts['failed']++;

                    continue;
                }
                // A wall time skipped or repeated by a daylight-saving change has no single instant: keep the record
                // (earliest reading) but hold it for HR review instead of counting it or blocking the synchronization.
                $timeAmbiguous = $time->format('Y-m-d H:i:s') !== $local
                    || $time->subHour()->format('Y-m-d H:i:s') === $local || $time->addHour()->format('Y-m-d H:i:s') === $local;
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
                    'occurrence' => $occurrence, 'sync_run_id' => $runId,
                    'match_status' => $timeAmbiguous ? 'time_review' : ($employeeId ? 'matched' : 'unmatched'),
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $counts['inserted']++;
            }
            if (! $dryRun) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    // ON CONFLICT (source_key) DO NOTHING: a repeated or concurrent read never duplicates a punch.
                    DB::table('attendance_punches')->insertOrIgnore($chunk);
                }
            }

            return $counts;
        });
    }
}
