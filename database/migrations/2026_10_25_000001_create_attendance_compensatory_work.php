<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_emergency_holidays', function (Blueprint $t) {
            $t->id();
            $t->date('holiday_date');
            $t->string('name', 180);
            $t->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $t->string('reason', 2000);
            $t->string('status', 20)->default('pending');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestamps();
            $t->index(['holiday_date', 'status']);
        });
        Schema::create('attendance_compensatory_work', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $t->date('work_date');
            $t->string('status', 20)->default('pending');
            $t->text('reason');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('credited_at')->nullable();
            $t->foreignId('leave_transaction_id')->nullable()->constrained('employee_leave_transactions')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date'], 'comp_work_employee_date_unique');
            $t->index(['status', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_compensatory_work');
        Schema::dropIfExists('attendance_emergency_holidays');
    }
};
