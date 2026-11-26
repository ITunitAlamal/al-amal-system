<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE department_structure_changes DROP CONSTRAINT dsc_type_check');
        DB::statement("ALTER TABLE department_structure_changes ADD CONSTRAINT dsc_type_check CHECK (change_type IN ('create_department', 'create_unit', 'convert_to_unit', 'rename', 'retire', 'reparent', 'reactivate'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('department_structure_changes')->where('change_type', 'create_department')->exists()) {
            throw new RuntimeException('لا يمكن إزالة دعم إنشاء الأقسام مع وجود قرارات محفوظة.');
        }
        DB::statement('ALTER TABLE department_structure_changes DROP CONSTRAINT dsc_type_check');
        DB::statement("ALTER TABLE department_structure_changes ADD CONSTRAINT dsc_type_check CHECK (change_type IN ('create_unit', 'convert_to_unit', 'rename', 'retire', 'reparent', 'reactivate'))");
    }
};
