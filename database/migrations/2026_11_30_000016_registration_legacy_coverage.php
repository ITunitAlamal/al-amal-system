<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy field coverage of family registration (owner prompt 2026-11-02, legacy report
 * «حقول تسجيل الأيتام والأوصياء والأسر في النظام القديم» §9). Additive only:
 *
 * - Housing details the old system kept (rooms, building type/condition, furnishing, area) on the current address;
 *   building/furnishing lists are configurable in «ثوابت النظام».
 * - Income per source (the old fixed-income components): one row per source with an explicit amount status, so a
 *   missing amount is «unknown», never zero, and an unknown currency stays null.
 * - The old file number on the household (NOT unique: the old `family_id` repeats).
 * - registry_legacy_references: original legacy row ids per AIMS record for a future migration (ids only, no personal
 *   data), unique per source row and target type.
 * - Income sources «شؤون اجتماعية» and «مخصصات الشهداء والجرحى» (old fixed-income categories).
 */
return new class extends Migration
{
    private const LISTS = [
        'building_types' => ['نوع المبنى', ['concrete' => 'باطون', 'asbestos' => 'أسبست', 'zinc' => 'زينكو', 'tent' => 'خيمة', 'caravan' => 'كرفان', 'other' => 'أخرى']],
        'building_conditions' => ['حالة المبنى', ['good' => 'جيدة', 'fair' => 'متوسطة', 'poor' => 'سيئة', 'partly_damaged' => 'متضرر جزئيًا', 'destroyed' => 'مدمر']],
        'furnishing_conditions' => ['حالة الأثاث', ['good' => 'جيدة', 'fair' => 'متوسطة', 'poor' => 'سيئة', 'none' => 'لا يوجد']],
    ];

    public function up(): void
    {
        $group = DB::table('master_data_types')->where('code', 'housing_tenures')->value('group_id');
        foreach (self::LISTS as $code => [$name, $values]) {
            $type = DB::table('master_data_types')->where('code', $code)->value('id')
                ?? DB::table('master_data_types')->insertGetId(['group_id' => $group, 'code' => $code, 'name_ar' => $name, 'sort_order' => 90, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->values((int) $type, $values);
        }
        $income = DB::table('master_data_types')->where('code', 'income_sources')->value('id');
        if ($income) {
            $this->values((int) $income, ['social_affairs' => 'شؤون اجتماعية', 'martyrs_wounded' => 'مخصصات الشهداء والجرحى']);
        }

        Schema::table('registry_addresses', function (Blueprint $t): void {
            $t->unsignedSmallInteger('rooms_count')->nullable();
            $t->decimal('building_area_sqm', 8, 2)->nullable();
            $t->foreignId('building_type_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('building_condition_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('furnishing_condition_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE registry_addresses ADD CONSTRAINT registry_addresses_housing CHECK ((rooms_count IS NULL OR rooms_count <= 100) AND (building_area_sqm IS NULL OR building_area_sqm > 0))');

        Schema::table('households', function (Blueprint $t): void {
            $t->string('legacy_file_no', 40)->nullable()->index();
        });

        Schema::create('economic_income_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('economic_assessment_id')->constrained('economic_assessments')->restrictOnDelete();
            $t->foreignId('income_source_value_id')->nullable()->constrained('master_data_values')->restrictOnDelete();
            $t->string('label', 200)->nullable();
            $t->string('amount_status', 10)->default('unknown');
            $t->decimal('amount', 12, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('period', 10)->nullable();
            $t->string('note', 500)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE economic_income_items ADD CONSTRAINT economic_income_items_amount CHECK (
            amount_status IN ('known', 'none', 'unknown') AND ((amount_status = 'known') = (amount IS NOT NULL)) AND (amount IS NULL OR amount >= 0)
            AND (period IS NULL OR period IN ('monthly', 'weekly', 'yearly', 'one_off')) AND (income_source_value_id IS NOT NULL OR label IS NOT NULL))");

        Schema::create('registry_legacy_references', function (Blueprint $t): void {
            $t->id();
            $t->string('source_system', 40);
            $t->string('source_table', 80);
            $t->string('source_id', 40);
            $t->string('subject_type', 40);
            $t->unsignedBigInteger('subject_id');
            $t->jsonb('flags')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['source_system', 'source_table', 'source_id', 'subject_type'], 'registry_legacy_references_source_uniq');
            $t->index(['subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE registry_legacy_references ADD CONSTRAINT registry_legacy_references_subject CHECK (subject_type IN
            ('persons', 'households', 'household_members', 'orphans', 'orphan_parent_links', 'orphan_guardianships', 'registry_documents', 'portal_applications'))");
    }

    /** Rolled back only while nothing uses the new columns, rows or list values. */
    public function down(): void
    {
        $values = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->whereIn('t.code', array_keys(self::LISTS))->pluck('v.id');
        if (DB::table('economic_income_items')->exists() || DB::table('registry_legacy_references')->exists() || DB::table('households')->whereNotNull('legacy_file_no')->exists()
            || DB::table('registry_addresses')->where(fn ($q) => $q->whereNotNull('rooms_count')->orWhereNotNull('building_area_sqm')->orWhereNotNull('building_type_value_id')
                ->orWhereNotNull('building_condition_value_id')->orWhereNotNull('furnishing_condition_value_id'))->exists()) {
            throw new RuntimeException('توجد بيانات في حقول السكن أو مصادر الدخل أو المراجع القديمة؛ لا يمكن التراجع.');
        }
        Schema::dropIfExists('registry_legacy_references');
        Schema::dropIfExists('economic_income_items');
        Schema::table('households', fn (Blueprint $t) => $t->dropColumn('legacy_file_no'));
        DB::statement('ALTER TABLE registry_addresses DROP CONSTRAINT IF EXISTS registry_addresses_housing');
        Schema::table('registry_addresses', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('furnishing_condition_value_id');
            $t->dropConstrainedForeignId('building_condition_value_id');
            $t->dropConstrainedForeignId('building_type_value_id');
            $t->dropColumn(['rooms_count', 'building_area_sqm']);
        });
        DB::table('master_data_values')->whereIn('id', $values)->delete();
        DB::table('master_data_types')->whereIn('code', array_keys(self::LISTS))->delete();
        // The two income sources stay unless unused.
        $income = DB::table('master_data_types')->where('code', 'income_sources')->value('id');
        DB::table('master_data_values')->where('type_id', $income)->whereIn('code', ['social_affairs', 'martyrs_wounded'])
            ->whereNotExists(fn ($q) => $q->from('economic_assessments')->whereRaw('income_source_ids::jsonb @> to_jsonb(master_data_values.id)'))->delete();
    }

    /** @param array<string, string> $values */
    private function values(int $typeId, array $values): void
    {
        $sort = (int) DB::table('master_data_values')->where('type_id', $typeId)->max('sort_order');
        foreach ($values as $code => $name) {
            if (! DB::table('master_data_values')->where('type_id', $typeId)->where('code', $code)->exists()) {
                DB::table('master_data_values')->insert(['type_id' => $typeId, 'code' => $code, 'name_ar' => $name, 'sort_order' => ++$sort, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
};
