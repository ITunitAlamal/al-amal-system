<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_drafts', function (Blueprint $table) {
            $table->id();

            // المستخدم صاحب المسودة
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // معرّف ثابت للمسودة
            $table->uuid('draft_key');

            // نوع النموذج: صيانة، شراء، إلخ
            $table->string('form_type', 60);

            // محتوى المسودة - يستخدم لاحقًا للحقول المسموحة فقط
            $table->json('payload');

            // رقم النسخة لمنع تعارض التعديلات
            $table->unsignedInteger('version')->default(1);

            // وقت آخر حفظ مؤكد على الخادم
            $table->timestamp('last_saved_at')->nullable();

            $table->timestamps();

            // لا يمكن تكرار نفس المسودة للمستخدم
            $table->unique(['user_id', 'draft_key']);

            // تسريع البحث عن مسودات المستخدم
            $table->index(['user_id', 'form_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_drafts');
    }
};
