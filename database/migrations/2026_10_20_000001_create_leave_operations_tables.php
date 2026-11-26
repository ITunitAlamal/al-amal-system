<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_module_settings', function (Blueprint $t) {
            $t->id();
            $t->string('counting_mode', 24)->nullable(); // calendar or working
            $t->jsonb('working_weekdays')->nullable(); // ISO 1=Mon ... 7=Sun
            $t->string('approval_mode', 32)->nullable(); // hr_only or manager_then_hr
            $t->string('grant_mode', 24)->nullable(); // full_year (only when approved)
            $t->boolean('requests_enabled')->default(false);
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE leave_module_settings ADD CONSTRAINT leave_settings_modes_check CHECK ((counting_mode IS NULL OR counting_mode IN ('calendar','working')) AND (approval_mode IS NULL OR approval_mode IN ('hr_only','manager_then_hr')) AND (grant_mode IS NULL OR grant_mode = 'full_year'))");

        Schema::create('leave_holidays', function (Blueprint $t) {
            $t->id();
            $t->date('holiday_date')->unique();
            $t->string('name_ar');
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('employee_leave_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('leave_type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->unsignedSmallInteger('balance_year');
            $t->timestamps();
            $t->unique(['employee_id', 'leave_type_value_id', 'balance_year'], 'leave_account_unique');
        });
        Schema::create('employee_leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('leave_type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->decimal('requested_days', 8, 2)->nullable();
            $t->string('status', 32)->default('draft');
            $t->text('reason');
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status', 'start_date']);
        });
        DB::statement("ALTER TABLE employee_leave_requests ADD CONSTRAINT leave_request_status_check CHECK (status IN ('draft','submitted','manager_approved','approved','rejected','cancelled'))");
        DB::statement('ALTER TABLE employee_leave_requests ADD CONSTRAINT leave_request_dates_check CHECK (end_date >= start_date)');
        DB::statement('ALTER TABLE employee_leave_requests ADD CONSTRAINT leave_request_days_check CHECK (requested_days IS NULL OR requested_days > 0)');

        Schema::create('employee_leave_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('employee_leave_accounts')->restrictOnDelete();
            $t->foreignId('leave_request_id')->nullable()->constrained('employee_leave_requests')->restrictOnDelete();
            $t->decimal('amount_days', 10, 2);
            $t->string('kind', 32); // annual_grant, adjustment, leave_taken, reversal
            $t->string('external_key', 160)->unique();
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['account_id', 'kind']);
        });
        DB::statement('ALTER TABLE employee_leave_transactions ADD CONSTRAINT leave_tx_nonzero_check CHECK (amount_days <> 0)');

        Schema::create('employee_leave_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('leave_request_id')->constrained('employee_leave_requests')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('from_status', 32);
            $t->string('to_status', 32);
            $t->text('comment')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_approvals');
        Schema::dropIfExists('employee_leave_transactions');
        Schema::dropIfExists('employee_leave_requests');
        Schema::dropIfExists('employee_leave_accounts');
        Schema::dropIfExists('leave_holidays');
        Schema::dropIfExists('leave_module_settings');
    }
};
