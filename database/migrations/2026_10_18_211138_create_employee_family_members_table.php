<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_family_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            // صلة القرابة: spouse, son, daughter, etc.
            $table->string('relationship_code', 100);

            // البيانات الشخصية
            $table->string('full_name_ar', 255);
            $table->string('full_name_en', 255)->nullable();
            $table->string('national_id', 100)->nullable();
            $table->date('date_of_birth')->nullable();

            // معلومات التواصل
            $table->string('phone', 50)->nullable();
            $table->string('notes', 1000)->nullable();

            // هل الشخص مُعال من الموظف؟
            $table->boolean('is_dependent')->default(false);

            // حالة السجل
            $table->boolean('is_active')->default(true);

            // توثيق الإنشاء والتعديل
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
                ['employee_id', 'relationship_code'],
                'employee_family_relationship_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_family_members');
    }
};
