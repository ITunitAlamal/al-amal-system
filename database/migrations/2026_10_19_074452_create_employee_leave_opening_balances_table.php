<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leave_opening_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            // نوع الإجازة من ثوابت النظام
            $table->foreignId('leave_type_value_id')
                ->constrained('master_data_values')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('balance_year');

            // الاستحقاق والرصيد الافتتاحي بالأيام
            $table->decimal('annual_entitlement_days', 8, 2)
                ->default(0);

            $table->decimal('opening_balance_days', 8, 2)
                ->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['employee_id', 'leave_type_value_id', 'balance_year'],
                'employee_leave_opening_unique'
            );
        });

        DB::statement('
            ALTER TABLE employee_leave_opening_balances
            ADD CONSTRAINT employee_leave_opening_amounts_check
            CHECK (
                annual_entitlement_days >= 0
                AND opening_balance_days >= 0
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_opening_balances');
    }
};
