<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {

            // المرحلة الأولى: البيانات الشخصية
            $table->string('place_of_birth', 255)->nullable();
            $table->unsignedBigInteger('marital_status_value_id')
                ->nullable();

            // المرحلة الثانية: التعليم
            $table->boolean('has_qualifications')->nullable();

            // المرحلة الثالثة: بيانات العمل
            $table->unsignedBigInteger('employment_term_value_id')
                ->nullable();

            $table->unsignedBigInteger('administrative_level_value_id')
                ->nullable();

            // المرحلة الرابعة: بيانات العائلة
            $table->boolean('has_family_members')->nullable();

            // المرحلة الخامسة: إكمال التسجيل
            $table->string('onboarding_status', 30)
                ->default('draft');

            $table->unsignedSmallInteger('onboarding_step')
                ->default(1);

            $table->timestampTz('onboarding_completed_at')
                ->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('marital_status_value_id')
                ->references('id')
                ->on('master_data_values')
                ->restrictOnDelete();

            $table->foreign('employment_term_value_id')
                ->references('id')
                ->on('master_data_values')
                ->restrictOnDelete();

            $table->foreign('administrative_level_value_id')
                ->references('id')
                ->on('master_data_values')
                ->restrictOnDelete();
        });

        // حالة التسجيل يجب أن تكون ضمن القيم المعتمدة.
        DB::statement("
            ALTER TABLE employees
            ADD CONSTRAINT employees_onboarding_status_check
            CHECK (
                onboarding_status IN (
                    'draft',
                    'pending_activation',
                    'completed'
                )
            )
        ");

        DB::statement('
            ALTER TABLE employees
            ADD CONSTRAINT employees_onboarding_step_check
            CHECK (onboarding_step BETWEEN 1 AND 5)
        ');
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE employees
            DROP CONSTRAINT IF EXISTS employees_onboarding_step_check
        ');

        DB::statement('
            ALTER TABLE employees
            DROP CONSTRAINT IF EXISTS employees_onboarding_status_check
        ');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['marital_status_value_id']);
            $table->dropForeign(['employment_term_value_id']);
            $table->dropForeign(['administrative_level_value_id']);

            $table->dropColumn([
                'place_of_birth',
                'marital_status_value_id',
                'has_qualifications',
                'employment_term_value_id',
                'administrative_level_value_id',
                'has_family_members',
                'onboarding_status',
                'onboarding_step',
                'onboarding_completed_at',
            ]);
        });
    }
};
