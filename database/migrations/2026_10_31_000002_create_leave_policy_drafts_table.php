<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('leave_policy_drafts')) {
            return;
        }
        Schema::create('leave_policy_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_correction_id')->unique();
            $table->foreignId('employment_term_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->foreignId('leave_type_value_id')->constrained('master_data_values')->restrictOnDelete();
            $table->jsonb('tiers');
            $table->string('status', 40)->default('approved_pending_launch');
            $table->date('go_live_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Approved leave policies must be preserved.');
    }
};
