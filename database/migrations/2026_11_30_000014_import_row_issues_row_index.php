<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The import review page reads each row's findings by row_id; without an index every lookup scanned all findings
 * (≈0.2 s each on 365k rows, ~7 s per page). Built CONCURRENTLY so imports and reviews keep writing meanwhile.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS import_row_issues_row_id_index ON import_row_issues (row_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS import_row_issues_row_id_index');
    }
};
