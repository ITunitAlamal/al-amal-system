<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: السماح بالحسابات دون بريد إلكتروني
        DB::statement(
            'ALTER TABLE users ALTER COLUMN email DROP NOT NULL'
        );
    }

    public function down(): void
    {
        // لا يمكن إعادة الإلزام إذا وُجدت حسابات بلا بريد.
        // نمنع التراجع غير الآمن دون حذف أو اختلاق بيانات.
        $hasNullEmails = DB::table('users')
            ->whereNull('email')
            ->exists();

        if ($hasNullEmails) {
            throw new RuntimeException(
                'لا يمكن جعل البريد إجباريًا لوجود حسابات دون بريد إلكتروني.'
            );
        }

        DB::statement(
            'ALTER TABLE users ALTER COLUMN email SET NOT NULL'
        );
    }
};
