<?php

namespace App\Console\Commands;

use App\Services\Orphans\Import\ImportPipeline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Continues large smart-import batches (analysis or execution) in bounded, resumable steps. */
final class OrphansImportProcess extends Command
{
    protected $signature = 'orphans:import-process {--seconds=50}';

    protected $description = 'Continue smart-import analysis/execution in resumable chunks';

    /** One file pass runs until the time budget (each pass reopens the workbook), committing every 200 rows. */
    private const ROWS_PER_PASS = 100000;

    public function handle(ImportPipeline $pipeline): int
    {
        $deadline = microtime(true) + (int) $this->option('seconds');
        foreach (DB::table('import_batches')->whereIn('status', ['analyzing', 'executing'])->orderBy('id')->pluck('id') as $id) {
            while (microtime(true) < $deadline) {
                $batch = DB::table('import_batches')->find($id);
                // true = batch moved on, null = another worker (e.g. the web request) holds it: leave it to that worker.
                if (! in_array($batch->status, ['analyzing', 'executing'], true) || $pipeline->work($batch, self::ROWS_PER_PASS, (int) max(5, $deadline - microtime(true))) !== false) {
                    break;
                }
                if (DB::table('import_batches')->where('id', $id)->value('status') === 'failed') {
                    break;
                }
            }
        }

        return self::SUCCESS;
    }
}
