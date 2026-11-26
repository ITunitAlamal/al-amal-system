<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave phase 4 (owner decisions of 2026-10-22).
 *
 * - Contract type and work schedule are recorded on the employee, apart from the employment term.
 * - A leave rule may target one contract type; an empty contract type applies to all of them.
 * - Pro-rata entitlement for service under a year is a policy proposed by the system admin and
 *   approved by the executive director. It is for display only and never credits a balance.
 *
 * Additive only. Backup taken before running: C:\Projects\al-amal-backups\al_amal_db_20261022_013044_before_phase4_ess_pr.dump
 */
return new class extends Migration
{
    /** @var array<string, string> approved contract types (code => Arabic label) */
    private const CONTRACT_TYPES = [
        'permanent' => 'دائم',
        'fixed_term' => 'محدد المدة',
        'probation' => 'تجريبي',
        'daily_wage' => 'مياومة',
        'part_time' => 'جزئي',
    ];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('contract_type_value_id')->nullable()->after('employment_term_value_id')
                ->constrained('master_data_values')->restrictOnDelete();
            $table->foreignId('work_schedule_value_id')->nullable()->after('contract_type_value_id')
                ->constrained('master_data_values')->restrictOnDelete();
        });

        Schema::table('leave_entitlement_rules', function (Blueprint $table): void {
            $table->foreignId('contract_type_value_id')->nullable()->after('employment_term_value_id')
                ->constrained('master_data_values')->restrictOnDelete();
        });

        // Same overlap guard as before, now per contract type; an empty contract type is its own group.
        DB::statement('ALTER TABLE leave_entitlement_rules DROP CONSTRAINT leave_rules_no_active_overlap');
        DB::statement("
            ALTER TABLE leave_entitlement_rules
            ADD CONSTRAINT leave_rules_no_active_overlap
            EXCLUDE USING gist (
                employment_term_value_id WITH =,
                (coalesce(contract_type_value_id, 0)) WITH =,
                leave_type_value_id WITH =,
                int4range(min_service_months::integer, max_service_months::integer, '[)') WITH &&,
                daterange(effective_from, effective_until, '[)') WITH &&
            )
            WHERE (is_active)
        ");

        Schema::table('leave_module_settings', function (Blueprint $table): void {
            $table->string('prorata_status', 20)->default('not_defined');
            $table->decimal('prorata_monthly_rate_days', 6, 3)->nullable();
            $table->unsignedSmallInteger('prorata_min_service_months')->nullable();
            $table->string('prorata_rounding', 20)->nullable();
            $table->text('prorata_notes')->nullable();
            $table->foreignId('prorata_proposed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('prorata_proposed_at')->nullable();
            $table->foreignId('prorata_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('prorata_approved_at')->nullable();
        });

        $typeId = DB::table('master_data_types')->where('code', 'contract_types')->value('id');
        if ($typeId) {
            $order = 1;
            foreach (self::CONTRACT_TYPES as $code => $label) {
                $exists = DB::table('master_data_values')->where('type_id', $typeId)->where('code', $code)->exists();
                if (! $exists) {
                    $id = DB::table('master_data_values')->insertGetId([
                        'type_id' => $typeId, 'code' => $code, 'name_ar' => $label, 'sort_order' => $order,
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('audit_logs')->insert([
                        'actor_id' => null, 'action' => 'master_data.value.created', 'subject_type' => 'master_data_values',
                        'subject_id' => $id, 'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
                        'metadata' => json_encode(['before' => null, 'after' => ['type_id' => $typeId, 'code' => $code, 'name_ar' => $label],
                            'source' => 'قرار صاحب المشروع 2026-10-22 (أنواع العقود)'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
                $order++;
            }
        }
    }

    /**
     * Rollback for a failed rollout only. Contract-type values stay, because records may reference them.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE leave_entitlement_rules DROP CONSTRAINT leave_rules_no_active_overlap');
        DB::statement("
            ALTER TABLE leave_entitlement_rules
            ADD CONSTRAINT leave_rules_no_active_overlap
            EXCLUDE USING gist (
                employment_term_value_id WITH =,
                leave_type_value_id WITH =,
                int4range(min_service_months::integer, max_service_months::integer, '[)') WITH &&,
                daterange(effective_from, effective_until, '[)') WITH &&
            )
            WHERE (is_active)
        ");

        Schema::table('leave_module_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('prorata_proposed_by');
            $table->dropConstrainedForeignId('prorata_approved_by');
            $table->dropColumn(['prorata_status', 'prorata_monthly_rate_days', 'prorata_min_service_months', 'prorata_rounding', 'prorata_notes', 'prorata_proposed_at', 'prorata_approved_at']);
        });
        Schema::table('leave_entitlement_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('contract_type_value_id');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_schedule_value_id');
            $table->dropConstrainedForeignId('contract_type_value_id');
        });
    }
};
