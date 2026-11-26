<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The source sheet holds free text in some name cells (up to ~750 characters), which overflowed the varchar limits
 * of the staged copy. Names, identities as written and category keys become `text`: a value is never truncated.
 * varchar → text is binary compatible in PostgreSQL (no table rewrite); staged rows are unchanged.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'child_name', 'child_name_search', 'father_name', 'father_name_search', 'mother_name', 'mother_name_search', 'guardian_name', 'guardian_name_search',
        'child_national_id', 'father_national_id', 'mother_national_id', 'guardian_national_id', 'mother_status_key', 'guardian_relation_key', 'governorate_key',
        'city_key', 'school_grade_key', 'academic_performance_key', 'health_status_key', 'mother_education_key',
    ];

    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS orphan_import_rows_child_search_prefix');
        DB::statement('DROP INDEX IF EXISTS orphan_import_rows_guardian_search_prefix');
        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE orphan_import_rows ALTER COLUMN {$column} TYPE text");
        }
        DB::statement('CREATE INDEX orphan_import_rows_child_search_prefix ON orphan_import_rows (batch_id, child_name_search text_pattern_ops)');
        DB::statement('CREATE INDEX orphan_import_rows_guardian_search_prefix ON orphan_import_rows (batch_id, guardian_name_search text_pattern_ops)');
    }

    public function down(): void
    {
        // Narrowing back would truncate staged evidence; it is refused while any value is longer than 255 characters.
        foreach (self::COLUMNS as $column) {
            if (DB::table('orphan_import_rows')->whereRaw("char_length({$column}) > 255")->exists()) {
                throw new RuntimeException("orphan_import_rows.{$column} holds values longer than 255 characters; not narrowed.");
            }
        }
        DB::statement('DROP INDEX IF EXISTS orphan_import_rows_child_search_prefix');
        DB::statement('DROP INDEX IF EXISTS orphan_import_rows_guardian_search_prefix');
        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE orphan_import_rows ALTER COLUMN {$column} TYPE varchar(255)");
        }
        DB::statement('CREATE INDEX orphan_import_rows_child_search_prefix ON orphan_import_rows (batch_id, child_name_search varchar_pattern_ops)');
        DB::statement('CREATE INDEX orphan_import_rows_guardian_search_prefix ON orphan_import_rows (batch_id, guardian_name_search varchar_pattern_ops)');
    }
};
