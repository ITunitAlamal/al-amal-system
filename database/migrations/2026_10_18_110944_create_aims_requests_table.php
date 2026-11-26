<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aims_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            // نوع الطلب
            $table->string('request_type', 60);

            // بيانات الطلب الأساسية
            $table->string('title', 200);
            $table->text('description')->nullable();

            // صاحب الطلب وقسمه عند الإنشاء
            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('department_id')
                ->constrained('departments')
                ->restrictOnDelete();

            // draft / submitted / in_progress /
            // completed / rejected / cancelled
            $table->string('status', 30)->default('draft');

            // منع تعارض التحديثات المتزامنة
            $table->unsignedInteger('revision')->default(1);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['requested_by', 'status']);
            $table->index(['department_id', 'status']);
            $table->index(['request_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aims_requests');
    }
};
