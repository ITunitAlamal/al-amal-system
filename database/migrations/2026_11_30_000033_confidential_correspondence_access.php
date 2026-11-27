<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Confidential correspondence (owner decision 3, 2026-11-27): the system administrator no longer sees records
 * classified other than «داخلي» (today «سري» and «مقيد»). Exceptional access to one such record is an official,
 * specific, time-bound grant issued by the executive director on the record's page: one record, one person, a
 * reason and an end date, revocable. App\Services\Correspondence\ConfidentialGrants issues, revokes and audits it;
 * CorrespondenceAccess honours it for reading only (it never makes anyone a holder, sender or recipient).
 *
 * Structure only, no business rows. Safe to run twice. down() keeps the table: grants are part of the record's
 * access history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('correspondence_confidential_grants')) {
            Schema::create('correspondence_confidential_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('correspondence_id')->constrained('official_correspondences')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
                $table->text('reason');
                $table->timestamp('ends_at');
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('revoke_reason')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'correspondence_id']);
                $table->index('correspondence_id');
            });
        }
        DB::statement('ALTER TABLE correspondence_confidential_grants DROP CONSTRAINT IF EXISTS correspondence_confidential_grants_rules');
        DB::statement('ALTER TABLE correspondence_confidential_grants ADD CONSTRAINT correspondence_confidential_grants_rules CHECK (
            user_id <> issued_by AND length(btrim(reason)) >= 5 AND ends_at > created_at
            AND ((revoked_at IS NULL AND revoked_by IS NULL) OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL)))');
    }

    public function down(): void
    {
        throw new RuntimeException('Confidential access grants are part of the correspondence access history and are kept.');
    }
};
