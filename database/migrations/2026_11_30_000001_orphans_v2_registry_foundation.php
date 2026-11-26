<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * AIMS 2.0 orphans department — registry foundation (phase 1).
 *
 * One canonical `persons` row per human (child, guardian, father, mother, household contact). Department roles hang
 * off it: `orphans` (the authoritative child file), `orphan_guardians` (guardian registry, may exist without
 * children), parent links shared by siblings, and direct child↔guardian relationships with their own status and
 * history. Households are social context only and never grant guardianship.
 *
 * Invariant enforced in the database: an orphan that is not ended/archived always has at least one live
 * guardianship (claimed, pending or approved) — a deferred constraint trigger, so the guardian link can be
 * written in the same transaction right after the child.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('persons', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('first_name', 100)->nullable();
            $t->string('father_name', 100)->nullable();
            $t->string('grandfather_name', 100)->nullable();
            $t->string('family_name', 100)->nullable();
            $t->string('extra_names', 200)->nullable();
            $t->string('full_name', 400);
            $t->string('name_en', 300)->nullable();
            // Read-only search forms (ArabicText::normalize); the names above are never rewritten.
            $t->text('name_search')->default('');
            $t->string('n_first', 100)->nullable();
            $t->string('n_father', 100)->nullable();
            $t->string('n_grandfather', 100)->nullable();
            $t->string('n_family', 100)->nullable();
            $t->foreignId('id_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('id_number')->nullable();
            $t->string('id_hash', 64)->nullable();
            $t->string('id_last4', 4)->nullable();
            $t->string('id_format', 15)->default('missing');
            $t->timestamp('id_verified_at')->nullable();
            $t->foreignId('id_verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('id_verification_source', 200)->nullable();
            $t->string('sex', 10)->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('dob_status', 12)->default('declared');
            $t->foreignId('nationality_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('place_of_birth', 150)->nullable();
            $t->string('vital_status', 12)->default('unknown');
            $t->string('phone', 20)->nullable();
            $t->string('alt_phone', 20)->nullable();
            $t->string('email', 150)->nullable();
            $t->text('notes')->nullable();
            $t->text('restricted_notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index('n_first');
            $t->index('n_father');
            $t->index('n_grandfather');
            $t->index('n_family');
            $t->index('phone');
        });
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_id_format CHECK (id_format IN ('missing','valid_format','invalid_format','placeholder','other_type'))");
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_sex CHECK (sex IS NULL OR sex IN ('male','female'))");
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_dob_status CHECK (dob_status IN ('declared','verified','estimated'))");
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_vital_status CHECK (vital_status IN ('alive','deceased','missing','unknown'))");
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_id_pair CHECK ((id_number IS NULL) = (id_hash IS NULL))");
        // A person is stored once per authoritative identity; placeholder/invalid numbers are flagged, not unique.
        DB::statement("CREATE UNIQUE INDEX persons_identity_unique ON persons (id_hash) WHERE id_format IN ('valid_format','other_type')");
        DB::statement('CREATE INDEX persons_id_hash_index ON persons (id_hash)');
        DB::statement('CREATE INDEX persons_name_search_trgm ON persons USING gin (name_search gin_trgm_ops)');

        Schema::create('person_status_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $t->string('status', 12);
            $t->date('event_date')->nullable();
            $t->string('place', 200)->nullable();
            $t->text('cause')->nullable();
            $t->text('evidence_note')->nullable();
            $t->string('verification', 12)->default('reported');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['person_id', 'id']);
        });
        DB::statement("ALTER TABLE person_status_events ADD CONSTRAINT person_status_events_status CHECK (status IN ('alive','deceased','missing','unknown'))");
        DB::statement("ALTER TABLE person_status_events ADD CONSTRAINT person_status_events_verification CHECK (verification IN ('reported','verified'))");

        Schema::create('households', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('contact_person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $t->unsignedSmallInteger('members_count')->nullable();
            $t->unsignedSmallInteger('dependents_count')->nullable();
            $t->unsignedSmallInteger('earners_count')->nullable();
            $t->text('living_conditions')->nullable();
            $t->string('status', 12)->default('active');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE households ADD CONSTRAINT households_status CHECK (status IN ('active','archived'))");

        Schema::create('household_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('household_id')->constrained('households')->restrictOnDelete();
            $t->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $t->foreignId('relation_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('ended_reason', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index('person_id');
        });
        DB::statement('CREATE UNIQUE INDEX household_members_current ON household_members (household_id, person_id) WHERE ends_on IS NULL');

        Schema::create('orphans', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('person_id')->unique()->constrained('persons')->restrictOnDelete();
            $t->string('status', 12)->default('draft');
            $t->string('status_reason', 300)->nullable();
            $t->string('registration_source', 10)->default('staff');
            $t->date('registered_on');
            $t->foreignId('orphan_class_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('class_basis', 12)->default('provisional');
            $t->unsignedTinyInteger('completeness')->default(0);
            $t->jsonb('completeness_sections')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('review_due_on')->nullable();
            $t->text('notes')->nullable();
            $t->text('restricted_notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['status', 'id']);
            $t->index('assigned_to');
        });
        DB::statement("ALTER TABLE orphans ADD CONSTRAINT orphans_status CHECK (status IN ('draft','active','suspended','archived','ended'))");
        DB::statement("ALTER TABLE orphans ADD CONSTRAINT orphans_source CHECK (registration_source IN ('staff','portal','import'))");
        DB::statement("ALTER TABLE orphans ADD CONSTRAINT orphans_class_basis CHECK (class_basis IN ('derived','provisional','manual'))");

        Schema::create('orphan_parent_links', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_id')->constrained('orphans')->restrictOnDelete();
            $t->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $t->string('relation', 10);
            $t->string('basis', 12)->default('declared');
            $t->string('verification', 12)->default('reported');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->text('evidence_note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['orphan_id', 'relation']);
            $t->index('person_id');
        });
        DB::statement("ALTER TABLE orphan_parent_links ADD CONSTRAINT orphan_parent_links_relation CHECK (relation IN ('father','mother'))");
        DB::statement("ALTER TABLE orphan_parent_links ADD CONSTRAINT orphan_parent_links_basis CHECK (basis IN ('declared','document','import'))");
        DB::statement("ALTER TABLE orphan_parent_links ADD CONSTRAINT orphan_parent_links_verification CHECK (verification IN ('reported','verified'))");

        Schema::create('orphan_guardians', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('reference_no', 30)->unique();
            $t->foreignId('person_id')->unique()->constrained('persons')->restrictOnDelete();
            $t->string('status', 10)->default('active');
            $t->foreignId('portal_account_id')->nullable()->unique()->constrained('guardians')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE orphan_guardians ADD CONSTRAINT orphan_guardians_status CHECK (status IN ('active','inactive'))");

        Schema::create('orphan_guardianships', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_id')->constrained('orphans')->restrictOnDelete();
            $t->foreignId('guardian_id')->constrained('orphan_guardians')->restrictOnDelete();
            $t->string('status', 10)->default('claimed');
            $t->boolean('is_primary')->default(false);
            $t->foreignId('kinship_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('authority_kind_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('legal_basis', 300)->nullable();
            $t->string('authority_scope', 300)->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('source', 10)->default('staff');
            $t->text('evidence_note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->foreignId('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('ended_at')->nullable();
            $t->string('ended_reason', 300)->nullable();
            $t->foreignId('replaces_id')->nullable()->constrained('orphan_guardianships')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['guardian_id', 'status']);
            $t->index(['orphan_id', 'status']);
        });
        DB::statement("ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_status CHECK (status IN ('claimed','pending','approved','rejected','ended'))");
        DB::statement("ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_source CHECK (source IN ('staff','portal','import'))");
        DB::statement("ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_decided CHECK (status NOT IN ('approved','rejected') OR (decided_by IS NOT NULL AND decided_at IS NOT NULL))");
        DB::statement("ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_ended CHECK (status <> 'ended' OR (ended_at IS NOT NULL AND ended_reason IS NOT NULL))");
        DB::statement("ALTER TABLE orphan_guardianships ADD CONSTRAINT orphan_guardianships_dates CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)");
        DB::statement("CREATE UNIQUE INDEX orphan_guardianships_live_pair ON orphan_guardianships (orphan_id, guardian_id) WHERE status IN ('claimed','pending','approved')");
        DB::statement("CREATE UNIQUE INDEX orphan_guardianships_one_primary ON orphan_guardianships (orphan_id) WHERE is_primary AND status IN ('claimed','pending','approved')");

        DB::unprepared(<<<'SQL'
CREATE FUNCTION orphans_require_guardian() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target bigint;
BEGIN
    IF TG_TABLE_NAME = 'orphans' THEN target := NEW.id; ELSE target := COALESCE(NEW.orphan_id, OLD.orphan_id); END IF;
    IF EXISTS (SELECT 1 FROM orphans o WHERE o.id = target AND o.status NOT IN ('ended','archived'))
       AND NOT EXISTS (SELECT 1 FROM orphan_guardianships g WHERE g.orphan_id = target AND g.status IN ('claimed','pending','approved')) THEN
        RAISE EXCEPTION 'ORPHAN_WITHOUT_GUARDIAN: every operational orphan needs a linked guardian (orphan %)', target USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER orphans_require_guardian_on_orphan AFTER INSERT OR UPDATE OF status ON orphans
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION orphans_require_guardian();
CREATE CONSTRAINT TRIGGER orphans_require_guardian_on_link AFTER UPDATE OF status OR DELETE ON orphan_guardianships
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION orphans_require_guardian();
SQL);

        Schema::create('registry_addresses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->string('kind', 12);
            $t->foreignId('governorate_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('locality_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('area_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('subarea_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('address_text', 500)->nullable();
            $t->text('detail')->nullable();
            $t->foreignId('shelter_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('tenure_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('stability', 100)->nullable();
            $t->string('services_access', 300)->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['person_id', 'kind']);
            $t->index(['household_id', 'kind']);
            $t->index('governorate_value_id');
            $t->index('locality_value_id');
        });
        DB::statement('ALTER TABLE registry_addresses ADD CONSTRAINT registry_addresses_owner CHECK (num_nonnulls(person_id, household_id) = 1)');
        DB::statement("ALTER TABLE registry_addresses ADD CONSTRAINT registry_addresses_kind CHECK (kind IN ('original','current','displacement'))");
        // One open current address per owner; displacement episodes are rows with dates.
        DB::statement("CREATE UNIQUE INDEX registry_addresses_one_current_person ON registry_addresses (person_id) WHERE kind = 'current' AND ends_on IS NULL AND person_id IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX registry_addresses_one_current_household ON registry_addresses (household_id) WHERE kind = 'current' AND ends_on IS NULL AND household_id IS NOT NULL");

        Schema::create('economic_assessments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->string('period', 10)->default('current');
            $t->date('reference_date')->nullable();
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->foreignId('employment_status_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('occupation', 200)->nullable();
            $t->string('sector', 150)->nullable();
            $t->foreignId('income_band_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('income_source_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('economic_status_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->unsignedSmallInteger('dependents_count')->nullable();
            $t->unsignedSmallInteger('earners_count')->nullable();
            $t->text('obligations')->nullable();
            $t->text('notes')->nullable();
            $t->string('source', 15)->default('self_reported');
            $t->string('verification', 12)->default('reported');
            $t->foreignId('assessed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['person_id', 'period', 'id']);
            $t->index(['household_id', 'id']);
        });
        DB::statement('ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_owner CHECK (num_nonnulls(person_id, household_id) = 1)');
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_period CHECK (period IN ('current','pre_death'))");
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_source CHECK (source IN ('self_reported','caregiver','document','field_visit','import'))");
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_verification CHECK (verification IN ('reported','verified'))");

        Schema::create('care_records', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $t->string('category', 20);
            $t->foreignId('need_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('summary', 300)->nullable();
            $t->text('clinical_details')->nullable();
            $t->string('source', 12)->default('caregiver');
            $t->string('verification', 12)->default('reported');
            $t->date('follow_up_on')->nullable();
            $t->string('access_barrier', 300)->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['person_id', 'is_active']);
        });
        DB::statement("ALTER TABLE care_records ADD CONSTRAINT care_records_category CHECK (category IN ('health','disability','chronic','medication','assistive_device','education_support','other'))");
        DB::statement("ALTER TABLE care_records ADD CONSTRAINT care_records_source CHECK (source IN ('self','caregiver','clinician','document','import'))");
        DB::statement("ALTER TABLE care_records ADD CONSTRAINT care_records_verification CHECK (verification IN ('reported','verified'))");

        Schema::create('education_records', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_id')->constrained('orphans')->restrictOnDelete();
            $t->string('academic_year', 9)->nullable();
            $t->foreignId('stage_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('grade', 60)->nullable();
            $t->string('school_name', 200)->nullable();
            $t->string('school_type', 60)->nullable();
            $t->foreignId('status_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('performance', 100)->nullable();
            $t->string('attendance_note', 300)->nullable();
            $t->text('support_needs')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['orphan_id', 'id']);
        });

        Schema::create('social_assessments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->string('kind', 12)->default('visit');
            $t->string('status', 12)->default('scheduled');
            $t->date('scheduled_on')->nullable();
            $t->date('completed_on')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('needs_level_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->text('summary')->nullable();
            $t->date('valid_until')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['assigned_to', 'status']);
            $t->index(['orphan_id', 'id']);
        });
        DB::statement('ALTER TABLE social_assessments ADD CONSTRAINT social_assessments_subject CHECK (num_nonnulls(orphan_id, household_id) >= 1)');
        DB::statement("ALTER TABLE social_assessments ADD CONSTRAINT social_assessments_kind CHECK (kind IN ('visit','assessment','follow_up'))");
        DB::statement("ALTER TABLE social_assessments ADD CONSTRAINT social_assessments_status CHECK (status IN ('scheduled','completed','cancelled'))");
        DB::statement("ALTER TABLE social_assessments ADD CONSTRAINT social_assessments_completed CHECK (status <> 'completed' OR (completed_on IS NOT NULL AND completed_by IS NOT NULL))");

        Schema::create('registry_documents', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('person_id')->nullable()->constrained('persons')->restrictOnDelete();
            $t->foreignId('guardianship_id')->nullable()->constrained('orphan_guardianships')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->foreignId('document_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('title', 200)->nullable();
            $t->string('path', 500);
            $t->string('original_name', 255);
            $t->string('mime', 120);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('review_status', 10)->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->boolean('is_restricted')->default(false);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('uploaded_by_guardian')->nullable()->constrained('guardians')->restrictOnDelete();
            $t->timestamps();
            $t->index('person_id');
            $t->index('guardianship_id');
        });
        DB::statement('ALTER TABLE registry_documents ADD CONSTRAINT registry_documents_owner CHECK (num_nonnulls(person_id, guardianship_id, household_id) >= 1)');
        DB::statement("ALTER TABLE registry_documents ADD CONSTRAINT registry_documents_review CHECK (review_status IN ('pending','accepted','rejected'))");

        Schema::create('orphan_staff_capabilities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('capability', 20);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->text('note')->nullable();
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX orphan_staff_capabilities_live ON orphan_staff_capabilities (user_id, capability) WHERE revoked_at IS NULL');

        Schema::create('orphan_access_log', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at');
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('orphan_settings', function (Blueprint $t): void {
            $t->string('key', 60)->primary();
            $t->jsonb('value')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('updated_at')->nullable();
        });
        DB::table('orphan_settings')->insert([
            // Undecided by the owner: no maximum age is enforced until set.
            ['key' => 'orphan_max_age', 'value' => json_encode(null), 'updated_at' => now()],
            ['key' => 'allow_multiple_approved_guardians', 'value' => json_encode(true), 'updated_at' => now()],
            ['key' => 'followup_interval_days', 'value' => json_encode(null), 'updated_at' => now()],
        ]);

        foreach (['orphan_reference_seq', 'guardian_reference_seq', 'household_reference_seq'] as $sequence) {
            DB::statement("CREATE SEQUENCE {$sequence} START 1");
        }

        $this->masterData();
    }

    /** Reference lists the profiles use; values are managed in «ثوابت النظام». Only age bands get defaults. */
    private function masterData(): void
    {
        $group = DB::table('master_data_groups')->where('code', 'orphans')->value('id');
        $types = [
            'orphan_age_bands' => ['الفئات العمرية للأيتام', 'Orphan age bands', 'تُستخدم في اللوحة والتقارير؛ الحدود في metadata (min_age/max_age بالسنوات الكاملة).'],
            'employment_statuses' => ['الحالة الوظيفية', 'Employment statuses', 'للتقييم الاقتصادي للوصي والأم والأب قبل الوفاة.'],
            'income_bands' => ['فئات الدخل', 'Income bands', 'فئات دخل تقريبية بدل المبالغ الدقيقة.'],
            'portal_review_checklist' => ['قائمة فحص طلبات البوابة', 'Portal review checklist', 'بنود قصيرة تظهر في مساحة مراجعة طلب الوصي.'],
        ];
        $sort = 200;
        foreach ($types as $code => [$ar, $en, $description]) {
            if (! DB::table('master_data_types')->where('code', $code)->exists()) {
                DB::table('master_data_types')->insert(['group_id' => $group, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'description' => $description,
                    'sort_order' => $sort++, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $bands = DB::table('master_data_types')->where('code', 'orphan_age_bands')->value('id');
        if (! DB::table('master_data_values')->where('type_id', $bands)->exists()) {
            foreach ([['age_0_5', 'من 0 إلى 5 سنوات', 0, 5], ['age_6_11', 'من 6 إلى 11 سنة', 6, 11], ['age_12_17', 'من 12 إلى 17 سنة', 12, 17], ['age_18_plus', '18 سنة فأكثر', 18, null]] as $i => [$code, $name, $min, $max]) {
                DB::table('master_data_values')->insert(['type_id' => $bands, 'code' => $code, 'name_ar' => $name, 'sort_order' => $i + 1, 'is_active' => true,
                    'metadata' => json_encode(['min_age' => $min, 'max_age' => $max]), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: the orphans registry holds live records.');
    }
};
