<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->insertOrIgnore([
            'code' => 'maintenance_technician',
            'name' => 'فني الصيانة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // لا نحذف الدور تلقائيًا حفاظًا على تعيينات الموظفين.
    }
};
