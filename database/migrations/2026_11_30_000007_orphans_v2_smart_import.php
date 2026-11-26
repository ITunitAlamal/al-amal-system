<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 7 — «الاستيراد الذكي» (smart import) and audited exports.
 *
 * A batch walks: uploaded → mapped → analyzing → reviewing → approved → executing → completed (or cancelled).
 * Rows are staged with their original cells and normalized fields; nothing reaches the registry before an explicit
 * approval, and execution is idempotent per row (one execution event per batch row). Imported relationships are
 * claims, campaign sheets become drafts or change requests, delivery/donor lists only produce reconciliation reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_profiles', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 150);
            $t->string('purpose', 30);
            $t->jsonb('mapping');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->string('purpose', 30);
            $t->string('status', 15)->default('uploaded');
            $t->string('original_name', 255);
            $t->string('path', 500);
            $t->string('mime', 120);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->string('format', 5);
            $t->string('sheet', 150)->nullable();
            $t->string('encoding', 20)->nullable();
            $t->unsignedInteger('header_row')->default(1);
            $t->jsonb('headers')->nullable();
            $t->jsonb('mapping')->nullable();
            $t->foreignId('mapping_profile_id')->nullable()->constrained('import_mapping_profiles')->restrictOnDelete();
            $t->foreignId('campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $t->jsonb('summary')->nullable();
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_processed')->default(0);
            $t->unsignedInteger('cursor')->default(0);
            $t->text('error')->nullable();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_purpose CHECK (purpose IN ('registry','campaign_candidates','delivery_reconciliation','donor_recipients'))");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_status CHECK (status IN ('uploaded','mapped','analyzing','reviewing','approved','executing','completed','cancelled','failed'))");
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_format CHECK (format IN ('xlsx','csv'))");
        // The same file twice is detected (not blocked) by its checksum.
        DB::statement('CREATE INDEX import_batches_sha ON import_batches (sha256)');

        Schema::create('import_staged_rows', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->constrained('import_batches')->restrictOnDelete();
            $t->unsignedInteger('row_no');
            $t->string('row_hash', 64);
            $t->text('original');
            $t->jsonb('normalized');
            $t->jsonb('matches')->nullable();
            $t->string('category', 15);
            $t->string('proposed_action', 15)->nullable();
            $t->jsonb('diff')->nullable();
            $t->string('group_key', 64)->nullable();
            $t->string('decision', 15)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->boolean('corrected')->default(false);
            $t->timestamps();
            $t->unique(['batch_id', 'row_no']);
            $t->index(['batch_id', 'category']);
            $t->index(['batch_id', 'group_key']);
        });
        DB::statement("ALTER TABLE import_staged_rows ADD CONSTRAINT import_staged_rows_category CHECK (category IN ('ready','needs_review','conflict','duplicate','rejected'))");
        DB::statement("ALTER TABLE import_staged_rows ADD CONSTRAINT import_staged_rows_decision CHECK (decision IS NULL OR decision IN ('accept','skip'))");

        Schema::create('import_row_issues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('row_id')->constrained('import_staged_rows')->cascadeOnDelete();
            $t->foreignId('batch_id')->constrained('import_batches')->restrictOnDelete();
            $t->string('code', 40);
            $t->string('severity', 10);
            $t->string('field', 60)->nullable();
            $t->string('message', 500);
            $t->string('resolution', 15)->default('open');
            $t->index(['batch_id', 'code']);
        });
        DB::statement("ALTER TABLE import_row_issues ADD CONSTRAINT import_row_issues_severity CHECK (severity IN ('error','conflict','warning'))");

        Schema::create('import_execution_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('batch_id')->constrained('import_batches')->restrictOnDelete();
            $t->foreignId('row_id')->constrained('import_staged_rows')->restrictOnDelete();
            $t->string('outcome', 12);
            $t->jsonb('entities')->nullable();
            $t->text('message')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            // Idempotency: a row is executed at most once, whatever retries happen.
            $t->unique(['batch_id', 'row_id']);
        });
        DB::statement("ALTER TABLE import_execution_events ADD CONSTRAINT import_execution_events_outcome CHECK (outcome IN ('created','updated','skipped','failed','proposed'))");

        Schema::create('orphan_export_log', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('report', 60);
            $t->string('format', 5);
            $t->jsonb('filters')->nullable();
            $t->unsignedInteger('rows');
            $t->boolean('personal_data')->default(false);
            $t->string('purpose', 300)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at');
        });

        DB::statement('CREATE SEQUENCE import_batch_seq START 1');
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: import and export audit trails are records.');
    }
};
