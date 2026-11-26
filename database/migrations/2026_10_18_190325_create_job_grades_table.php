<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_grades', function (Blueprint $table) {
            $table->id();

            // السلم الوظيفي
            $table->foreignId('career_scale_id')
                ->constrained('career_scales')
                ->restrictOnDelete();

            // رمز فريد داخل السلم الوظيفي
            $table->string('code', 100);

            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('description')->nullable();

            // المستوى وترتيب العرض
            $table->unsignedInteger('grade_level')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            // نطاق الراتب الاختياري
            $table->decimal('salary_min', 15, 2)->nullable();
            $table->decimal('salary_max', 15, 2)->nullable();

            // المفتاح الأساسي لجدول العملات هو code
            $table->string('currency_code', 3)->nullable();

            $table->foreign('currency_code')
                ->references('code')
                ->on('currencies')
                ->restrictOnDelete();

            $table->boolean('is_active')->default(true);

            // سجل المسؤول عن الإضافة والتعديل
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
                ['career_scale_id', 'code'],
                'job_grades_scale_code_unique'
            );

            $table->index(
                ['career_scale_id', 'is_active', 'sort_order'],
                'job_grades_scale_active_order_index'
            );
        });

        // قيود PostgreSQL لمنع القيم غير الصحيحة
        DB::statement('
            ALTER TABLE job_grades
            ADD CONSTRAINT job_grades_salary_min_nonnegative
            CHECK (salary_min IS NULL OR salary_min >= 0)
        ');

        DB::statement('
            ALTER TABLE job_grades
            ADD CONSTRAINT job_grades_salary_max_nonnegative
            CHECK (salary_max IS NULL OR salary_max >= 0)
        ');

        DB::statement('
            ALTER TABLE job_grades
            ADD CONSTRAINT job_grades_salary_range_valid
            CHECK (
                salary_min IS NULL
                OR salary_max IS NULL
                OR salary_max >= salary_min
            )
        ');

        DB::statement('
            ALTER TABLE job_grades
            ADD CONSTRAINT job_grades_salary_currency_required
            CHECK (
                (salary_min IS NULL AND salary_max IS NULL)
                OR currency_code IS NOT NULL
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('job_grades');
    }
};
