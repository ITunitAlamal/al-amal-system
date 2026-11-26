<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Beneficiary portal (portal.al-amal.ps) inside AIMS. Additive only:
 * - guardian accounts: phone verification, forced password change, SMS consent;
 * - one-time codes, hashed and limited, sent only when the real SMS provider is enabled;
 * - in-portal notifications (no personal data in the title);
 * - reviewed data-change requests from beneficiaries (proposed values + before snapshot, encrypted);
 * - campaign publication to the portal (approved lists only; it never approves or edits a list);
 * - wizard resume step and a beneficiary-facing appeal decision text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $t): void {
            $t->timestamp('phone_verified_at')->nullable();
            $t->string('phone_verification_method', 10)->nullable();
            $t->foreignId('phone_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('sms_consent_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
        });
        DB::statement("ALTER TABLE guardians ADD CONSTRAINT guardians_phone_method CHECK (phone_verification_method IS NULL OR phone_verification_method IN ('sms','manual'))");

        Schema::create('guardian_otps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guardian_id')->constrained('guardians')->restrictOnDelete();
            $t->string('purpose', 16);
            $t->string('code_hash', 64);
            $t->timestamp('expires_at');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('consumed_at')->nullable();
            $t->timestamp('created_at');
            $t->index(['guardian_id', 'purpose', 'id']);
        });
        DB::statement("ALTER TABLE guardian_otps ADD CONSTRAINT guardian_otps_purpose CHECK (purpose IN ('phone_verify','password_reset'))");

        Schema::create('portal_notifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guardian_id')->constrained('guardians')->restrictOnDelete();
            $t->string('kind', 12);
            $t->string('title', 200);
            $t->string('body', 500)->nullable();
            $t->string('url', 300)->nullable();
            $t->string('dedupe', 120)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
            $t->index(['guardian_id', 'read_at', 'id']);
            $t->unique(['guardian_id', 'dedupe']);
        });
        DB::statement("ALTER TABLE portal_notifications ADD CONSTRAINT portal_notifications_kind CHECK (kind IN ('action','decision','aid','info'))");

        Schema::create('portal_change_requests', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('guardian_account_id')->constrained('guardians')->restrictOnDelete();
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->string('subject', 8);
            $t->string('field_group', 24);
            $t->text('proposed');
            $t->text('current_snapshot')->nullable();
            $t->text('reason')->nullable();
            $t->string('status', 12)->default('submitted');
            $t->text('public_note')->nullable();
            $t->text('internal_note')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->uuid('idempotency_key');
            $t->timestamps();
            $t->unique(['guardian_account_id', 'idempotency_key']);
            $t->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE portal_change_requests ADD CONSTRAINT portal_change_subject CHECK (subject IN ('guardian','child'))");
        DB::statement("ALTER TABLE portal_change_requests ADD CONSTRAINT portal_change_target CHECK ((subject = 'child') = (orphan_id IS NOT NULL))");
        DB::statement("ALTER TABLE portal_change_requests ADD CONSTRAINT portal_change_group CHECK (field_group IN ('phone','current_address','school','household','health','parent_status','guardianship','other'))");
        DB::statement("ALTER TABLE portal_change_requests ADD CONSTRAINT portal_change_status CHECK (status IN ('submitted','under_review','needs_info','approved','rejected'))");
        DB::statement("ALTER TABLE portal_change_requests ADD CONSTRAINT portal_change_decided CHECK (status NOT IN ('approved','rejected') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL AND public_note IS NOT NULL))");
        DB::statement('CREATE SEQUENCE portal_change_seq START 1');

        Schema::table('registry_documents', function (Blueprint $t): void {
            $t->foreignId('change_request_id')->nullable()->constrained('portal_change_requests')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE registry_documents DROP CONSTRAINT registry_documents_owner');
        DB::statement('ALTER TABLE registry_documents ADD CONSTRAINT registry_documents_owner CHECK (num_nonnulls(person_id, guardianship_id, household_id, application_id, change_request_id) >= 1)');

        Schema::table('portal_applications', function (Blueprint $t): void {
            $t->unsignedTinyInteger('last_step')->default(0);
        });

        Schema::table('orphan_appeals', function (Blueprint $t): void {
            $t->uuid('idempotency_key')->nullable();
            $t->unique(['filed_by_guardian', 'idempotency_key']);
        });

        Schema::table('campaigns', function (Blueprint $t): void {
            $t->timestamp('portal_published_at')->nullable();
            $t->foreignId('portal_published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('portal_instructions')->nullable();
        });

        foreach ([['portal_show_sponsorship', true], ['portal_contact_text', null]] as [$key, $value]) {
            DB::table('orphan_settings')->insertOrIgnore(['key' => $key, 'value' => json_encode($value), 'updated_at' => now()]);
        }
        if (! DB::table('notification_settings')->where('event_code', 'orphans_portal_change')->exists()) {
            DB::table('notification_settings')->insert(['event_code' => 'orphans_portal_change', 'label_ar' => 'طلب تعديل بيانات من البوابة', 'description' => 'إشعار المسؤول عند وصول طلب تعديل من وصي.',
                'in_app_enabled' => true, 'sms_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: portal requests and notifications are records.');
    }
};
