<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policy_corrections', function (Blueprint $table) {
            $table->id();

            // The incorrect original policy remains untouched.
            $table->foreignId('original_rule_id')
                ->constrained('leave_entitlement_rules')
                ->restrictOnDelete();

            // Approved replacement rules, stored until go-live.
            $table->jsonb('replacement_rules');

            $table->string('status', 20)
                ->default('pending');

            // Date will be set when AIMS officially launches.
            $table->date('go_live_date')->nullable();

            // Original policy approval date is unknown.
            $table->date('original_policy_date')->nullable();

            $table->text('reason');

            $table->timestampTz('applied_at')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('applied_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique('original_rule_id');
        });

        DB::statement("
            ALTER TABLE leave_policy_corrections
            ADD CONSTRAINT leave_policy_corrections_status_check
            CHECK (status IN ('pending', 'applied', 'cancelled'))
        ");

        DB::statement("
            ALTER TABLE leave_policy_corrections
            ADD CONSTRAINT leave_policy_corrections_applied_check
            CHECK (
                status <> 'applied'
                OR (
                    go_live_date IS NOT NULL
                    AND applied_at IS NOT NULL
                    AND applied_by IS NOT NULL
                )
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policy_corrections');
    }
};
