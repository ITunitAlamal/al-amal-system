<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE leave_module_settings DROP CONSTRAINT IF EXISTS leave_settings_modes_check');
        DB::statement("ALTER TABLE leave_module_settings ADD CONSTRAINT leave_settings_modes_check CHECK ((counting_mode IS NULL OR counting_mode IN ('calendar','working')) AND (approval_mode IS NULL OR approval_mode IN ('hr_only','manager_then_hr','department_or_executive')) AND (grant_mode IS NULL OR grant_mode = 'full_year'))");
        Schema::create('leave_hr_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('leave_request_id')->constrained('employee_leave_requests')->restrictOnDelete();
            $t->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $t->string('event', 32);
            $t->timestamps();
            $t->unique(['leave_request_id', 'recipient_user_id', 'event'], 'leave_hr_notif_unique');
            $t->index(['recipient_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_hr_notifications');
        DB::statement('ALTER TABLE leave_module_settings DROP CONSTRAINT IF EXISTS leave_settings_modes_check');
        DB::statement("ALTER TABLE leave_module_settings ADD CONSTRAINT leave_settings_modes_check CHECK ((counting_mode IS NULL OR counting_mode IN ('calendar','working')) AND (approval_mode IS NULL OR approval_mode IN ('hr_only','manager_then_hr')) AND (grant_mode IS NULL OR grant_mode = 'full_year'))");
    }
};
