<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Smart import 2.0 (AIMS_Smart_Import_2_0_Provisional_Operational_Registry_Final.md). Additive only:
 * - orphans gain the «معتمد مبدئيًا» state (status `provisional`) and the batch they were imported from;
 * - guardian links keep their provenance (row, sibling inference, confidence);
 * - staging gains family keys, inference, indexes for in-file lookups and per-batch entity keys (idempotent reuse);
 * - execution events reference the official records and record every row outcome;
 * - batches distinguish provisionally imported / partially imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orphans DROP CONSTRAINT orphans_status');
        DB::statement("ALTER TABLE orphans ADD CONSTRAINT orphans_status CHECK (status IN ('draft','provisional','active','suspended','archived','ended'))");
        Schema::table('orphans', function (Blueprint $t): void {
            $t->foreignId('import_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $t->index('status');
        });
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->jsonb('provenance')->nullable());

        Schema::table('import_staged_rows', function (Blueprint $t): void {
            $t->string('family_key', 64)->nullable();
            $t->jsonb('inference')->nullable();
            $t->index(['batch_id', 'family_key']);
        });
        DB::statement("CREATE INDEX import_staged_rows_child_id ON import_staged_rows (batch_id, (normalized->>'child_id'))");
        DB::statement("CREATE INDEX import_staged_rows_unit_key ON import_staged_rows (batch_id, (normalized->>'unit_key'))");

        DB::statement('ALTER TABLE import_execution_events ALTER COLUMN outcome TYPE varchar(20)');
        DB::statement('ALTER TABLE import_execution_events DROP CONSTRAINT import_execution_events_outcome');
        DB::statement("ALTER TABLE import_execution_events ADD CONSTRAINT import_execution_events_outcome CHECK (outcome IN ('created','linked','updated','skipped','held_for_review','failed','proposed'))");
        Schema::table('import_execution_events', function (Blueprint $t): void {
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('guardian_id')->nullable()->constrained('orphan_guardians')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE import_batches ALTER COLUMN status TYPE varchar(20)');
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_status');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_status CHECK (status IN ('uploaded','mapped','analyzing','reviewing','approved','executing','partially_imported','imported','completed','cancelled','failed'))");
        Schema::table('import_batches', fn (Blueprint $t) => $t->jsonb('commit_scope')->nullable());

        // People, households and guardians created by a batch, by stable key: a retried unit reuses them, never duplicates.
        Schema::create('import_entity_keys', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->constrained('import_batches')->restrictOnDelete();
            $t->string('entity_key', 80);
            $t->string('entity_type', 20);
            $t->unsignedBigInteger('entity_id');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['batch_id', 'entity_key']);
        });
        DB::statement("ALTER TABLE import_entity_keys ADD CONSTRAINT import_entity_keys_type CHECK (entity_type IN ('person','guardian','household'))");

        DB::table('orphan_settings')->insertOrIgnore(['key' => 'import_policy', 'value' => json_encode([]), 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (DB::table('orphans')->where('status', 'provisional')->orWhereNotNull('import_batch_id')->exists() || DB::table('import_entity_keys')->exists()) {
            throw new RuntimeException('Forward-only: provisional imported records exist.');
        }
        Schema::drop('import_entity_keys');
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropColumn('commit_scope'));
        Schema::table('import_execution_events', fn (Blueprint $t) => $t->dropConstrainedForeignId('orphan_id'));
        Schema::table('import_execution_events', fn (Blueprint $t) => $t->dropConstrainedForeignId('guardian_id'));
        Schema::table('import_execution_events', fn (Blueprint $t) => $t->dropConstrainedForeignId('household_id'));
        DB::statement('DROP INDEX IF EXISTS import_staged_rows_child_id');
        DB::statement('DROP INDEX IF EXISTS import_staged_rows_unit_key');
        Schema::table('import_staged_rows', fn (Blueprint $t) => $t->dropColumn(['family_key', 'inference']));
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->dropColumn('provenance'));
        Schema::table('orphans', fn (Blueprint $t) => $t->dropConstrainedForeignId('import_batch_id'));
        DB::statement('ALTER TABLE orphans DROP CONSTRAINT orphans_status');
        DB::statement("ALTER TABLE orphans ADD CONSTRAINT orphans_status CHECK (status IN ('draft','active','suspended','archived','ended'))");
    }
};
