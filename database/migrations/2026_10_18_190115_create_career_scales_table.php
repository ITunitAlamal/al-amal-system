<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_scales', function (Blueprint $table) {
            $table->id();

            // رمز فريد ومستقر للسلم الوظيفي
            $table->string('code', 100)->unique();

            // الاسم والوصف
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('description')->nullable();

            // ترتيب العرض وحالة التفعيل
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // تتبع المسؤول عن الإنشاء والتعديل
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
                ['is_active', 'sort_order'],
                'career_scales_active_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_scales');
    }
};
