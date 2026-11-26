<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Comprehensive family registration fields (data dictionary: docs/aims2/AIMS2_REGISTRATION_DATA_DICTIONARY_AR.md).
 *
 * Additive only. The family request (`portal_applications`) becomes the request snapshot for both sources (portal and
 * staff), with drafts and a declaration. Its encrypted `form_data` is what the applicant reported. Approval promotes
 * each child into the operational tables, which this migration extends:
 * - parent status details;
 * - economic facts with amount and currency;
 * - household counts;
 * - address and displacement history;
 * - education and health details;
 * - external support, kept separate from AIMS benefits.
 */
return new class extends Migration
{
    private const YNU = "('yes','no','unknown')";

    public function up(): void
    {
        $master = fn (Blueprint $t, string $column) => $t->foreignId($column)->nullable()->constrained('master_data_values')->restrictOnDelete();

        Schema::table('persons', function (Blueprint $t) use ($master): void {
            $master($t, 'marital_status_value_id');
            $master($t, 'education_level_value_id');
            $t->string('education_specialization', 150)->nullable();
            $t->string('education_institution', 200)->nullable();
            $t->string('vocational_training', 300)->nullable();
            $t->string('contact_channel', 12)->nullable();
            $t->string('civil_registry_ref', 60)->nullable();
        });
        DB::statement("ALTER TABLE persons ADD CONSTRAINT persons_contact_channel CHECK (contact_channel IS NULL OR contact_channel IN ('phone','sms','whatsapp','in_person','other'))");

        Schema::table('person_status_events', function (Blueprint $t) use ($master): void {
            $master($t, 'cause_value_id');
            $t->string('certificate_no', 60)->nullable();
            $t->date('reported_on')->nullable();
            $t->string('reported_to', 200)->nullable();
            $t->string('report_ref', 100)->nullable();
            $t->string('source', 200)->nullable();
        });

        DB::statement('ALTER TABLE registry_addresses DROP CONSTRAINT registry_addresses_kind');
        DB::statement("ALTER TABLE registry_addresses ADD CONSTRAINT registry_addresses_kind CHECK (kind IN ('original','current','displacement','last_known'))");
        Schema::table('registry_addresses', function (Blueprint $t): void {
            $t->string('street', 200)->nullable();
            $t->string('landmark', 200)->nullable();
            $t->decimal('latitude', 9, 6)->nullable();
            $t->decimal('longitude', 9, 6)->nullable();
            $t->string('housing_condition', 200)->nullable();
            $t->string('move_reason', 300)->nullable();
            $t->string('contact_accessibility', 200)->nullable();
        });
        DB::statement('ALTER TABLE registry_addresses ADD CONSTRAINT registry_addresses_geo CHECK ((latitude IS NULL) = (longitude IS NULL))');
        DB::statement('ALTER TABLE registry_addresses ADD CONSTRAINT registry_addresses_dates CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)');

        Schema::table('portal_applications', function (Blueprint $t) use ($master): void {
            $t->string('source', 8)->default('portal');
            $t->foreignId('created_by_user')->nullable()->constrained('users')->restrictOnDelete();
            $master($t, 'applicant_relationship_value_id');
            $t->string('alt_phone', 20)->nullable();
            $t->timestamp('declaration_accepted_at')->nullable();
            $t->timestamp('consent_accepted_at')->nullable();
            $t->text('form_data')->nullable();
            $t->unsignedSmallInteger('form_version')->default(1);
            $t->timestamp('last_saved_at')->nullable();
            $t->jsonb('promotion')->nullable();
            $t->text('review_notes')->nullable();
        });
        DB::statement('ALTER TABLE portal_applications ALTER COLUMN guardian_account_id DROP NOT NULL');
        DB::statement('ALTER TABLE portal_applications ALTER COLUMN submitted_at DROP NOT NULL');
        DB::statement('ALTER TABLE portal_applications DROP CONSTRAINT portal_applications_status');
        DB::statement("ALTER TABLE portal_applications ADD CONSTRAINT portal_applications_status CHECK (status IN ('draft','submitted','under_review','needs_completion','resubmitted','completed','rejected','withdrawn'))");
        DB::statement("ALTER TABLE portal_applications ADD CONSTRAINT portal_applications_source CHECK (source IN ('portal','staff'))");
        DB::statement("ALTER TABLE portal_applications ADD CONSTRAINT portal_applications_owner CHECK ((source = 'portal' AND guardian_account_id IS NOT NULL) OR (source = 'staff' AND created_by_user IS NOT NULL))");
        // A draft is not a request: only a submitted request carries a submission time and the signed declaration.
        DB::statement("ALTER TABLE portal_applications ADD CONSTRAINT portal_applications_submitted CHECK (status IN ('draft','withdrawn') OR (submitted_at IS NOT NULL AND declaration_accepted_at IS NOT NULL AND consent_accepted_at IS NOT NULL))");

        Schema::table('portal_application_children', function (Blueprint $t): void {
            $t->string('card_key', 36)->nullable();
            $t->string('guardian_key', 40)->nullable();
            $t->string('guardian_name', 400)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX portal_application_children_card ON portal_application_children (application_id, card_key) WHERE card_key IS NOT NULL');
        DB::statement('ALTER TABLE portal_application_children DROP CONSTRAINT portal_application_children_status');
        DB::statement("ALTER TABLE portal_application_children ADD CONSTRAINT portal_application_children_status CHECK (status IN ('pending','approved','rejected','needs_completion','withdrawn'))");

        Schema::table('registry_documents', function (Blueprint $t): void {
            $t->string('target', 60)->nullable();
        });

        Schema::table('economic_assessments', function (Blueprint $t): void {
            $t->string('employed', 8)->nullable();
            $t->string('employer', 200)->nullable();
            $t->string('stability', 12)->nullable();
            $t->decimal('income_amount', 12, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('income_period', 8)->nullable();
            $t->string('income_regularity', 12)->nullable();
            $t->string('other_income', 300)->nullable();
            $t->jsonb('income_source_ids')->nullable();
            $t->boolean('supports_household')->nullable();
            $t->boolean('breadwinner_loss')->nullable();
            $t->decimal('expenses_amount', 12, 2)->nullable();
            $t->text('unmet_needs')->nullable();
            $t->string('housing_note', 300)->nullable();
            $t->string('confidence', 8)->nullable();
            $t->date('verified_on')->nullable();
            $t->foreignId('application_id')->nullable()->constrained('portal_applications')->restrictOnDelete();
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_employed CHECK (employed IS NULL OR employed IN '.self::YNU.')');
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_stability CHECK (stability IS NULL OR stability IN ('stable','irregular','seasonal','unknown'))");
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_period_kind CHECK (income_period IS NULL OR income_period IN ('monthly','weekly','yearly'))");
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_regularity CHECK (income_regularity IS NULL OR income_regularity IN ('regular','irregular','unknown'))");
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_confidence CHECK (confidence IS NULL OR confidence IN ('high','medium','low'))");
        DB::statement('ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_amounts CHECK ((income_amount IS NULL OR income_amount >= 0) AND (expenses_amount IS NULL OR expenses_amount >= 0))');
        DB::statement('ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_currency CHECK ((income_amount IS NULL AND expenses_amount IS NULL) OR currency IS NOT NULL)');

        Schema::table('households', function (Blueprint $t): void {
            $t->string('display_name', 200)->nullable();
            $t->unsignedSmallInteger('male_count')->nullable();
            $t->unsignedSmallInteger('female_count')->nullable();
            $t->unsignedSmallInteger('unknown_sex_count')->nullable();
            $t->unsignedSmallInteger('studying_count')->nullable();
            $t->unsignedSmallInteger('employed_count')->nullable();
            $t->date('assessed_on')->nullable();
            $t->text('count_exception_reason')->nullable();
            $t->string('displacement_status', 8)->default('unknown');
            $t->date('displaced_since')->nullable();
            $t->unsignedSmallInteger('displacement_count')->nullable();
            $t->string('external_aid_status', 8)->default('unknown');
            $t->string('utilities_access', 300)->nullable();
            $t->foreignId('application_id')->nullable()->constrained('portal_applications')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE households ADD CONSTRAINT households_displacement CHECK (displacement_status IN '.self::YNU.')');
        DB::statement('ALTER TABLE households ADD CONSTRAINT households_external_aid CHECK (external_aid_status IN '.self::YNU.')');

        Schema::table('household_members', function (Blueprint $t): void {
            $t->boolean('is_studying')->nullable();
            $t->boolean('is_employed')->nullable();
            $t->boolean('is_dependent')->nullable();
        });

        Schema::table('orphans', function (Blueprint $t) use ($master): void {
            $master($t, 'health_status_value_id');
            $t->string('external_sponsorship_status', 8)->default('unknown');
            $t->jsonb('reported_needs')->nullable();
            $t->foreignId('application_id')->nullable()->constrained('portal_applications')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE orphans ADD CONSTRAINT orphans_external_sponsorship CHECK (external_sponsorship_status IN '.self::YNU.')');

        Schema::table('orphan_guardianships', function (Blueprint $t): void {
            $t->string('issuing_authority', 200)->nullable();
            $t->string('decision_no', 100)->nullable();
        });

        Schema::table('education_records', function (Blueprint $t) use ($master): void {
            $master($t, 'institution_type_value_id');
            $t->string('modality', 10)->nullable();
            $t->string('nonattendance_reason', 300)->nullable();
            $t->boolean('transport_need')->nullable();
            $t->string('assistive_need', 300)->nullable();
            $t->string('specialization', 150)->nullable();
            $t->string('source', 12)->default('caregiver');
        });
        DB::statement("ALTER TABLE education_records ADD CONSTRAINT education_records_modality CHECK (modality IS NULL OR modality IN ('in_person','remote','blended','other'))");

        Schema::table('care_records', function (Blueprint $t) use ($master): void {
            $master($t, 'disability_type_value_id');
            $t->string('severity', 10)->nullable();
            $t->string('regular_follow_up', 8)->nullable();
            $t->string('follow_up_frequency', 100)->nullable();
            $t->text('accommodations')->nullable();
        });
        DB::statement("ALTER TABLE care_records ADD CONSTRAINT care_records_severity CHECK (severity IS NULL OR severity IN ('mild','moderate','severe','unknown'))");
        DB::statement('ALTER TABLE care_records ADD CONSTRAINT care_records_follow_up CHECK (regular_follow_up IS NULL OR regular_follow_up IN '.self::YNU.')');

        // Reported support from other organizations. It is never an AIMS benefit (benefit_records = confirmed deliveries only).
        Schema::create('external_support_records', function (Blueprint $t) use ($master): void {
            $t->id();
            $t->foreignId('orphan_id')->nullable()->constrained('orphans')->restrictOnDelete();
            $t->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();
            $t->string('kind', 12);
            $t->string('organization', 200);
            $master($t, 'support_type_value_id');
            $t->string('support_type_note', 200)->nullable();
            $master($t, 'frequency_value_id');
            $t->string('regularity', 10)->nullable();
            $t->date('received_on')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->decimal('amount', 12, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('quantity', 100)->nullable();
            $t->string('verification', 12)->default('reported');
            $t->string('source', 15)->default('self_reported');
            $t->text('evidence_note')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('application_id')->nullable()->constrained('portal_applications')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index('orphan_id');
            $t->index('household_id');
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE external_support_records ADD CONSTRAINT external_support_owner CHECK (num_nonnulls(orphan_id, household_id) = 1)');
        DB::statement("ALTER TABLE external_support_records ADD CONSTRAINT external_support_kind CHECK (kind IN ('aid','sponsorship'))");
        DB::statement("ALTER TABLE external_support_records ADD CONSTRAINT external_support_regularity CHECK (regularity IS NULL OR regularity IN ('regular','irregular','one_off','unknown'))");
        DB::statement("ALTER TABLE external_support_records ADD CONSTRAINT external_support_verification CHECK (verification IN ('reported','verified'))");
        DB::statement("ALTER TABLE external_support_records ADD CONSTRAINT external_support_source CHECK (source IN ('self_reported','caregiver','document','field_visit','import'))");
        DB::statement('ALTER TABLE external_support_records ADD CONSTRAINT external_support_amount CHECK (amount IS NULL OR (amount >= 0 AND currency IS NOT NULL))');
        DB::statement('ALTER TABLE external_support_records ADD CONSTRAINT external_support_dates CHECK (ends_on IS NULL OR starts_on IS NULL OR ends_on >= starts_on)');

        $this->referenceValues();
    }

    /**
     * Codes the conditional form logic depends on. Names stay editable in «ثوابت النظام»; existing values are kept and
     * only missing codes are added.
     */
    private function referenceValues(): void
    {
        $add = function (string $type, array $values): void {
            $typeId = DB::table('master_data_types')->where('code', $type)->value('id');
            if (! $typeId) {
                return;
            }
            $sort = (int) DB::table('master_data_values')->where('type_id', $typeId)->max('sort_order');
            foreach ($values as $code => $name) {
                if (! DB::table('master_data_values')->where('type_id', $typeId)->where('code', $code)->exists()) {
                    DB::table('master_data_values')->insert(['type_id' => $typeId, 'code' => $code, 'name_ar' => $name, 'sort_order' => ++$sort, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        };
        $add('health_statuses', ['no_known_issue' => 'لا توجد مشكلة معروفة', 'chronic' => 'مرض مزمن', 'temporary' => 'حالة مؤقتة', 'disability' => 'إعاقة',
            'needs_follow_up' => 'تحتاج متابعة', 'unknown' => 'غير معروف']);
        $add('enrollment_statuses', ['not_school_age' => 'دون سن الدراسة', 'kindergarten' => 'في الروضة', 'university' => 'طالب جامعي', 'not_enrolled' => 'غير ملتحق', 'other' => 'أخرى']);
        $add('education_stages', ['university' => 'المرحلة الجامعية']);
        $add('education_levels', ['illiterate' => 'أمي/ة']);
        $add('kinship_types', ['other_relative' => 'قريب آخر', 'other' => 'أخرى']);
        $add('orphan_document_types', ['child_id' => 'هوية اليتيم', 'mother_id' => 'هوية الأم', 'missing_report' => 'إثبات الفقد']);
        DB::table('master_data_values')->whereIn('type_id', DB::table('master_data_types')->where('code', 'education_levels')->select('id'))->where('code', 'illiterate')->update(['sort_order' => 0]);
        cache()->forget('orphans.registry.options');
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: registration fields hold records.');
    }
};
