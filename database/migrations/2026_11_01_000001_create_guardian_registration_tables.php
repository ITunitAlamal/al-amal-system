<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 200);
            $t->text('national_id');
            $t->string('national_id_hash', 64)->unique();
            $t->string('phone', 20)->unique();
            $t->string('password');
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('guardian_applications', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('guardian_id')->constrained('guardians')->restrictOnDelete();
            $t->text('deceased_national_id');
            $t->string('deceased_id_hash', 64);
            $t->string('relationship', 200);
            $t->string('status', 30)->default('awaiting_api');
            $t->string('verification_status', 25)->default('unavailable');
            $t->unsignedInteger('verification_revision')->default(0);
            $t->text('verification_payload')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->unsignedInteger('imported_children_count')->default(0);
            $t->timestamps();
            $t->unique(['guardian_id', 'deceased_id_hash'], 'guardian_deceased_unique');
            $t->index(['status', 'created_at']);
        });
        Schema::create('orphan_children', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('source', 80);
            $t->string('source_child_id', 120);
            $t->text('national_id')->nullable();
            $t->string('national_id_hash', 64)->nullable()->unique();
            $t->text('full_name');
            $t->date('date_of_birth');
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->unique(['source', 'source_child_id'], 'orphan_source_child_unique');
        });
        Schema::create('orphan_guardianships', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guardian_id')->constrained('guardians')->restrictOnDelete();
            $t->foreignId('orphan_child_id')->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('guardian_application_id')->constrained('guardian_applications')->restrictOnDelete();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->timestamps();
            $t->unique(['guardian_id', 'orphan_child_id'], 'orphan_guardian_child_unique');
        });
        Schema::create('orphan_registration_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guardian_application_id')->nullable()->constrained('guardian_applications')->restrictOnDelete();
            $t->foreignId('guardian_id')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->foreignId('staff_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 50);
            $t->text('details_ciphertext')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Guardian and child history must be preserved; use a reviewed forward migration.');
    }
};
