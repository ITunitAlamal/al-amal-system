<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();

            // رمز فريد ومستقر لكل فئة وظيفية
            $table->string('code', 100)->unique();

            // أسماء الفئة
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();

            $table->text('description')->nullable();

            // الترتيب والحالة
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // المستخدم المسؤول عن الإضافة والتعديل
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // ترتيب عرض الفئات النشطة
            $table->index(
                ['is_active', 'sort_order'],
                'job_categories_active_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_categories');
    }
};
