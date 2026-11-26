<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();

            // الفئة الوظيفية التي يتبع لها المسمى
            $table->foreignId('job_category_id')
                ->constrained('job_categories')
                ->restrictOnDelete();

            // الرمز الفريد للمسمى الوظيفي
            $table->string('code', 100)->unique();

            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
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
                ['job_category_id', 'is_active', 'sort_order'],
                'job_titles_category_active_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_titles');
    }
};
