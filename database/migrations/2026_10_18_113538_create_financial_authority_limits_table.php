<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_authority_limits', function (Blueprint $table) {
            $table->id();

            $table->string('authority_code', 60);
            $table->string('action_code', 60);

            // ILS: العملة المعتمدة
            $table->char('currency', 3)->default('ILS');

            // تخزين المبالغ بالأغورات لتجنب أخطاء الكسور
            $table->unsignedBigInteger('limit_agorot')->nullable();

            $table->boolean('is_unlimited')->default(false);
            $table->boolean('is_active')->default(false);

            $table->text('decision_reference')->nullable();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique([
                'authority_code',
                'action_code',
                'currency',
            ], 'financial_authority_unique');
        });

        Schema::create('financial_authority_limit_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('financial_authority_limit_id')
                ->constrained('financial_authority_limits')
                ->restrictOnDelete();

            $table->foreignId('changed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->json('before_values')->nullable();
            $table->json('after_values');

            $table->string('change_reason', 500);

            $table->timestamp('changed_at');

            $table->timestamps();

            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_authority_limit_histories');
        Schema::dropIfExists('financial_authority_limits');
    }
};
