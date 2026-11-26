<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            // المستوى التعليمي والمؤهل
            $table->string('qualification_name', 255);
            $table->string('degree_level', 100)->nullable();

            // المؤسسة التعليمية والتخصص
            $table->string('institution_name', 255);
            $table->string('specialization', 255)->nullable();

            // الدولة التي صدرت منها الشهادة
            $table->string('issuing_country_code', 3)->nullable();

            // بيانات الدراسة والتخرج
            $table->date('study_start_date')->nullable();
            $table->date('graduation_date')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();

            // التقدير والمعدل
            $table->string('grade', 100)->nullable();
            $table->decimal('gpa', 6, 3)->nullable();
            $table->string('gpa_scale', 30)->nullable();

            $table->string('certificate_number', 150)->nullable();
            $table->text('notes')->nullable();

            // الحالة الافتراضية للمؤهل
            $table->boolean('is_highest_qualification')
                ->default(false);

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

            $table->index('employee_id');
        });

        DB::statement('
            ALTER TABLE employee_qualifications
            ADD CONSTRAINT employee_qualifications_dates_valid
            CHECK (
                study_start_date IS NULL
                OR graduation_date IS NULL
                OR graduation_date >= study_start_date
            )
        ');

        DB::statement('
            CREATE UNIQUE INDEX employee_qualifications_one_highest
            ON employee_qualifications (employee_id)
            WHERE is_highest_qualification = true
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_qualifications');
    }
};
