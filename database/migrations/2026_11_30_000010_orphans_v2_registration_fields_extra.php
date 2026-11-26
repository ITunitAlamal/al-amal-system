<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Registration fields added from AIMS_Comprehensive_Orphan_Guardian_Registration_Fields_Prompt (2026-11-01).
 * Additive only: nullable columns, CHECK constraints, missing reference values, and the identity-number pattern
 * moved into «ثوابت النظام» (metadata.pattern of the Palestinian ID value).
 */
return new class extends Migration
{
    private const YNU = "('yes','no','unknown')";

    private const NEW_COLUMNS = [
        'persons' => ['marital_status_since'],
        'person_status_events' => ['confidence', 'official_cause'],
        'economic_assessments' => ['benefits_status', 'benefits_note', 'caregiving_capacity', 'caregiving_note', 'rent_amount', 'assessed_status_value_id'],
        'households' => ['special_needs_count', 'head_relation_value_id', 'contact_phone', 'last_moved_on'],
        'registry_addresses' => ['host_note', 'accessibility_needs', 'source'],
        'care_records' => ['assessed_on'],
        'orphans' => ['loss_circumstances'],
        'portal_applications' => ['declared_by_name'],
    ];

    public function up(): void
    {
        $master = fn (Blueprint $t, string $column) => $t->foreignId($column)->nullable()->constrained('master_data_values')->restrictOnDelete();

        Schema::table('persons', fn (Blueprint $t) => $t->date('marital_status_since')->nullable());

        Schema::table('person_status_events', function (Blueprint $t): void {
            $t->string('confidence', 8)->nullable();
            $t->text('official_cause')->nullable(); // encrypt(); entered by staff, separate from the reported cause
        });
        DB::statement("ALTER TABLE person_status_events ADD CONSTRAINT person_status_events_confidence CHECK (confidence IS NULL OR confidence IN ('high','medium','low'))");

        Schema::table('economic_assessments', function (Blueprint $t) use ($master): void {
            $t->string('benefits_status', 8)->nullable();
            $t->string('benefits_note', 500)->nullable();
            $t->string('caregiving_capacity', 8)->nullable();
            $t->string('caregiving_note', 500)->nullable();
            $t->decimal('rent_amount', 12, 2)->nullable();
            $master($t, 'assessed_status_value_id');
        });
        DB::statement('ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_benefits CHECK (benefits_status IS NULL OR benefits_status IN '.self::YNU.')');
        DB::statement("ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_caregiving CHECK (caregiving_capacity IS NULL OR caregiving_capacity IN ('yes','partial','no','unknown'))");
        DB::statement('ALTER TABLE economic_assessments ADD CONSTRAINT economic_assessments_rent CHECK (rent_amount IS NULL OR (rent_amount >= 0 AND currency IS NOT NULL))');

        Schema::table('households', function (Blueprint $t) use ($master): void {
            $t->unsignedSmallInteger('special_needs_count')->nullable();
            $master($t, 'head_relation_value_id');
            $t->string('contact_phone', 20)->nullable();
            $t->date('last_moved_on')->nullable();
        });
        DB::statement('ALTER TABLE households ADD CONSTRAINT households_special_needs CHECK (special_needs_count IS NULL OR members_count IS NULL OR special_needs_count <= members_count)');

        Schema::table('registry_addresses', function (Blueprint $t): void {
            $t->string('host_note', 300)->nullable();
            $t->string('accessibility_needs', 500)->nullable();
            $t->string('source', 20)->nullable();
        });
        DB::statement("ALTER TABLE registry_addresses ADD CONSTRAINT registry_addresses_source CHECK (source IS NULL OR source IN ('self_reported','document','field_visit','other'))");

        Schema::table('care_records', fn (Blueprint $t) => $t->date('assessed_on')->nullable());
        Schema::table('orphans', fn (Blueprint $t) => $t->text('loss_circumstances')->nullable()); // model cast «encrypted»
        Schema::table('portal_applications', fn (Blueprint $t) => $t->string('declared_by_name', 200)->nullable());

        $this->referenceValues();
    }

    /** Missing codes only; existing values and names are never replaced. */
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
        $add('economic_statuses', ['good' => 'جيد', 'average' => 'متوسط', 'weak' => 'ضعيف', 'very_hard' => 'صعب جدًا']);
        $add('income_sources', ['wages' => 'أجور ورواتب', 'occasional_work' => 'عمل متقطع', 'pension' => 'معاش أو راتب تقاعدي', 'savings' => 'مدخرات',
            'assistance' => 'مساعدات', 'remittances' => 'حوالات من الخارج', 'none' => 'لا يوجد دخل', 'other' => 'أخرى']);
        $add('housing_tenures', ['relatives' => 'لدى أقارب', 'hosted' => 'استضافة لدى أسرة', 'camp' => 'مخيم', 'shelter' => 'مركز إيواء']);
        $add('kinship_types', ['grandfather' => 'جد', 'grandmother' => 'جدة']);
        $add('education_levels', ['other' => 'أخرى']);

        // «جد/ة» is replaced by «جد» and «جدة» only when nothing (row or saved request) refers to it.
        $grandparent = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'kinship_types')->where('v.code', 'grandparent')->value('v.id');
        if ($grandparent && ! $this->referenced((int) $grandparent)) {
            DB::table('master_data_values')->where('id', $grandparent)->update(['is_active' => false, 'updated_at' => now()]);
        }

        // Identity-number syntax is a constant, editable with the value (default: 9 digits).
        $id = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'identity_document_types')->where('v.code', 'palestinian_id')->first(['v.id', 'v.metadata']);
        if ($id) {
            $meta = json_decode((string) $id->metadata, true) ?: [];
            if (! isset($meta['pattern'])) {
                DB::table('master_data_values')->where('id', $id->id)->update(['metadata' => json_encode($meta + ['pattern' => '^\d{9}$', 'pattern_hint' => '9 أرقام'], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        }
    }

    /** True if any foreign key to master_data_values, or any saved request snapshot, uses this value. */
    private function referenced(int $valueId): bool
    {
        $columns = DB::select("SELECT cl.relname AS tbl, a.attname AS col FROM pg_constraint c
            JOIN pg_class cl ON cl.oid = c.conrelid JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid = 'master_data_values'::regclass");
        foreach ($columns as $column) {
            if ($column->tbl !== 'master_data_values' && DB::table($column->tbl)->where($column->col, $valueId)->exists()) {
                return true;
            }
        }
        foreach (DB::table('portal_applications')->whereNotNull('form_data')->pluck('form_data') as $encrypted) {
            try {
                if (preg_match('/"(?:kinship_value_id|relationship_value_id|relation_value_id)":"?'.$valueId.'\b/', Crypt::decryptString($encrypted))) {
                    return true;
                }
            } catch (Throwable) {
                return true; // unreadable snapshot: keep the value
            }
        }

        return false;
    }

    public function down(): void
    {
        foreach (self::NEW_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereNotNull($column)->exists()) {
                    throw new RuntimeException("Forward-only: {$table}.{$column} holds data.");
                }
            }
        }
        foreach (self::NEW_COLUMNS as $table => $columns) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn($columns));
        }
    }
};
