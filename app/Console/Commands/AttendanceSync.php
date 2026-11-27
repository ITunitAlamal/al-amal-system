<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDailyService;
use App\Services\Attendance\AttendanceDeviceBackoff;
use App\Services\Attendance\AttendanceDeviceReader;
use App\Services\Attendance\AttendanceRawImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AttendanceSync extends Command
{
    protected $signature = 'attendance:sync {--device= : Registered device ID} {--dry-run : Count and match without inserting punches}';

    protected $description = 'Read immutable raw attendance; first import requires an operator-approved preview';

    public function handle(AttendanceDeviceReader $reader, AttendanceRawImporter $importer, AttendanceDailyService $daily): int
    {
        if (! config('attendance.zk_sync_enabled')) {
            $this->warn('Attendance synchronization is disabled.');

            return self::SUCCESS;
        }
        if (! Schema::hasColumn('attendance_punches', 'raw_payload')) {
            $this->error('Safe raw import migration is required; legacy import is blocked.');

            return self::FAILURE;
        }
        $devices = DB::table('attendance_devices')->where('is_active', true)->where('sync_mode', 'pull')
            ->where('assigned_agent', config('attendance.agent_id', 'local'))
            ->when($this->option('device'), fn ($q) => $q->where('id', (int) $this->option('device')))->orderBy('id')->get();
        $backoff = AttendanceDeviceBackoff::ready();
        $failed = false;
        foreach ($devices as $device) {
            // After a failure the device waits (AttendanceDeviceBackoff) instead of being retried every minute. A "sync now"
            // made after the last failure, or an operator's --device run, still goes at once; an older request waits its turn.
            $freshRequest = $device->sync_requested_at && (! $backoff || ! $device->sync_failed_at
                || CarbonImmutable::parse($device->sync_requested_at)->greaterThan(CarbonImmutable::parse($device->sync_failed_at)));
            if (! $this->option('device') && $backoff && $device->sync_retry_at && ! $freshRequest && now()->lessThan(CarbonImmutable::parse($device->sync_retry_at))) {
                continue;
            }
            if (! $this->option('device') && ! $device->sync_requested_at &&
                (! $device->import_approved_at || ($device->last_synced_at && now()->diffInSeconds($device->last_synced_at, true) < $device->sync_interval_seconds))) {
                continue;
            }
            $lock = Cache::lock('attendance-device-io:'.$device->id, 180);
            if (! $lock->get()) {
                $this->warn("Device {$device->id}: busy; request retained.");

                continue;
            }
            $runId = null;
            $payload = null;
            $dryRun = false;
            // A streak of failures with the same error is one run row whose attempts grow (see the catch below), so a
            // retry opens its own row only once the device answered.
            $streakRun = $backoff && (int) $device->sync_failure_count > 0
                ? DB::table('attendance_sync_runs')->where('device_id', $device->id)->orderByDesc('id')->first(['id', 'status', 'error', 'dry_run'])
                : null;
            $streakRun = $streakRun?->status === 'failed' ? $streakRun : null;
            try {
                $dryRun = (bool) $this->option('dry-run') || ! $device->import_approved_at || $device->sync_request_mode === 'preview';
                $digest = $importer->configurationDigest($device);
                $openRun = fn (): int => DB::table('attendance_sync_runs')->insertGetId([
                    'device_id' => $device->id, 'status' => 'running', 'dry_run' => $dryRun, 'configuration_digest' => $digest,
                    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $runId = $streakRun ? null : $openRun();
                $disk = Storage::disk('local');
                $path = 'attendance-retry/'.$device->id.'-'.$digest.'.encrypted';
                if ($disk->exists($path)) {
                    $payload = json_decode(Crypt::decryptString($disk->get($path)), true, 512, JSON_THROW_ON_ERROR);
                } else {
                    $payload = $reader->read($device);
                    $encrypted = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
                    if (! $disk->put($path.'.tmp', $encrypted) || ! $disk->move($path.'.tmp', $path)) {
                        throw new \RuntimeException('retry_buffer_unavailable');
                    }
                }
                $runId ??= $openRun();
                DB::table('attendance_sync_runs')->where('id', $runId)->update(['received' => count($payload['events'])]);
                $counts = $importer->ingest($device, $payload['events'], $runId, $dryRun);
                DB::transaction(function () use ($runId, $counts, $dryRun, $device, $backoff): void {
                    DB::table('attendance_sync_runs')->where('id', $runId)->update($counts + [
                        'status' => 'success', 'ended_at' => now(), 'updated_at' => now(),
                    ]);
                    $updates = ['last_error' => null, 'updated_at' => now()];
                    if (! $dryRun) {
                        $updates += ['last_synced_at' => now(), 'last_sync_success_at' => now()];
                    }
                    if ($backoff) {
                        $updates += ['sync_failure_count' => 0, 'sync_failed_at' => null, 'sync_retry_at' => null];
                    }
                    DB::table('attendance_devices')->where('id', $device->id)->update($updates);
                    DB::table('attendance_devices')->where('id', $device->id)->where('sync_requested_at', $device->sync_requested_at)
                        ->update(['sync_requested_at' => null, 'sync_request_mode' => null]);
                });
                if ($backoff && (int) $device->sync_failure_count > 0) {
                    Log::info('Attendance device sync recovered', ['device_id' => (int) $device->id, 'failed_attempts' => (int) $device->sync_failure_count]);
                }
                if (! $dryRun) {
                    $disk->delete($path);
                }
                $recalculated = ! $dryRun && $counts['inserted'] > 0 ? $this->recalculateImportedDays($runId, $daily) : 0;
                $this->info('Device '.$device->id.' '.($dryRun ? 'PREVIEW ' : 'RAW ').json_encode($counts + ['recalculated_days' => $recalculated]));
            } catch (Throwable $exception) {
                $failed = true;
                $safeCodes = ['host_not_approved', 'probe_timeout', 'pyzk_unavailable', 'reader_failed', 'invalid_snapshot_event', 'invalid_local_timestamp', 'ambiguous_local_timestamp', 'invalid_snapshot_configuration', 'import_not_authorized', 'retry_buffer_unavailable'];
                $code = in_array($exception->getMessage(), $safeCodes, true) ? $exception->getMessage() : 'sync_failed';
                $failures = (int) ($device->sync_failure_count ?? 0) + 1;
                $retryIn = AttendanceDeviceBackoff::delay($failures, 60);
                try {
                    if ($runId) {
                        DB::table('attendance_sync_runs')->where('id', $runId)->update(['status' => 'failed', 'failed' => count($payload['events'] ?? []), 'error' => $code, 'ended_at' => now(), 'updated_at' => now()]);
                    } elseif ($streakRun && $streakRun->error === $code && (bool) $streakRun->dry_run === $dryRun) {
                        DB::table('attendance_sync_runs')->where('id', $streakRun->id)->update(['attempts' => DB::raw('attempts + 1'), 'ended_at' => now(), 'updated_at' => now()]);
                    } else {
                        DB::table('attendance_sync_runs')->insert(['device_id' => $device->id, 'status' => 'failed', 'dry_run' => $dryRun, 'failed' => count($payload['events'] ?? []), 'error' => $code, 'started_at' => now(), 'ended_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('attendance_devices')->where('id', $device->id)->update(['last_error' => $code, 'updated_at' => now()]
                        + ($backoff ? ['sync_failure_count' => $failures, 'sync_failed_at' => now(), 'sync_retry_at' => now()->addSeconds($retryIn)] : []));
                } catch (Throwable) {
                    // A persisted encrypted snapshot remains available for retry after a database outage.
                }
                // One log line when the device starts failing or its error changes, not one per attempt.
                if (! $backoff || $failures === 1 || $device->last_error !== $code) {
                    Log::warning('Attendance device sync failing', ['device_id' => (int) $device->id, 'code' => $code, 'retry_in_seconds' => $retryIn]
                        + ($code === 'sync_failed' ? ['exception' => $exception] : []));
                }
                $this->warn("Device {$device->id}: {$code}; retry retained".($backoff ? " in {$retryIn}s" : '').'.');
            } finally {
                $lock->release();
            }
        }

        // A device that cannot be reached is recorded on the device and its run, and logged once above; failing the
        // scheduled command as well would log a stack trace every minute. An operator's --device run still reports it.
        return $failed && $this->option('device') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Incremental update: only the employee days touched by the punches of this run (and the day before, for
     * overnight shifts). A failure here never undoes the import; the scheduled recalculation catches up.
     */
    private function recalculateImportedDays(int $runId, AttendanceDailyService $daily): int
    {
        $tz = config('app.timezone');
        $days = [];
        foreach (DB::table('attendance_punches')->where('sync_run_id', $runId)->whereNotNull('employee_id')
            ->whereIn('match_status', ['matched', 'reviewed'])->get(['employee_id', 'occurred_at']) as $punch) {
            $local = CarbonImmutable::parse($punch->occurred_at)->setTimezone($tz);
            $days[$punch->employee_id.'|'.$local->subDay()->toDateString()] = true;
            $days[$punch->employee_id.'|'.$local->toDateString()] = true;
        }
        foreach (array_keys($days) as $key) {
            [$employeeId, $date] = explode('|', $key);
            try {
                $daily->recalculate((int) $employeeId, $date);
            } catch (Throwable $exception) {
                Log::warning('Attendance day recalculation after import failed', ['employee_id' => (int) $employeeId, 'date' => $date, 'reason' => $exception->getMessage()]);
            }
        }

        return count($days);
    }
}
