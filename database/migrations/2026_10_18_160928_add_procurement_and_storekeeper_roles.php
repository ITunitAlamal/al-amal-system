<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $roles = [
            [
                'code' => 'procurement_officer',
                'name' => 'موظف المشتريات',
            ],
            [
                'code' => 'storekeeper',
                'name' => 'أمين المخازن',
            ],
        ];

        foreach ($roles as $role) {
            $exists = DB::table('roles')
                ->where('code', $role['code'])
                ->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'code' => $role['code'],
                    'name' => $role['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // لا نحذف الأدوار تلقائيًا؛ فقد تكون مرتبطة بموظفين.
        // أي إزالة مستقبلية تحتاج مراجعة التعيينات أولًا.
    }
};
