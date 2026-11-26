<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDailyService;
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
        $failed = false;
        foreach ($devices as $device) {
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
            try {
                $dryRun = (bool) $this->option('dry-run') || ! $device->import_approved_at || $device->sync_request_mode === 'preview';
                $digest = $importer->configurationDigest($device);
                $runId = DB::table('attendance_sync_runs')->insertGetId([
                    'device_id' => $device->id, 'status' => 'running', 'dry_run' => $dryRun, 'configuration_digest' => $digest,
                    'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
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
                DB::table('attendance_sync_runs')->where('id', $runId)->update(['received' => count($payload['events'])]);
                $counts = $importer->ingest($device, $payload['events'], $runId, $dryRun);
                DB::transaction(function () use ($runId, $counts, $dryRun, $device): void {
                    DB::table('attendance_sync_runs')->where('id', $runId)->update($counts + [
                        'status' => 'success', 'ended_at' => now(), 'updated_at' => now(),
                    ]);
                    $updates = ['last_error' => null, 'updated_at' => now()];
                    if (! $dryRun) {
                        $updates += ['last_synced_at' => now(), 'last_sync_success_at' => now()];
                    }
                    DB::table('attendance_devices')->where('id', $device->id)->update($updates);
                    DB::table('attendance_devices')->where('id', $device->id)->where('sync_requested_at', $device->sync_requested_at)
                        ->update(['sync_requested_at' => null, 'sync_request_mode' => null]);
                });
                if (! $dryRun) {
                    $disk->delete($path);
                }
                $recalculated = ! $dryRun && $counts['inserted'] > 0 ? $this->recalculateImportedDays($runId, $daily) : 0;
                $this->info('Device '.$device->id.' '.($dryRun ? 'PREVIEW ' : 'RAW ').json_encode($counts + ['recalculated_days' => $recalculated]));
            } catch (Throwable $exception) {
                $failed = true;
                $safeCodes = ['host_not_approved', 'probe_timeout', 'pyzk_unavailable', 'reader_failed', 'invalid_snapshot_event', 'invalid_local_timestamp', 'ambiguous_local_timestamp', 'invalid_snapshot_configuration', 'import_not_authorized', 'retry_buffer_unavailable'];
                $code = in_array($exception->getMessage(), $safeCodes, true) ? $exception->getMessage() : 'sync_failed';
                try {
                    if ($runId) {
                        DB::table('attendance_sync_runs')->where('id', $runId)->update(['status' => 'failed', 'failed' => count($payload['events'] ?? []), 'error' => $code, 'ended_at' => now(), 'updated_at' => now()]);
                    } else {
                        DB::table('attendance_sync_runs')->insert(['device_id' => $device->id, 'status' => 'failed', 'failed' => count($payload['events'] ?? []), 'error' => $code, 'started_at' => now(), 'ended_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('attendance_devices')->where('id', $device->id)->update(['last_error' => $code, 'updated_at' => now()]);
                } catch (Throwable) {
                    // A persisted encrypted snapshot remains available for retry after a database outage.
                }
                $this->error("Device {$device->id}: {$code}; retry retained.");
            } finally {
                $lock->release();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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
