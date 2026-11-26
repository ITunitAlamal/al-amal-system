<?php

use App\Services\Configuration\NavigationRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staging tier of the orphan registry import («استيراد الكشوف»). Additive only.
 *
 * - One batch per file (unique SHA-256); one row per source row (unique batch + Excel row); one issue per row,
 *   type and reason. Re-running a batch never multiplies rows or issues; a revised file is a new batch that
 *   points to the one it supersedes.
 * - The source sheet is fixed to «جميع المستفيدين» by a CHECK constraint.
 * - Every staged row is a provisional source claim: review_status starts at pending_review, and the identity,
 *   household and guardianship decisions are separate. Nothing here creates or changes an approved orphan,
 *   parent, household, guardianship, portal account, sponsorship or payment.
 * - Source evidence (fingerprint, raw payload, row number) cannot be rewritten (trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orphan_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('status', 20)->default('uploaded');
            $table->string('source_sheet', 60)->default('جميع المستفيدين');
            $table->string('file_name', 255);
            $table->char('file_sha256', 64)->unique();
            $table->unsignedBigInteger('file_size');
            $table->string('storage_path', 255);
            $table->foreignId('supersedes_id')->nullable()->constrained('orphan_import_batches')->restrictOnDelete();
            $table->jsonb('header_map')->nullable();
            $table->jsonb('blockers')->nullable();
            $table->jsonb('profile')->nullable();
            $table->unsignedInteger('data_rows')->nullable();
            $table->unsignedInteger('staged_rows')->default(0);
            $table->unsignedInteger('cursor_row')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('analyzed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE orphan_import_batches ADD CONSTRAINT orphan_import_batches_status_check CHECK (status IN ('uploaded','analyzing','analyzed','blocked','approved','staging','staged','failed','cancelled'))");
        DB::statement("ALTER TABLE orphan_import_batches ADD CONSTRAINT orphan_import_batches_sheet_check CHECK (source_sheet = 'جميع المستفيدين')");
        DB::statement('ALTER TABLE orphan_import_batches ADD CONSTRAINT orphan_import_batches_approver_check CHECK (approved_by IS NULL OR approved_by <> uploaded_by)');

        Schema::create('orphan_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('orphan_import_batches')->restrictOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->char('fingerprint', 64);
            $table->text('raw_payload');
            $table->jsonb('parse_flags')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->string('child_name', 255)->nullable();
            $table->string('child_name_search', 255)->nullable();
            $table->string('child_national_id', 40)->nullable();
            $table->string('child_id_status', 20);
            $table->string('child_sex', 10)->nullable();
            $table->date('child_dob')->nullable();
            $table->string('father_name', 255)->nullable();
            $table->string('father_name_search', 255)->nullable();
            $table->string('father_national_id', 40)->nullable();
            $table->string('father_id_status', 20);
            $table->date('father_death_date')->nullable();
            $table->string('mother_name', 255)->nullable();
            $table->string('mother_name_search', 255)->nullable();
            $table->string('mother_national_id', 40)->nullable();
            $table->string('mother_id_status', 20);
            $table->string('mother_status_key', 120)->nullable();
            $table->string('guardian_name', 255)->nullable();
            $table->string('guardian_name_search', 255)->nullable();
            $table->string('guardian_national_id', 40)->nullable();
            $table->string('guardian_id_status', 20);
            $table->string('guardian_relation_key', 120)->nullable();
            $table->boolean('guardian_claims_mother')->default(false);
            $table->string('governorate_key', 120)->nullable();
            $table->string('city_key', 160)->nullable();
            $table->boolean('is_displaced')->nullable();
            $table->string('school_grade_key', 120)->nullable();
            $table->string('academic_performance_key', 255)->nullable();
            $table->string('health_status_key', 255)->nullable();
            $table->string('mother_education_key', 160)->nullable();
            $table->unsignedSmallInteger('family_total')->nullable();
            $table->unsignedSmallInteger('family_males')->nullable();
            $table->unsignedSmallInteger('family_females')->nullable();
            $table->string('child_key', 40)->nullable();
            $table->string('household_key', 90)->nullable();
            $table->string('guardian_key', 40)->nullable();
            $table->unsignedSmallInteger('issue_count')->default(0);
            $table->string('max_severity', 10)->nullable();
            $table->string('review_status', 20)->default('pending_review');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            foreach (['identity', 'household', 'guardianship'] as $decision) {
                $table->string($decision.'_decision', 20)->nullable();
                $table->text($decision.'_note')->nullable();
                $table->foreignId($decision.'_decided_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp($decision.'_decided_at')->nullable();
            }
            $table->timestamps();
            $table->unique(['batch_id', 'source_row_number']);
            $table->index(['batch_id', 'child_key']);
            $table->index(['batch_id', 'household_key']);
            $table->index(['batch_id', 'guardian_key']);
            $table->index(['batch_id', 'review_status', 'max_severity']);
            $table->index(['batch_id', 'governorate_key']);
            $table->index('assigned_to');
            $table->index('child_national_id');
        });
        DB::statement('CREATE INDEX orphan_import_rows_child_search_prefix ON orphan_import_rows (batch_id, child_name_search varchar_pattern_ops)');
        DB::statement('CREATE INDEX orphan_import_rows_guardian_search_prefix ON orphan_import_rows (batch_id, guardian_name_search varchar_pattern_ops)');
        DB::statement("ALTER TABLE orphan_import_rows ADD CONSTRAINT orphan_import_rows_review_check CHECK (review_status IN ('pending_review','in_review','needs_followup','reviewed'))");
        foreach (['identity', 'household', 'guardianship'] as $decision) {
            DB::statement("ALTER TABLE orphan_import_rows ADD CONSTRAINT orphan_import_rows_{$decision}_check CHECK ({$decision}_decision IS NULL OR ({$decision}_decision IN ('supported','rejected','needs_evidence') AND {$decision}_decided_by IS NOT NULL AND length(coalesce({$decision}_note, '')) >= 10))");
        }
        DB::statement("ALTER TABLE orphan_import_rows ADD CONSTRAINT orphan_import_rows_sex_check CHECK (child_sex IS NULL OR child_sex IN ('male','female'))");

        Schema::create('orphan_import_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('orphan_import_batches')->restrictOnDelete();
            $table->foreignId('row_id')->constrained('orphan_import_rows')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('reason', 60)->default('');
            $table->string('severity', 10);
            $table->jsonb('details')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['row_id', 'type', 'reason']);
            $table->index(['batch_id', 'status', 'severity', 'type']);
            $table->index(['assigned_to', 'status']);
        });
        DB::statement("ALTER TABLE orphan_import_issues ADD CONSTRAINT orphan_import_issues_status_check CHECK (status IN ('open','follow_up','resolved','dismissed'))");
        DB::statement("ALTER TABLE orphan_import_issues ADD CONSTRAINT orphan_import_issues_severity_check CHECK (severity IN ('high','medium','low','info'))");
        DB::statement("ALTER TABLE orphan_import_issues ADD CONSTRAINT orphan_import_issues_resolution_check CHECK (status NOT IN ('resolved','dismissed') OR (resolved_by IS NOT NULL AND length(coalesce(resolution_note, '')) >= 10))");

        Schema::create('orphan_import_aliases', function (Blueprint $table): void {
            $table->id();
            $table->string('field_key', 40);
            $table->string('source_key', 255);
            $table->foreignId('master_data_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['field_key', 'source_key']);
        });

        // Source evidence is immutable once staged.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION orphan_import_rows_evidence_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.raw_payload IS DISTINCT FROM OLD.raw_payload OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
                   OR NEW.source_row_number IS DISTINCT FROM OLD.source_row_number OR NEW.batch_id IS DISTINCT FROM OLD.batch_id THEN
                    RAISE EXCEPTION 'orphan_import_rows: source evidence is immutable';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER orphan_import_rows_evidence BEFORE UPDATE ON orphan_import_rows FOR EACH ROW EXECUTE FUNCTION orphan_import_rows_evidence_guard();
        SQL);

        app(NavigationRegistry::class)->apply(null);
    }

    public function down(): void
    {
        // Staged data is kept: the tables are dropped only while empty.
        if (DB::table('orphan_import_rows')->exists() || DB::table('orphan_import_batches')->exists()) {
            throw new RuntimeException('orphan_import tables hold staged data; purge the batches (audited) before rolling back.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS orphan_import_rows_evidence ON orphan_import_rows; DROP FUNCTION IF EXISTS orphan_import_rows_evidence_guard();');
        Schema::dropIfExists('orphan_import_aliases');
        Schema::dropIfExists('orphan_import_issues');
        Schema::dropIfExists('orphan_import_rows');
        Schema::dropIfExists('orphan_import_batches');
        DB::table('navigation_items')->where('code', 'orphans_imports')->delete();
    }
};
