<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_entitlement_rules', function (Blueprint $table) {
            $table->id();

            // References to approved system master data
            $table->foreignId('employment_term_value_id')
                ->constrained('master_data_values')
                ->restrictOnDelete();

            $table->foreignId('leave_type_value_id')
                ->constrained('master_data_values')
                ->restrictOnDelete();

            // Employee service duration, measured in completed months.
            // Minimum inclusive, maximum exclusive.
            $table->unsignedInteger('min_service_months')
                ->default(0);

            // NULL means no upper service limit.
            $table->unsignedInteger('max_service_months')
                ->nullable();

            // Annual entitlement in days.
            $table->decimal('annual_entitlement_days', 8, 2);

            // Policy effective date range:
            // effective_from inclusive; effective_until exclusive.
            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('code', 100)->unique();
            $table->string('name_ar', 255);
            $table->text('notes')->nullable();

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
                [
                    'employment_term_value_id',
                    'leave_type_value_id',
                    'is_active',
                ],
                'leave_rules_lookup_idx'
            );

            $table->index(
                ['effective_from', 'effective_until'],
                'leave_rules_effective_idx'
            );
        });

        DB::statement('
            ALTER TABLE leave_entitlement_rules
            ADD CONSTRAINT leave_rules_service_range_check
            CHECK (
                min_service_months >= 0
                AND (
                    max_service_months IS NULL
                    OR max_service_months > min_service_months
                )
            )
        ');

        DB::statement('
            ALTER TABLE leave_entitlement_rules
            ADD CONSTRAINT leave_rules_effective_dates_check
            CHECK (
                effective_until IS NULL
                OR effective_until > effective_from
            )
        ');

        DB::statement('
            ALTER TABLE leave_entitlement_rules
            ADD CONSTRAINT leave_rules_entitlement_check
            CHECK (annual_entitlement_days >= 0)
        ');

        // PostgreSQL exclusion constraint prevents two ACTIVE
        // policies from overlapping in both seniority and dates
        // for the same employment term and leave type.
        DB::statement(
            'CREATE EXTENSION IF NOT EXISTS btree_gist'
        );

        DB::statement("
            ALTER TABLE leave_entitlement_rules
            ADD CONSTRAINT leave_rules_no_active_overlap
            EXCLUDE USING gist (
                employment_term_value_id WITH =,
                leave_type_value_id WITH =,
                int4range(
                    min_service_months::integer,
                    max_service_months::integer,
                    '[)'
                ) WITH &&,
                daterange(
                    effective_from,
                    effective_until,
                    '[)'
                ) WITH &&
            )
            WHERE (is_active)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlement_rules');

        // Deliberately retain btree_gist because other tables
        // or database applications may also depend on it.
    }
};
