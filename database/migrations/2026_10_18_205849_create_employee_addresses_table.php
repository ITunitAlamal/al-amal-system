<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            // original: السكن الأصلي
            // current: السكن الحالي
            // previous: عنوان سابق
            $table->string('address_type', 30);

            // بيانات الموقع داخل قطاع غزة
            $table->string('governorate', 150);
            $table->string('city', 150);
            $table->string('area', 150)->nullable();
            $table->string('neighborhood', 255)->nullable();

            // تفاصيل إضافية
            $table->text('address_details')->nullable();
            $table->text('notes')->nullable();

            // فترة الإقامة إن توفرت
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->boolean('is_active')->default(true);

            // بيانات التدقيق
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'address_type'],
                'employee_addresses_employee_type_index'
            );
        });

        DB::statement("
            ALTER TABLE employee_addresses
            ADD CONSTRAINT employee_addresses_type_check
            CHECK (
                address_type IN ('original', 'current', 'previous')
            )
        ");

        DB::statement('
            ALTER TABLE employee_addresses
            ADD CONSTRAINT employee_addresses_dates_check
            CHECK (
                valid_from IS NULL
                OR valid_to IS NULL
                OR valid_to >= valid_from
            )
        ');

        // عنوان أصلي نشط واحد وعنوان حالي نشط واحد لكل موظف.
        // العناوين السابقة يمكن أن تكون متعددة.
        DB::statement("
            CREATE UNIQUE INDEX employee_addresses_one_active_type
            ON employee_addresses (employee_id, address_type)
            WHERE is_active = true
              AND address_type IN ('original', 'current')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_addresses');
    }
};
