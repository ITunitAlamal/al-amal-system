<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3 — beneficiary portal applications and «الطلبات والمراجعات».
 *
 * The portal account (`guardians`) gets its own approval state, separate from every child's guardianship. An
 * application carries the applicant's identity snapshot and one claim per child; each claim is decided on its own
 * and, when approved, points to the guardianship it created or approved. Appeals are reviewable records that never
 * edit an approved campaign list by themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardians', function (Blueprint $t): void {
            $t->foreignId('person_id')->nullable()->unique()->constrained('persons')->restrictOnDelete();
            $t->string('account_status', 10)->default('pending');
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
        });
        DB::statement("ALTER TABLE guardians ADD CONSTRAINT guardians_account_status CHECK (account_status IN ('pending','approved','rejected','suspended'))");

        Schema::create('portal_applications', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('guardian_account_id')->constrained('guardians')->restrictOnDelete();
            $t->string('status', 20)->default('submitted');
            $t->string('first_name', 100)->nullable();
            $t->string('father_name', 100)->nullable();
            $t->string('grandfather_name', 100)->nullable();
            $t->string('family_name', 100)->nullable();
            $t->string('full_name', 400);
            $t->text('id_number')->nullable();
            $t->string('id_hash', 64)->nullable();
            $t->string('id_last4', 4)->nullable();
            $t->string('phone', 20)->nullable();
            $t->text('applicant_note')->nullable();
            $t->foreignId('matched_guardian_id')->nullable()->constrained('orphan_guardians')->restrictOnDelete();
            $t->jsonb('checklist')->nullable();
            $t->text('completion_request')->nullable();
            $t->timestamp('completion_requested_at')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->date('due_on')->nullable();
            $t->text('decision_note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('submitted_at');
            $t->timestamps();
            $t->index(['status', 'submitted_at']);
            $t->index('assigned_to');
            $t->index('id_hash');
        });
        DB::statement("ALTER TABLE portal_applications ADD CONSTRAINT portal_applications_status CHECK (status IN ('submitted','under_review','needs_completion','resubmitted','completed','rejected','withdrawn'))");

        Schema::create('portal_application_children', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('portal_applications')->restrictOnDelete();
            $t->string('first_name', 100)->nullable();
            $t->string('father_name', 100)->nullable();
            $t->string('grandfather_name', 100)->nullable();
            $t->string('family_name', 100)->nullable();
            $t->string('full_name', 400);
            $t->text('id_number')->nullable();
            $t->string('id_hash', 64)->nullable();
            $t->string('id_last4', 4)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('sex', 10)->nullable();
            $t->foreignId('kinship_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('relationship_note', 300)->nullable();
            $t->foreignId('matched_orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->string('status', 20)->default('pending');
            $t->text('decision_note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('guardianship_id')->nullable()->constrained('orphan_guardianships')->restrictOnDelete();
            $t->timestamps();
            $t->index('application_id');
            $t->index('id_hash');
        });
        DB::statement("ALTER TABLE portal_application_children ADD CONSTRAINT portal_application_children_status CHECK (status IN ('pending','approved','rejected','needs_completion'))");
        DB::statement("ALTER TABLE portal_application_children ADD CONSTRAINT portal_application_children_approved CHECK (status <> 'approved' OR guardianship_id IS NOT NULL)");
        DB::statement("ALTER TABLE portal_application_children ADD CONSTRAINT portal_application_children_decided CHECK (status NOT IN ('approved','rejected') OR (decided_by IS NOT NULL AND decision_note IS NOT NULL OR status = 'approved'))");

        Schema::create('portal_application_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('portal_applications')->restrictOnDelete();
            $t->foreignId('child_id')->nullable()->constrained('portal_application_children')->restrictOnDelete();
            $t->string('action', 40);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('actor_guardian_id')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->text('note')->nullable();
            $t->jsonb('payload')->nullable();
            $t->timestamp('created_at');
            $t->index(['application_id', 'id']);
        });

        Schema::table('registry_documents', function (Blueprint $t): void {
            $t->foreignId('application_id')->nullable()->constrained('portal_applications')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE registry_documents DROP CONSTRAINT registry_documents_owner');
        DB::statement('ALTER TABLE registry_documents ADD CONSTRAINT registry_documents_owner CHECK (num_nonnulls(person_id, guardianship_id, household_id, application_id) >= 1)');

        Schema::create('orphan_appeals', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->string('kind', 20);
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->foreignId('guardian_id')->nullable()->constrained('orphan_guardians')->restrictOnDelete();
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->string('filed_via', 10)->default('staff');
            $t->foreignId('filed_by_user')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('filed_by_guardian')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->text('reason');
            $t->text('evidence_note')->nullable();
            $t->string('status', 15)->default('open');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'id']);
            $t->index('campaign_id');
        });
        DB::statement("ALTER TABLE orphan_appeals ADD CONSTRAINT orphan_appeals_kind CHECK (kind IN ('eligibility','campaign_omission','other'))");
        DB::statement("ALTER TABLE orphan_appeals ADD CONSTRAINT orphan_appeals_status CHECK (status IN ('open','under_review','accepted','rejected'))");
        DB::statement('ALTER TABLE orphan_appeals ADD CONSTRAINT orphan_appeals_subject CHECK (num_nonnulls(orphan_id, household_id, guardian_id) = 1)');
        DB::statement("ALTER TABLE orphan_appeals ADD CONSTRAINT orphan_appeals_decided CHECK (status NOT IN ('accepted','rejected') OR (decided_by IS NOT NULL AND decision_note IS NOT NULL))");

        DB::statement('CREATE SEQUENCE portal_application_seq START 1');
        DB::statement('CREATE SEQUENCE orphan_appeal_seq START 1');

        foreach ([['orphans_portal_update', 'تحديث على طلب بوابة الأوصياء', 'إشعار الموظف المسؤول عند إسناد طلب أو إعادة تقديمه.'],
            ['orphans_appeal_update', 'اعتراض جديد أو محدث', 'إشعار المسؤول عن مراجعة الاعتراضات.']] as [$code, $label, $description]) {
            if (! DB::table('notification_settings')->where('event_code', $code)->exists()) {
                DB::table('notification_settings')->insert(['event_code' => $code, 'label_ar' => $label, 'description' => $description, 'in_app_enabled' => true, 'sms_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: portal applications and appeals are records.');
    }
};
