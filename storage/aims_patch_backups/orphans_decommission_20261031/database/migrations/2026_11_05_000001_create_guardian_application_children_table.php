<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-child verification for guardian applications (AIMS-ORP-SRS-002 §5.4–5.10, §6).
 *
 * Each verification run stores one row per child with its own result, so one child in conflict
 * never blocks the others. Kinship and legal authority are kept apart: kinship alone never grants access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardian_application_children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guardian_application_id')->constrained('guardian_applications')->restrictOnDelete();
            $table->unsignedInteger('verification_revision');
            $table->string('source', 80);
            $table->string('source_child_id', 120);
            $table->text('full_name');
            $table->date('date_of_birth');
            $table->text('national_id')->nullable();
            $table->string('national_id_hash', 64)->nullable();
            $table->string('kinship', 40)->default('unknown');
            $table->string('authority_kind', 40)->default('none');
            $table->text('authority_reference')->nullable();
            $table->date('authority_valid_until')->nullable();
            $table->string('result', 20);
            $table->json('reasons')->nullable();
            $table->foreignId('orphan_child_id')->nullable()->constrained('orphan_children')->restrictOnDelete();
            $table->boolean('linked')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['guardian_application_id', 'verification_revision', 'source_child_id'], 'guardian_app_child_revision_unique');
            $table->index(['result', 'created_at']);
        });

        Schema::table('orphan_guardianships', function (Blueprint $table): void {
            $table->foreignId('guardian_application_child_id')->nullable()->after('guardian_application_id')->constrained('guardian_application_children')->restrictOnDelete();
            $table->string('kinship', 40)->nullable()->after('approved_by');
            $table->string('authority_kind', 40)->nullable()->after('kinship');
            $table->string('ended_reason', 200)->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Guardian verification history must be preserved; use a reviewed forward migration.');
    }
};
