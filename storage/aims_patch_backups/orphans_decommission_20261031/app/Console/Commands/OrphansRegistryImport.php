<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Orphans\Import\RegistryImports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Background worker of «استيراد الكشوف»: analyses uploaded batches (dry run, counts only) and stages approved ones
 * in resumable chunks. Staging writes only the orphan_import_* tables. `--purge` is the privileged, audited rollback
 * of one batch's staged rows.
 */
final class OrphansRegistryImport extends Command
{
    protected $signature = 'orphans:registry-import
        {--batch= : public_id of one batch (default: the next pending job)}
        {--purge= : public_id of a batch whose staged rows are removed (system administrator)}
        {--admin= : user id of the system administrator for --purge}
        {--reason= : reason for --purge}';

    protected $description = 'Analyse uploaded orphan registry batches and stage approved ones (staging tables only)';

    public function handle(RegistryImports $imports): int
    {
        // Until the staging migration is approved and applied there is nothing to do.
        if (! Schema::hasTable('orphan_import_batches')) {
            return self::SUCCESS;
        }
        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        if ($this->option('purge')) {
            $admin = User::find((int) $this->option('admin'));
            if (! $admin) {
                $this->error('--admin is required');

                return self::FAILURE;
            }
            $result = $imports->purge($admin, (string) $this->option('purge'), (string) $this->option('reason'));
            $this->info("Purged staged rows: {$result['rows']}, issues: {$result['issues']}");

            return self::SUCCESS;
        }
        $job = $this->option('batch')
            ? $this->forBatch($imports, (string) $this->option('batch'))
            : $imports->nextJob();
        if (! $job) {
            $this->line('Nothing to do.');

            return self::SUCCESS;
        }
        [$step, $batch] = $job;
        $this->info(ucfirst($step).' batch '.$batch->public_id);
        if ($step === 'analyze') {
            $imports->analyze($batch);
        } else {
            $imports->stage($batch, fn (int $row) => $this->output->isVerbose() ? $this->line("  staged up to row $row") : null);
        }
        $after = DB::table('orphan_import_batches')->find($batch->id);
        $this->info("Status: {$after->status}; staged rows: {$after->staged_rows}".($after->failure ? "; failure: {$after->failure}" : ''));

        return $after->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function forBatch(RegistryImports $imports, string $publicId): ?array
    {
        $batch = DB::table('orphan_import_batches')->where('public_id', $publicId)->first();
        $job = match ($batch?->status) {
            'uploaded' => ['analyze', 'analyzing'],
            'approved' => ['stage', 'staging'],
            default => null,
        };

        return $job && $imports->claim($batch, $job[1]) ? [$job[0], $batch] : null;
    }
}
