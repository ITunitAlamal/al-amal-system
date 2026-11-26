<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_values', function (Blueprint $table) {
            $table->id();

            // نوع الثابت الذي تنتمي إليه القيمة
            $table->foreignId('type_id')
                ->constrained('master_data_types')
                ->restrictOnDelete();

            // رمز فريد داخل كل نوع ثابت
            $table->string('code', 100);

            // أسماء القيمة
            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();

            $table->text('description')->nullable();

            // قيمة مرجعية تابعة عند الحاجة
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('master_data_values')
                ->restrictOnDelete();

            // ترتيب العرض والحالة
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // بيانات إضافية للعرض فقط عند الحاجة
            $table->jsonb('metadata')->nullable();

            // توثيق المسؤول عن الإنشاء والتعديل
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // يمنع تكرار الرمز داخل النوع نفسه
            $table->unique(
                ['type_id', 'code'],
                'master_data_values_type_code_unique'
            );

            // تسريع تحميل القوائم المنسدلة النشطة
            $table->index(
                ['type_id', 'is_active', 'sort_order'],
                'master_data_values_type_active_order_index'
            );

            $table->index(
                'parent_id',
                'master_data_values_parent_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_values');
    }
};
