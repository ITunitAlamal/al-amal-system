<?php

use App\Services\AuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * Decommissions the old «شؤون الأيتام والكفالات» implementation (owner order 2026-10-31) before a full redesign.
 *
 * Removed: every table, trigger function and sequence owned only by the orphans, sponsorships, relief campaigns
 * and field-distribution module; its menu entries, permissions, workflow definitions, notification types and
 * KPI definitions; the orphan-registry columns added to `guardians`; its stored uploads. The data is disposable
 * by owner decision and is not kept.
 *
 * Kept on purpose: the ORPHANS department and its units, master data («ثوابت النظام»), audit logs, finance and
 * stock records, and the beneficiary portal accounts (`guardians` in its original portal form).
 *
 * No shared table has a foreign key into the dropped tables. The drop runs without CASCADE so that any
 * unexpected dependency stops the migration instead of removing a shared object.
 */
return new class extends Migration
{
    private const TABLES = [
        'field_access_tokens', 'field_devices',
        'guardian_application_children', 'guardian_applications',
        'orphan_access_grants', 'orphan_access_log', 'orphan_access_requests', 'orphan_addresses',
        'orphan_application_children', 'orphan_applications', 'orphan_assistance_requests', 'orphan_change_requests',
        'orphan_children', 'orphan_contributions', 'orphan_document_requirements', 'orphan_documents',
        'orphan_education_records', 'orphan_emergency_flags', 'orphan_followups', 'orphan_form_versions',
        'orphan_guardianships', 'orphan_health_records', 'orphan_household_parents', 'orphan_households',
        'orphan_import_aliases', 'orphan_import_batches', 'orphan_import_issues', 'orphan_import_rows',
        'orphan_need_assessments', 'orphan_parents', 'orphan_payment_instructions', 'orphan_referrals',
        'orphan_registration_events', 'orphan_retention_policies', 'orphan_savings_entries', 'orphan_social_scores',
        'orphan_social_visits', 'orphan_visit_plans', 'orphans_module_settings', 'registry_requests',
        'relief_allocations', 'relief_batch_manifests', 'relief_batches', 'relief_campaign_items',
        'relief_campaign_members', 'relief_campaigns', 'relief_delivery_events', 'relief_exceptions',
        'relief_reconciliations', 'relief_roster_entries', 'relief_rosters',
        'scoring_rulesets',
        'sponsor_contacts', 'sponsors', 'sponsorship_agreement_templates', 'sponsorship_agreements',
        'sponsorship_collections', 'sponsorship_disbursements', 'sponsorship_funds', 'sponsorship_schedules',
        'sponsorship_waitlist', 'sponsorships',
    ];

    private const FUNCTIONS = [
        'orphan_child_requires_guardian', 'orphan_import_rows_evidence_guard', 'orphan_savings_append_only',
        'relief_batches_head_guard', 'relief_campaigns_owner_guard', 'relief_entries_freeze', 'relief_events_append_only',
        'relief_exceptions_executive_guard', 'relief_rosters_freeze', 'scoring_rulesets_freeze', 'sponsorship_templates_freeze',
    ];

    private const SEQUENCES = [
        'orphan_guardian_ref_seq', 'orphan_household_ref_seq', 'orphan_child_file_seq', 'orphan_application_ref_seq',
        'orphan_change_ref_seq', 'guardian_ref_seq', 'orphan_visit_ref_seq', 'relief_campaign_ref_seq',
        'relief_exception_ref_seq', 'relief_batch_ref_seq', 'sponsor_ref_seq', 'sponsorship_ref_seq',
        'contribution_ref_seq', 'assistance_ref_seq', 'orphan_visit_plan_ref_seq', 'sponsorship_fund_ref_seq',
        'sponsorship_agreement_ref_seq',
    ];

    /** Orphan-registry profile columns added to the portal accounts table by the removed migrations. */
    private const GUARDIAN_COLUMNS = [
        'reference_no', 'identity_type_value_id', 'date_of_birth', 'sex', 'alt_phone', 'email',
        'occupation', 'employer', 'created_by', 'origin', 'name_parts', 'name_index',
    ];

    private const NAVIGATION = [
        'orphans_registry', 'orphans_records', 'orphans_staging', 'orphans_imports', 'orphans_guardians',
        'orphans_sponsorships', 'relief_campaigns', 'orphans_reports', 'orphans_delegations', 'relief_devices',
        'orphans_settings', 'finance_sponsorships',
    ];

    private const PERMISSIONS = [
        'orphans.guardians.view', 'orphans.guardians.verify', 'orphans.guardians.review',
        'orphans.guardians.approve', 'orphans.guardians.reject', 'finance.sponsorships.record',
    ];

    private const WORKFLOWS = [
        'ORPHAN_FILE_ACTIVATION', 'ORPHAN_CHANGE', 'ORPHAN_SOCIAL_VISIT', 'SCORING_RULESET', 'RELIEF_CAMPAIGN',
        'RELIEF_ROSTER', 'RELIEF_EXCEPTION', 'RELIEF_CLOSURE', 'SPONSORSHIP', 'ORPHAN_ASSISTANCE',
    ];

    private const NOTIFICATION_EVENTS = [
        'orphans_application_update', 'orphans_change_update', 'orphans_visit_update', 'relief_campaign_update',
        'relief_exception_decided', 'relief_member_changed', 'sponsorship_update', 'orphans_emergency_flagged',
        'orphans_referral_assigned', 'orphans_document_expiring', 'orphans_followup_due', 'orphans_followup_escalated',
        'orphans_visit_assigned', 'sponsorship_agreement_renewal', 'sponsor_contact_followup',
    ];

    private const KPIS = ['ORPHAN_FILE_COMPLETENESS', 'ORPHAN_DUE_VISITS', 'ORPHAN_ACTIVE_SPONSORSHIPS', 'ORPHAN_AID_COMPLETED'];

    /** Migration files of the removed module; their files are deleted, so their rows are cleared too. */
    private const MIGRATION_PATTERNS = [
        '2026_11_02_000001_create_orphan_access_delegation_tables', '2026_11_05_000001_create_guardian_application_children_table',
        '2026_11_20_%', '2026_11_23_000001_%', '2026_11_24_000001_%', '2026_11_25_000001_%', '2026_11_27_%', '2026_11_28_%',
    ];

    public function up(): void
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        DB::transaction(function () use ($counts): void {
            // Workflow definitions: refuse if any instance exists (none at decommissioning time).
            $definitionIds = DB::table('workflow_definitions')->whereIn('code', self::WORKFLOWS)->pluck('id');
            $versionIds = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitionIds)->pluck('id');
            abort_if(DB::table('workflow_instances')->whereIn('workflow_version_id', $versionIds)->exists(), 409,
                'Orphan workflow instances exist; decommissioning stopped.');
            DB::table('workflow_transitions')->whereIn('workflow_version_id', $versionIds)->delete();
            DB::table('workflow_steps')->whereIn('workflow_version_id', $versionIds)->delete();
            DB::table('workflow_versions')->whereIn('id', $versionIds)->delete();
            DB::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();

            if ($counts !== []) {
                DB::statement('DROP TABLE IF EXISTS '.implode(', ', array_map(fn ($t) => '"'.$t.'"', array_keys($counts))));
            }
            foreach (self::FUNCTIONS as $function) {
                DB::statement("DROP FUNCTION IF EXISTS \"{$function}\"()");
            }

            if (Schema::hasTable('guardians')) {
                DB::statement('ALTER TABLE guardians DROP CONSTRAINT IF EXISTS guardians_origin');
                DB::statement('DROP INDEX IF EXISTS guardians_name_index');
                $columns = array_values(array_filter(self::GUARDIAN_COLUMNS, fn ($c) => Schema::hasColumn('guardians', $c)));
                if ($columns !== []) {
                    DB::statement('ALTER TABLE guardians '.implode(', ', array_map(fn ($c) => "DROP COLUMN \"{$c}\"", $columns)));
                }
                if (! DB::table('guardians')->whereNull('phone')->orWhereNull('password')->exists()) {
                    DB::statement('ALTER TABLE guardians ALTER COLUMN phone SET NOT NULL, ALTER COLUMN password SET NOT NULL');
                }
            }
            foreach (self::SEQUENCES as $sequence) {
                DB::statement("DROP SEQUENCE IF EXISTS \"{$sequence}\"");
            }

            $children = DB::table('navigation_items')->whereIn('code', self::NAVIGATION)->delete();
            $group = DB::table('navigation_items')->where('code', 'orphans_group')->delete();

            $permissionIds = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id');
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('finance_user_permissions')->whereIn('permission_code', self::PERMISSIONS)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();

            $events = DB::table('notification_settings')->whereIn('event_code', self::NOTIFICATION_EVENTS)->delete();

            $kpiIds = DB::table('kpi_definitions')->whereIn('code', self::KPIS)->pluck('id');
            $kpiVersions = DB::table('kpi_versions')->whereIn('definition_id', $kpiIds)->pluck('id');
            DB::table('kpi_results')->whereIn('version_id', $kpiVersions)->delete();
            DB::table('kpi_versions')->whereIn('id', $kpiVersions)->delete();
            DB::table('kpi_definitions')->whereIn('id', $kpiIds)->delete();

            $migrations = DB::table('migrations')->where(function ($query): void {
                foreach (self::MIGRATION_PATTERNS as $pattern) {
                    $query->orWhere('migration', 'like', $pattern);
                }
            })->delete();

            app(AuditService::class)->recordRow('orphans.department.decommissioned', 'departments', 'ORPHANS',
                ['dropped_tables' => $counts], [
                    'navigation_items' => $children + $group, 'permissions' => $permissionIds->count(),
                    'workflow_definitions' => $definitionIds->count(), 'notification_events' => $events,
                    'kpi_definitions' => $kpiIds->count(), 'migration_rows' => $migrations,
                    'decision' => 'owner 2026-10-31: full removal before redesign, data disposable, no backup',
                ], null, null);
        });

        foreach (['orphan_imports', 'orphans', 'relief'] as $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The old orphans module was removed by owner decision; its data is not recoverable.');
    }
};
