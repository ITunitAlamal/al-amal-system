<?php

use App\Services\Orphans\OrphansWorkflowInstaller as Installer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orphans registry foundation: households, parents shared between siblings, the child's file, education and health
 * history, secured documents, payout instructions (approved by Accounts), applications with per-child outcomes,
 * reviewed change requests, the civil-registry request log, admin-managed classifications and versioned form rules.
 * Additive only: the existing guardian, child and guardianship rows are extended, never rewritten.
 */
return new class extends Migration
{
    private const TABLES = ['orphan_access_log', 'registry_requests', 'orphan_change_requests', 'orphan_application_children', 'orphan_applications',
        'orphan_payment_instructions', 'orphan_documents', 'orphan_document_requirements', 'orphan_health_records', 'orphan_education_records',
        'orphan_addresses', 'orphan_parents', 'orphan_households', 'orphan_form_versions', 'orphans_module_settings'];

    public function up(): void
    {
        Schema::create('orphans_module_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedSmallInteger('eligibility_max_age')->default(18);
            $t->string('eligibility_age_status', 20)->default('pending_approval');
            $t->boolean('manual_evidence_review_enabled')->default(false);
            $t->unsignedSmallInteger('sponsorship_max_active_per_child')->nullable();
            $t->unsignedSmallInteger('campaign_cooling_off_days')->nullable();
            $t->unsignedSmallInteger('field_offline_max_hours')->default(168);
            $t->unsignedSmallInteger('field_session_hours')->default(12);
            $t->unsignedSmallInteger('field_lock_minutes')->default(5);
            $t->boolean('field_photo_enabled')->default(false);
            $t->boolean('field_geolocation_enabled')->default(false);
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE orphans_module_settings ADD CONSTRAINT orphans_settings_offline_cap CHECK (field_offline_max_hours BETWEEN 1 AND 168 AND field_session_hours BETWEEN 1 AND 24 AND field_lock_minutes BETWEEN 1 AND 60)');
        DB::statement("ALTER TABLE orphans_module_settings ADD CONSTRAINT orphans_settings_age_status CHECK (eligibility_age_status IN ('pending_approval','approved'))");

        Schema::create('orphan_form_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedInteger('version')->unique();
            $t->jsonb('rules');
            $t->string('status', 12)->default('draft');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE orphan_form_versions ADD CONSTRAINT orphan_form_versions_status CHECK (status IN ('draft','active','retired'))");
        DB::statement("CREATE UNIQUE INDEX orphan_form_versions_one_active ON orphan_form_versions (status) WHERE status = 'active'");

        Schema::create('orphan_households', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->text('family_name');
            $t->foreignId('primary_guardian_id')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->string('status', 20)->default('draft');
            $t->unsignedSmallInteger('family_size')->nullable();
            $t->unsignedSmallInteger('dependents_count')->nullable();
            $t->unsignedSmallInteger('working_members_count')->nullable();
            $t->foreignId('income_source_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->decimal('monthly_income', 12, 2)->nullable();
            $t->foreignId('economic_status_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('needs_level_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('previous_tenure_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('current_tenure_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('shelter_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->decimal('rent_amount', 12, 2)->nullable();
            $t->boolean('displaced')->default(false);
            $t->date('displaced_since')->nullable();
            $t->text('basic_needs')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by_guardian')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE orphan_households ADD CONSTRAINT orphan_households_status CHECK (status IN ('draft','registered','active','inactive','closed'))");
        DB::statement('ALTER TABLE orphan_households ADD CONSTRAINT orphan_households_amounts CHECK ((monthly_income IS NULL OR monthly_income >= 0) AND (rent_amount IS NULL OR rent_amount >= 0))');

        Schema::create('orphan_addresses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->string('kind', 10);
            foreach (['governorate_value_id', 'locality_value_id', 'area_value_id', 'subarea_value_id'] as $column) {
                $t->foreignId($column)->nullable()->constrained('master_data_values')->restrictOnDelete();
            }
            $t->string('governorate', 120)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('area', 120)->nullable();
            $t->string('neighborhood', 120)->nullable();
            $t->boolean('address_other')->default(false);
            $t->text('address_details')->nullable();
            $t->text('landmark')->nullable();
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE orphan_addresses ADD CONSTRAINT orphan_addresses_kind CHECK (kind IN ('original','current'))");
        DB::statement('CREATE UNIQUE INDEX orphan_addresses_one_current ON orphan_addresses (household_id, kind) WHERE valid_to IS NULL');

        Schema::create('orphan_parents', function (Blueprint $t): void {
            $t->id();
            $t->string('role', 10);
            $t->text('full_name');
            $t->foreignId('identity_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('identity_no')->nullable();
            $t->string('identity_hash', 64)->nullable()->unique();
            $t->date('date_of_birth')->nullable();
            $t->foreignId('vital_status_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->date('death_date')->nullable();
            $t->foreignId('death_cause_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('occupation', 150)->nullable();
            $t->foreignId('marital_status_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('education_level_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('specialization', 150)->nullable();
            $t->boolean('has_illness')->default(false);
            $t->text('illness_note')->nullable();
            $t->boolean('has_disability')->default(false);
            $t->jsonb('disability_types')->nullable();
            $t->string('provenance', 20)->default('declared');
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE orphan_parents ADD CONSTRAINT orphan_parents_role CHECK (role IN ('father','mother'))");
        DB::statement("ALTER TABLE orphan_parents ADD CONSTRAINT orphan_parents_provenance CHECK (provenance IN ('declared','document','registry','staff_confirmed'))");

        Schema::table('orphan_children', function (Blueprint $t): void {
            $t->foreignId('household_id')->nullable()->constrained('orphan_households')->restrictOnDelete();
            $t->string('file_no', 20)->nullable()->unique();
            $t->text('name_parts')->nullable();
            $t->text('english_name')->nullable();
            $t->foreignId('identity_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('sex', 6)->nullable();
            $t->string('place_of_birth', 150)->nullable();
            $t->foreignId('orphan_class_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->boolean('sole_survivor')->default(false);
            $t->foreignId('father_id')->nullable()->constrained('orphan_parents')->restrictOnDelete();
            $t->foreignId('mother_id')->nullable()->constrained('orphan_parents')->restrictOnDelete();
            $t->text('phone')->nullable();
            $t->string('verification_status', 20)->default('unverified');
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('status_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE orphan_children ALTER COLUMN status SET DEFAULT 'applicant'");
        DB::statement("ALTER TABLE orphan_children ADD CONSTRAINT orphan_children_status CHECK (status IN ('applicant','pending_activation','active','suspended','closed'))");
        DB::statement("ALTER TABLE orphan_children ADD CONSTRAINT orphan_children_sex CHECK (sex IS NULL OR sex IN ('male','female'))");
        DB::statement("ALTER TABLE orphan_children ADD CONSTRAINT orphan_children_verification CHECK (verification_status IN ('unverified','api_verified','manual_verified','conflict'))");
        DB::statement("ALTER TABLE orphan_children ADD CONSTRAINT orphan_children_active_has_file CHECK (status NOT IN ('active','suspended','closed') OR file_no IS NOT NULL)");

        Schema::create('orphan_education_records', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_child_id')->constrained('orphan_children')->restrictOnDelete();
            $t->string('school_year', 9);
            $t->foreignId('enrollment_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('stage_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('school_name', 200)->nullable();
            $t->string('grade', 40)->nullable();
            $t->string('achievement', 120)->nullable();
            $t->string('support_needs', 500)->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['orphan_child_id', 'school_year']);
        });

        Schema::create('orphan_health_records', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_child_id')->constrained('orphan_children')->restrictOnDelete();
            $t->foreignId('health_status_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->boolean('has_chronic')->default(false);
            $t->text('chronic_note')->nullable();
            $t->string('condition_code', 40)->nullable();
            $t->boolean('has_disability')->default(false);
            $t->jsonb('disability_types')->nullable();
            $t->text('medications')->nullable();
            $t->string('coverage', 200)->nullable();
            $t->text('follow_up')->nullable();
            $t->boolean('is_current')->default(true);
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX orphan_health_one_current ON orphan_health_records (orphan_child_id) WHERE is_current');

        Schema::create('orphan_document_requirements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('document_type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->string('applies_to', 10);
            $t->string('requirement', 10)->default('required');
            $t->jsonb('condition')->nullable();
            $t->string('note', 300)->nullable();
            $t->boolean('is_active')->default(true);
            $t->date('effective_from');
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['document_type_value_id', 'applies_to']);
        });
        DB::statement("ALTER TABLE orphan_document_requirements ADD CONSTRAINT orphan_doc_req_target CHECK (applies_to IN ('child','household','guardian','father','mother') AND requirement IN ('required','optional'))");

        Schema::create('orphan_documents', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('owner_type', 12);
            $t->unsignedBigInteger('owner_id');
            $t->foreignId('document_type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->text('document_no')->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('supersedes_id')->nullable()->constrained('orphan_documents')->restrictOnDelete();
            $t->string('path', 300);
            $t->text('original_name');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->string('sha256', 64);
            $t->string('status', 10)->default('pending');
            $t->text('rejection_reason')->nullable();
            $t->text('reviewer_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->boolean('is_current')->default(true);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('uploaded_by_guardian')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->timestamps();
            $t->index(['owner_type', 'owner_id', 'is_current']);
        });
        DB::statement("ALTER TABLE orphan_documents ADD CONSTRAINT orphan_documents_owner CHECK (owner_type IN ('child','household','guardian','father','mother','visit','change','exception','assistance'))");
        DB::statement("ALTER TABLE orphan_documents ADD CONSTRAINT orphan_documents_status CHECK (status IN ('pending','accepted','rejected') AND (status <> 'rejected' OR rejection_reason IS NOT NULL))");
        DB::statement('ALTER TABLE orphan_documents ADD CONSTRAINT orphan_documents_uploader CHECK (uploaded_by IS NOT NULL OR uploaded_by_guardian IS NOT NULL)');

        Schema::create('orphan_payment_instructions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->foreignId('channel_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('institution_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('branch', 120)->nullable();
            $t->text('account_holder');
            $t->text('account_identifier');
            $t->string('account_hash', 64);
            $t->string('account_last4', 4);
            $t->string('status', 20)->default('pending_review');
            $t->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('submitted_by_guardian')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE orphan_payment_instructions ADD CONSTRAINT orphan_payment_status CHECK (status IN ('pending_review','approved','rejected','superseded'))");
        DB::statement("CREATE UNIQUE INDEX orphan_payment_one_approved ON orphan_payment_instructions (household_id) WHERE status = 'approved'");

        Schema::create('orphan_applications', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->string('source', 15);
            $t->foreignId('guardian_application_id')->nullable()->constrained('guardian_applications')->restrictOnDelete();
            $t->foreignId('guardian_id')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->foreignId('form_version_id')->constrained('orphan_form_versions')->restrictOnDelete();
            $t->string('status', 20)->default('draft');
            $t->boolean('resubmission_allowed')->default(true);
            $t->text('needs_info_note')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE orphan_applications ADD CONSTRAINT orphan_applications_source CHECK (source IN ('portal','staff','registry_claim'))");
        DB::statement("ALTER TABLE orphan_applications ADD CONSTRAINT orphan_applications_status CHECK (status IN ('draft','submitted','under_review','needs_info','in_decision','approved','partially_approved','rejected','withdrawn'))");
        DB::statement("CREATE UNIQUE INDEX orphan_applications_one_open ON orphan_applications (household_id) WHERE status IN ('draft','submitted','under_review','needs_info','in_decision')");

        Schema::create('orphan_application_children', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_application_id')->constrained('orphan_applications')->restrictOnDelete();
            $t->foreignId('orphan_child_id')->constrained('orphan_children')->restrictOnDelete();
            $t->string('verification', 16)->default('pending');
            $t->text('verification_note')->nullable();
            $t->string('recommendation', 8)->nullable();
            $t->text('recommendation_note')->nullable();
            $t->string('decision', 10)->nullable();
            $t->text('decision_note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['orphan_application_id', 'orphan_child_id'], 'orphan_application_child_unique');
        });
        DB::statement("ALTER TABLE orphan_application_children ADD CONSTRAINT orphan_app_child_values CHECK (verification IN ('pending','api_verified','manual_verified','conflict','incomplete')
            AND (recommendation IS NULL OR recommendation IN ('approve','reject','defer')) AND (decision IS NULL OR decision IN ('approved','rejected','deferred')))");

        Schema::create('orphan_change_requests', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 20)->unique();
            $t->string('subject_type', 10);
            $t->unsignedBigInteger('subject_id');
            $t->foreignId('household_id')->constrained('orphan_households')->restrictOnDelete();
            $t->string('category', 20);
            $t->text('changes');
            $t->text('reason');
            $t->jsonb('evidence_document_ids')->nullable();
            $t->string('status', 12)->default('submitted');
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('requested_by_guardian')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('workflow_instance_id')->nullable()->constrained('workflow_instances')->restrictOnDelete();
            $t->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('applied_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE orphan_change_requests ADD CONSTRAINT orphan_change_values CHECK (subject_type IN ('child','household','guardian','father','mother')
            AND category IN ('contact','residence','family','education','health','identity','parent_death','guardianship','eligibility','file_status')
            AND status IN ('submitted','in_review','returned','applied','rejected','withdrawn'))");

        Schema::create('registry_requests', function (Blueprint $t): void {
            $t->id();
            $t->string('provider', 60);
            $t->string('purpose', 40);
            $t->string('subject_kind', 12);
            $t->string('identifier_hash', 64)->nullable();
            $t->string('identifier_mask', 16)->nullable();
            $t->string('outcome', 12);
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('requested_by_guardian')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->foreignId('guardian_application_id')->nullable()->constrained('guardian_applications')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        DB::statement("ALTER TABLE registry_requests ADD CONSTRAINT registry_requests_outcome CHECK (outcome IN ('available','unavailable','pending','error'))");

        Schema::create('orphan_access_log', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->string('subject_type', 20);
            $t->unsignedBigInteger('subject_id');
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::table('guardians', function (Blueprint $t): void {
            $t->string('reference_no', 20)->nullable()->unique();
            $t->foreignId('identity_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->date('date_of_birth')->nullable();
            $t->string('sex', 6)->nullable();
            $t->text('alt_phone')->nullable();
            $t->string('email', 150)->nullable();
            $t->string('occupation', 150)->nullable();
            $t->string('employer', 150)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('orphan_guardianships', function (Blueprint $t): void {
            $t->foreignId('proof_document_id')->nullable()->constrained('orphan_documents')->restrictOnDelete();
            $t->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        foreach (['orphan_household_ref_seq', 'orphan_child_file_seq', 'orphan_application_ref_seq', 'orphan_change_ref_seq', 'guardian_ref_seq'] as $sequence) {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS {$sequence} START 1");
        }

        $this->masterData();
        $this->defaults();
        $this->workflows();
        $this->permissions();
        Installer::notification('orphans_application_update', 'تحديث طلب ملف يتيم', 'لموظف القسم ورئيسه عند تقديم طلب أو استكماله أو البت فيه.');
        Installer::notification('orphans_change_update', 'طلب تعديل بيانات يتيم', 'لموظف القسم ورئيسه عند تقديم طلب تعديل أو البت فيه.');
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'orphans.registry.installed', 'subject_type' => 'orphan_households', 'subject_id' => '0',
            'metadata' => json_encode(['before' => null, 'after' => ['tables' => self::TABLES, 'eligibility_max_age' => '18 (current rule, pending approval)',
                'manual_evidence_review' => 'disabled (owner decision 2026-10-22)'], 'source' => Installer::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function masterData(): void
    {
        Installer::masterData('orphans', 'الأيتام والأسر', [
            'orphan_classifications' => ['تصنيف اليتم', [
                ['father_deceased', 'يتيم الأب'],
                ['both_parents_deceased', 'يتيم الأبوين', ['both_parents' => true]],
                ['both_parents_deceased_sole_survivor', 'يتيم الأبوين والناجي الوحيد', ['both_parents' => true, 'sole_survivor' => true]],
            ]],
            'vital_statuses' => ['الحالة الحيوية', [['alive', 'على قيد الحياة'], ['deceased', 'متوفى', ['deceased' => true]]]],
            'death_causes' => ['سبب الوفاة', []],
            'enrollment_statuses' => ['الحالة التعليمية', [['enrolled', 'ملتحق', ['enrolled' => true]], ['dropped_out', 'متسرب'], ['never_attended', 'لم يلتحق']]],
            'education_stages' => ['المرحلة الدراسية', [['pre_kindergarten', 'ما قبل الروضة'], ['kindergarten', 'روضة'], ['primary', 'المرحلة الابتدائية'], ['lower_secondary', 'المرحلة الإعدادية'], ['upper_secondary', 'المرحلة الثانوية']]],
            'health_statuses' => ['الحالة الصحية', []],
            'disability_types' => ['نوع الإعاقة', [['hearing', 'سمعية'], ['visual', 'بصرية'], ['intellectual', 'ذهنية'], ['developmental', 'نمائية']]],
            'shelter_types' => ['نوع المأوى الحالي', [['organized_tent', 'خيمة في مخيم منظم'], ['unorganized_tent', 'خيمة في تجمع غير منظم'], ['with_relatives', 'لدى أقارب'], ['rental_dwelling', 'سكن بالإيجار']]],
            'housing_tenures' => ['ملكية السكن', [['owned', 'ملك'], ['rented', 'إيجار', ['rented' => true]], ['other', 'أخرى']]],
            'guardian_authority_kinds' => ['صفة التمثيل القانوني', [['legal_guardian', 'ولاية/وصاية شرعية'], ['custodian', 'حضانة بقرار'], ['agent', 'وكالة رسمية']]],
            'income_sources' => ['مصدر الدخل', []],
            'economic_statuses' => ['الوضع الاقتصادي', []],
            'needs_levels' => ['مستوى الاحتياج', []],
            'orphan_document_types' => ['مستندات ملف اليتيم', [
                ['birth_certificate', 'شهادة الميلاد'], ['father_death_certificate', 'شهادة وفاة الأب'], ['mother_death_certificate', 'شهادة وفاة الأم'],
                ['guardian_id', 'هوية الوصي'], ['guardianship_order', 'حجة الوصاية/الحضانة'], ['personal_photo', 'صورة شخصية'],
                ['housing_proof', 'إثبات السكن'], ['economic_evidence', 'إثبات الوضع الاقتصادي'], ['medical_report', 'تقرير طبي'],
                ['school_report', 'شهادة مدرسية'], ['other', 'مستند آخر'],
            ]],
            'orphan_review_reasons' => ['أسباب الإعادة والرفض', []],
            'social_visit_types' => ['نوع الزيارة الاجتماعية', []],
            'assistance_types' => ['نوع المساعدة/الخدمة', []],
            'payment_channels' => ['قناة صرف المستحقات', [['bank', 'حساب بنكي'], ['mobile_wallet', 'محفظة إلكترونية'], ['palpay', 'PalPay']]],
        ]);
    }

    private function defaults(): void
    {
        $now = now();
        DB::table('orphans_module_settings')->insert(['id' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $r = 'required';
        $o = 'optional';
        $rules = [
            'child' => ['first_name' => [$r], 'father_name' => [$r], 'grandfather_name' => [$r], 'family_name' => [$r], 'english_name' => [$o],
                'identity_type_value_id' => [$o], 'identity_no' => ['conditional', ['field' => 'identity_type_value_id', 'filled' => true]],
                'date_of_birth' => [$r], 'place_of_birth' => [$o], 'sex' => [$r], 'orphan_class_value_id' => [$r], 'sole_survivor' => [$o], 'phone' => [$o]],
            'household' => ['family_name' => [$r], 'original_address' => [$r], 'current_address' => [$r], 'displaced' => [$o],
                'displaced_since' => ['conditional', ['field' => 'displaced', 'eq' => true]], 'shelter_type_value_id' => [$o],
                'family_size' => [$r], 'dependents_count' => [$o], 'working_members_count' => [$o], 'income_source_value_id' => [$o], 'monthly_income' => [$o],
                'economic_status_value_id' => [$o], 'needs_level_value_id' => [$o], 'previous_tenure_value_id' => [$o], 'current_tenure_value_id' => [$o],
                'rent_amount' => ['conditional', ['field' => 'current_tenure_value_id', 'meta' => 'rented']], 'basic_needs' => [$o]],
            'father' => ['full_name' => [$r], 'identity_type_value_id' => [$o], 'identity_no' => ['conditional', ['field' => 'identity_type_value_id', 'filled' => true]],
                'date_of_birth' => [$o], 'vital_status_value_id' => [$r], 'death_date' => ['conditional', ['field' => 'vital_status_value_id', 'meta' => 'deceased']],
                'death_cause_value_id' => [$o], 'occupation' => [$o]],
            'mother' => ['full_name' => [$r], 'identity_type_value_id' => [$o], 'identity_no' => ['conditional', ['field' => 'identity_type_value_id', 'filled' => true]],
                'date_of_birth' => [$o], 'vital_status_value_id' => [$r], 'death_date' => ['conditional', ['field' => 'vital_status_value_id', 'meta' => 'deceased']],
                'death_cause_value_id' => [$o], 'marital_status_value_id' => [$o], 'education_level_value_id' => [$o], 'specialization' => [$o],
                'has_illness' => [$o], 'illness_note' => ['conditional', ['field' => 'has_illness', 'eq' => true]], 'has_disability' => [$o],
                'disability_types' => ['conditional', ['field' => 'has_disability', 'eq' => true]]],
            'education' => ['school_year' => [$r], 'enrollment_value_id' => [$r], 'stage_value_id' => ['conditional', ['field' => 'enrollment_value_id', 'meta' => 'enrolled']],
                'school_name' => ['conditional', ['field' => 'enrollment_value_id', 'meta' => 'enrolled']], 'grade' => ['conditional', ['field' => 'enrollment_value_id', 'meta' => 'enrolled']],
                'achievement' => [$o], 'support_needs' => [$o]],
            'health' => ['health_status_value_id' => [$o], 'has_chronic' => [$r], 'chronic_note' => ['conditional', ['field' => 'has_chronic', 'eq' => true]],
                'has_disability' => [$r], 'disability_types' => ['conditional', ['field' => 'has_disability', 'eq' => true]], 'medications' => [$o], 'coverage' => [$o], 'follow_up' => [$o]],
            'guardian' => ['name' => [$r], 'identity_type_value_id' => [$o], 'identity_no' => [$r], 'phone' => [$r], 'alt_phone' => [$o], 'email' => [$o],
                'date_of_birth' => [$o], 'sex' => [$o], 'occupation' => [$o], 'employer' => [$o]],
        ];
        $encoded = [];
        foreach ($rules as $form => $fields) {
            foreach ($fields as $field => $rule) {
                $encoded[$form][$field] = ['req' => $rule[0]] + (isset($rule[1]) ? ['when' => $rule[1]] : []);
            }
        }
        DB::table('orphan_form_versions')->insert(['version' => 1, 'rules' => json_encode($encoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'status' => 'active',
            'notes' => 'الإصدار الأول وفق متطلبات قسم الكفالات 2026؛ الجنسية لا تُطلب في نموذج الطفل الجديد.', 'activated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        $type = fn (string $code): ?int => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'orphan_document_types')->where('v.code', $code)->value('v.id');
        foreach ([
            ['birth_certificate', 'child', 'required', null, 'لكل طفل.'],
            ['father_death_certificate', 'father', 'required', ['vital' => 'deceased'], 'عند وفاة الأب.'],
            ['mother_death_certificate', 'mother', 'required', ['vital' => 'deceased'], 'عند وفاة الأم (يتيم الأبوين).'],
            ['guardian_id', 'guardian', 'required', null, 'وثيقة هوية الوصي أو المستلم المفوض.'],
            ['guardianship_order', 'guardian', 'required', ['authority' => ['legal_guardian', 'custodian']], 'عند ادعاء ولاية أو حضانة بقرار.'],
            ['personal_photo', 'child', 'optional', null, 'محمية؛ لا تظهر في المراسلات أو للمتبرعين.'],
            ['housing_proof', 'household', 'optional', null, null],
            ['economic_evidence', 'household', 'optional', null, null],
            ['medical_report', 'child', 'required', ['health_claim' => true], 'عند ادعاء مرض مزمن أو إعاقة.'],
            ['school_report', 'child', 'optional', null, null],
        ] as [$code, $target, $requirement, $condition, $note]) {
            DB::table('orphan_document_requirements')->insert(['document_type_value_id' => $type($code), 'applies_to' => $target, 'requirement' => $requirement,
                'condition' => $condition ? json_encode($condition, JSON_THROW_ON_ERROR) : null, 'note' => $note, 'is_active' => true,
                'effective_from' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function workflows(): void
    {
        $ed = ['department_scope' => 'any'];
        Installer::install('ORPHAN_FILE_ACTIVATION', 'orphan_application', 'اعتماد ملف يتيم لأول مرة',
            'مراجعة الموظف للطلب ومستنداته، توصية رئيس قسم الكفالات، ثم قرار المدير التنفيذي لكل طفل؛ التفعيل وترقيم الملف عند الاعتماد فقط.', 'ORP', [
                ['staff_review', 'مراجعة الطلب والمستندات', 10, 'start', 'requester', null, null],
                ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed + ['delegation_scope' => 'orphans.file_activation']],
                ['activated', 'اعتماد الملف وتفعيله', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], [
                ['staff_review', 'head_recommendation', 'submit', 'رفع الطلب لرئيس القسم', false],
                ['head_recommendation', 'ed_decision', 'recommend', 'رفع التوصية للمدير التنفيذي', false],
                ['head_recommendation', 'staff_review', 'return', 'إعادة للموظف (اذكر السبب)', true],
                ['ed_decision', 'activated', 'approve', 'اعتماد القرار لكل طفل وفق التوصيات', false],
                ['ed_decision', 'head_recommendation', 'return', 'إعادة لرئيس القسم (اذكر السبب)', true],
                ['ed_decision', 'rejected', 'reject', 'رفض الطلب كاملًا (اذكر السبب)', true],
            ]);
        Installer::install('ORPHAN_CHANGE', 'orphan_change_request', 'تعديل بيانات ملف يتيم',
            'تحقق الموظف من التعديل وأدلته، توصية رئيس القسم، ثم قرار المدير التنفيذي؛ يُطبّق التعديل عند الاعتماد مع حفظ القيم السابقة.', 'OCH', [
                ['staff_verification', 'تحقق الموظف من التعديل وأدلته', 10, 'start', 'requester', null, null],
                ['head_recommendation', 'توصية رئيس قسم الكفالات', 20, 'task', 'department_head', null, null],
                ['ed_decision', 'قرار المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $ed + ['delegation_scope' => 'orphans.changes']],
                ['applied', 'اعتماد التعديل وتطبيقه', 900, 'end', null, null, ['outcome' => 'completed']],
                ['rejected', 'رفض التعديل', 910, 'end', null, null, ['outcome' => 'rejected']],
            ], [
                ['staff_verification', 'head_recommendation', 'submit', 'رفع التعديل لرئيس القسم', false],
                ['head_recommendation', 'ed_decision', 'recommend', 'رفع التوصية للمدير التنفيذي', false],
                ['head_recommendation', 'staff_verification', 'return', 'إعادة للموظف (اذكر السبب)', true],
                ['ed_decision', 'applied', 'approve', 'اعتماد التعديل وتطبيقه', false],
                ['ed_decision', 'head_recommendation', 'return', 'إعادة لرئيس القسم (اذكر السبب)', true],
                ['ed_decision', 'rejected', 'reject', 'رفض التعديل (اذكر السبب)', true],
            ]);
    }

    private function permissions(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore(['code' => 'finance.sponsorships.record', 'name' => 'تسجيل تحصيلات وصرف الكفالات والتبرعات واعتماد بيانات الصرف', 'created_at' => $now, 'updated_at' => $now]);
        $permission = DB::table('permissions')->where('code', 'finance.sponsorships.record')->value('id');
        $role = DB::table('roles')->where('code', 'finance_head')->value('id');
        if ($permission && $role) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $role]);
        }
    }

    public function down(): void
    {
        foreach (['orphan_households', 'orphan_parents', 'orphan_documents', 'orphan_applications', 'orphan_change_requests', 'registry_requests'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("يوجد سجلات في {$table}؛ لا يُتراجع عن الترحيل حتى لا تضيع بيانات الأيتام.");
            }
        }
        Installer::uninstall(['ORPHAN_FILE_ACTIVATION', 'ORPHAN_CHANGE']);
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->dropConstrainedForeignId('proof_document_id'));
        Schema::table('orphan_guardianships', fn (Blueprint $t) => $t->dropConstrainedForeignId('ended_by'));
        Schema::table('guardians', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('identity_type_value_id');
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['reference_no', 'date_of_birth', 'sex', 'alt_phone', 'email', 'occupation', 'employer']);
        });
        DB::statement("ALTER TABLE orphan_children ALTER COLUMN status SET DEFAULT 'active'");
        foreach (['orphan_children_status', 'orphan_children_sex', 'orphan_children_verification', 'orphan_children_active_has_file'] as $constraint) {
            DB::statement("ALTER TABLE orphan_children DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        Schema::table('orphan_children', function (Blueprint $t): void {
            foreach (['household_id', 'identity_type_value_id', 'orphan_class_value_id', 'father_id', 'mother_id', 'activated_by', 'created_by'] as $column) {
                $t->dropConstrainedForeignId($column);
            }
            $t->dropColumn(['file_no', 'name_parts', 'english_name', 'sex', 'place_of_birth', 'sole_survivor', 'phone', 'verification_status', 'registered_at', 'activated_at', 'status_reason']);
        });
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['orphan_household_ref_seq', 'orphan_child_file_seq', 'orphan_application_ref_seq', 'orphan_change_ref_seq', 'guardian_ref_seq'] as $sequence) {
            DB::statement("DROP SEQUENCE IF EXISTS {$sequence}");
        }
        DB::table('notification_settings')->whereIn('event_code', ['orphans_application_update', 'orphans_change_update'])->delete();
    }
};
