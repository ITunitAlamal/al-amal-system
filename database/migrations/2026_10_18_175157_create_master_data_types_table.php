<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_data_types', function (Blueprint $table) {
            $table->id();

            // المجموعة الرئيسية التي يتبع لها نوع الثابت
            $table->foreignId('group_id')
                ->constrained('master_data_groups')
                ->restrictOnDelete();

            // رمز فريد ومستقر لنوع الثابت
            $table->string('code', 100)->unique();

            $table->string('name_ar', 255);
            $table->string('name_en', 255)->nullable();
            $table->text('description')->nullable();

            // ترتيب الظهور وحالة التفعيل
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            // توثيق المستخدم المسؤول عن الإنشاء والتعديل
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
                ['group_id', 'is_active', 'sort_order'],
                'master_data_types_group_active_order_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_types');
    }
};
