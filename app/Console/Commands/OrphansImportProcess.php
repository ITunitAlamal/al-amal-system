<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceDeviceBackoff;
use App\Services\Orphans\Import\ImportPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Continues large smart-import batches (analysis or execution) in bounded, resumable steps.
 *
 * An approved execution whose step fails outside the per-family units (a database outage, a chunk-level query) stays
 * «executing» with its error set. It is not retried again in the same run: the next attempt waits 1, 2, 4… up to 15
 * minutes (the attendance device back-off), and the command reports FAILURE while any batch is stopped, so the
 * scheduler heartbeat, the readiness check and the system admin board show it (OB-02).
 */
final class OrphansImportProcess extends Command
{
    protected $signature = 'orphans:import-process {--seconds=50}';

    protected $description = 'Continue smart-import analysis/execution in resumable chunks';

    /** One file pass runs until the time budget (each pass reopens the workbook), committing every 200 rows. */
    private const ROWS_PER_PASS = 100000;

    private const RETRY_KEY = 'aims:orphans-import:retry:';

    public function handle(ImportPipeline $pipeline): int
    {
        $deadline = microtime(true) + (int) $this->option('seconds');
        $stopped = 0;
        foreach (DB::table('import_batches')->whereIn('status', ['analyzing', 'executing'])->orderBy('id')->pluck('id') as $id) {
            $retry = Cache::get(self::RETRY_KEY.$id);
            if (is_array($retry) && ($retry['at'] ?? 0) > time() && DB::table('import_batches')->where('id', $id)->whereNotNull('error')->exists()) {
                $stopped++;

                continue;
            }
            while (microtime(true) < $deadline) {
                $batch = DB::table('import_batches')->find($id);
                // true = batch moved on, null = another worker (e.g. the web request) holds it: leave it to that worker.
                if (! in_array($batch->status, ['analyzing', 'executing'], true) || $pipeline->work($batch, self::ROWS_PER_PASS, (int) max(5, $deadline - microtime(true))) !== false) {
                    break;
                }
                $state = DB::table('import_batches')->where('id', $id)->first(['status', 'error']);
                if ($state->status === 'failed') {
                    $stopped++;
                    break;
                }
                if ($state->status === 'executing' && $state->error !== null) {
                    $failures = (int) (is_array($retry) ? ($retry['failures'] ?? 0) : 0) + 1;
                    Cache::put(self::RETRY_KEY.$id, ['failures' => $failures, 'at' => time() + AttendanceDeviceBackoff::delay($failures, 60)], now()->addDay());
                    $this->error("Import batch {$id} stopped: ".$state->error);
                    $stopped++;
                    break;
                }
            }
            if (DB::table('import_batches')->where('id', $id)->whereNull('error')->exists()) {
                Cache::forget(self::RETRY_KEY.$id);
            }
        }

        return $stopped > 0 ? self::FAILURE : self::SUCCESS;
    }
}
