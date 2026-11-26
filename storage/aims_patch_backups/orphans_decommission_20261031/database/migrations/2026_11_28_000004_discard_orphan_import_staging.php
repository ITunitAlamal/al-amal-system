<?php

use App\Services\AuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Owner decision 2026-10-30: discard the unapproved spreadsheet staging («جميع المستفيدين», 48,518 rows) without a
 * backup; it can be re-imported from the original workbook. Only the four staging tables and the private stored
 * copy of the upload are emptied — no official, financial, portal or audit record is touched. The tables
 * themselves stay for a later re-import. Not reversible.
 */
return new class extends Migration
{
    private const TABLES = ['orphan_import_issues', 'orphan_import_rows', 'orphan_import_batches', 'orphan_import_aliases'];

    public function up(): void
    {
        $counts = collect(self::TABLES)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();
        $files = DB::table('orphan_import_batches')->pluck('storage_path')->filter()->values();
        // TRUNCATE bypasses the row trigger that keeps staged evidence immutable; the four tables reference only each other.
        DB::statement('TRUNCATE '.implode(', ', self::TABLES).' RESTART IDENTITY');
        foreach ($files as $path) {
            Storage::disk('local')->delete($path);
        }
        app(AuditService::class)->recordRow('orphans.import.staging_discarded', 'orphan_import_batches', 0, $counts, ['files_deleted' => $files->count(), 'decision' => 'owner 2026-10-30, no backup'], null, null);
    }

    public function down(): void
    {
        // Nothing to restore: re-import the original workbook from «استيراد الكشوف».
    }
};
