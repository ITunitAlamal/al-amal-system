<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // الربط بحساب الدخول الحالي دون تغييره
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();

            // الرقم الوظيفي
            $table->string('employee_number', 50)->unique();

            // البيانات الشخصية الأساسية
            $table->string('first_name_ar', 150);
            $table->string('father_name_ar', 150)->nullable();
            $table->string('grandfather_name_ar', 150)->nullable();
            $table->string('family_name_ar', 150);

            $table->string('full_name_en', 255)->nullable();
            $table->string('national_id', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();

            $table->string('phone', 50)->nullable();
            $table->string('alternative_phone', 50)->nullable();
            $table->string('personal_email', 255)->nullable();

            // الارتباط بالهيكل الإداري الحالي
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->restrictOnDelete();

            // الفئة والمسمى الوظيفي
            $table->foreignId('job_category_id')
                ->nullable()
                ->constrained('job_categories')
                ->restrictOnDelete();

            $table->foreignId('job_title_id')
                ->nullable()
                ->constrained('job_titles')
                ->restrictOnDelete();

            // الدرجة الوظيفية
            $table->foreignId('job_grade_id')
                ->nullable()
                ->constrained('job_grades')
                ->restrictOnDelete();

            // التعيين والوضع الوظيفي
            $table->date('hire_date')->nullable();
            $table->date('work_start_date')->nullable();
            $table->string('employment_status', 50)
                ->default('active');

            $table->text('notes')->nullable();

            // التدقيق
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'employment_status']);
            $table->index(['job_category_id', 'job_title_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
