<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role assignment history and reopening after recorded effects (TRANSFORM §3.5, §6, §7, §20).
 *
 * - department_user_role_history: append-only copy of every role assignment as it was before it was changed or
 *   removed (user, role, department, appointment period, when it was assigned), with when, by whom and why it
 *   ended. An AFTER UPDATE/DELETE trigger on department_user_roles writes it, so every path is covered (the
 *   accounts screen, finance assignments, a cascade); the screens pass the person and reason through the
 *   transaction setting `aims.assignment_change` (App\Services\Organization\AssignmentHistory). The table has no
 *   foreign keys, so the history outlives the account, role or department it names. UPDATE, DELETE and TRUNCATE
 *   on it raise an error. The live table and its rows are not changed.
 * - Reopening a completed request (WorkflowEngine::reopenTargets): the stages that record a stock issue, a
 *   purchase receipt or a payment voucher get "reopen_locked": true in their step configuration, in every version
 *   (MAINTENANCE and SERVICE: materials, purchase, ext_payment where they exist; PURCHASE: stores). Routing, holders
 *   and running requests are unchanged; only the stages a reopening may return to are narrowed.
 *   Deliberate deviation from "a workflow change is a new published version": a reopening applies only to requests
 *   that are already completed, and those stay on the version they ran on, so a flag carried only by a new version
 *   would never reach them. The flag is therefore set in place on the existing (published and retired) versions,
 *   with one audit row per step; it changes no routing, holder, condition or transition.
 *
 * Every change writes an audit row. Safe to run twice.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000039 (تاريخ التكليفات وإعادة الفتح بعد الصرف)';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var array<string, list<string>> workflow => step codes that record an issue, a receipt or a payment */
    private const REOPEN_LOCKED = [
        'MAINTENANCE' => ['materials', 'purchase', 'ext_payment'],
        'SERVICE' => ['materials', 'purchase', 'ext_payment'],
        'PURCHASE' => ['stores'],
    ];

    public function up(): void
    {
        $created = ! Schema::hasTable('department_user_role_history');
        if ($created) {
            Schema::create('department_user_role_history', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('assignment_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('role_id');
                $table->string('role_code', 100)->nullable();
                $table->unsignedBigInteger('department_id')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->string('change_kind', 10);
                $table->jsonb('new_values')->nullable();
                $table->timestampTz('changed_at')->useCurrent();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->text('reason')->nullable();
                $table->index(['user_id', 'changed_at']);
                $table->index(['role_id', 'department_id']);
                $table->index('assignment_id');
            });
            DB::statement("ALTER TABLE department_user_role_history ADD CONSTRAINT department_user_role_history_kind CHECK (change_kind IN ('removed', 'changed'))");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION aims_assignment_history() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                ctx jsonb;
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.user_id = OLD.user_id AND NEW.role_id = OLD.role_id
                   AND NEW.department_id IS NOT DISTINCT FROM OLD.department_id
                   AND NEW.starts_on IS NOT DISTINCT FROM OLD.starts_on AND NEW.ends_on IS NOT DISTINCT FROM OLD.ends_on THEN
                    RETURN NEW;
                END IF;
                BEGIN
                    ctx := NULLIF(current_setting('aims.assignment_change', true), '')::jsonb;
                EXCEPTION WHEN others THEN
                    ctx := NULL;
                END;
                INSERT INTO department_user_role_history (assignment_id, user_id, role_id, role_code, department_id, starts_on, ends_on,
                    assigned_at, change_kind, new_values, changed_at, changed_by, reason)
                VALUES (OLD.id, OLD.user_id, OLD.role_id, (SELECT code FROM roles WHERE id = OLD.role_id), OLD.department_id,
                    OLD.starts_on, OLD.ends_on, OLD.created_at,
                    CASE WHEN TG_OP = 'DELETE' THEN 'removed' ELSE 'changed' END,
                    CASE WHEN TG_OP = 'UPDATE' THEN jsonb_build_object('user_id', NEW.user_id, 'role_id', NEW.role_id,
                        'department_id', NEW.department_id, 'starts_on', NEW.starts_on, 'ends_on', NEW.ends_on) END,
                    now(),
                    CASE WHEN ctx->>'by' ~ '^[0-9]{1,18}$' THEN (ctx->>'by')::bigint END,
                    LEFT(ctx->>'reason', 1000));
                RETURN NULL;
            END
            $$;
            DROP TRIGGER IF EXISTS department_user_roles_history ON department_user_roles;
            CREATE TRIGGER department_user_roles_history
                AFTER UPDATE OR DELETE ON department_user_roles
                FOR EACH ROW EXECUTE FUNCTION aims_assignment_history();

            CREATE OR REPLACE FUNCTION aims_assignment_history_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'ASSIGNMENT_HISTORY_APPEND_ONLY: department_user_role_history rows are never changed or removed.' USING ERRCODE = '42501';
            END
            $$;
            DROP TRIGGER IF EXISTS department_user_role_history_append_only ON department_user_role_history;
            CREATE TRIGGER department_user_role_history_append_only
                BEFORE UPDATE OR DELETE ON department_user_role_history
                FOR EACH ROW EXECUTE FUNCTION aims_assignment_history_append_only();
            DROP TRIGGER IF EXISTS department_user_role_history_no_truncate ON department_user_role_history;
            CREATE TRIGGER department_user_role_history_no_truncate
                BEFORE TRUNCATE ON department_user_role_history
                FOR EACH STATEMENT EXECUTE FUNCTION aims_assignment_history_append_only();
            SQL);

        if ($created) {
            $this->audit('organization.assignment_history.installed', 'department_user_role_history', 0, null,
                ['trigger' => 'department_user_roles_history', 'append_only' => true]);
        }

        foreach (self::REOPEN_LOCKED as $workflow => $codes) {
            $steps = DB::table('workflow_steps as s')
                ->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
                ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('d.code', $workflow)->whereIn('s.code', $codes)->whereIn('s.step_type', ['start', 'task', 'decision'])
                ->orderBy('s.id')->get(['s.id', 's.code', 's.assignment_config', 'v.version_number']);
            foreach ($steps as $step) {
                $config = is_string($step->assignment_config) ? (json_decode($step->assignment_config, true) ?: []) : [];
                if (($config['reopen_locked'] ?? null) === true) {
                    continue;
                }
                $after = [...$config, 'reopen_locked' => true];
                DB::table('workflow_steps')->where('id', $step->id)->update(['assignment_config' => json_encode($after, self::JSON), 'updated_at' => now()]);
                $this->audit('workflow.step.updated', 'workflow_steps', (int) $step->id, ['assignment_config' => $config],
                    ['assignment_config' => $after, 'workflow' => $workflow, 'version' => (int) $step->version_number, 'step' => $step->code]);
            }
        }
    }

    /**
     * Removes the reopen locks and the triggers. The history table is dropped only while it is still empty; once it
     * holds history, rolling back would erase it, so this refuses.
     */
    public function down(): void
    {
        if (Schema::hasTable('department_user_role_history') && DB::table('department_user_role_history')->exists()) {
            throw new RuntimeException('department_user_role_history already holds assignment history; it is kept. Remove the trigger by hand only after the owner decides.');
        }
        foreach (self::REOPEN_LOCKED as $workflow => $codes) {
            $steps = DB::table('workflow_steps as s')
                ->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
                ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('d.code', $workflow)->whereIn('s.code', $codes)->get(['s.id', 's.assignment_config']);
            foreach ($steps as $step) {
                $config = is_string($step->assignment_config) ? (json_decode($step->assignment_config, true) ?: []) : [];
                if (! array_key_exists('reopen_locked', $config)) {
                    continue;
                }
                unset($config['reopen_locked']);
                DB::table('workflow_steps')->where('id', $step->id)->update(['assignment_config' => $config === [] ? null : json_encode($config, self::JSON), 'updated_at' => now()]);
            }
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS department_user_roles_history ON department_user_roles;
            DROP FUNCTION IF EXISTS aims_assignment_history();
            DROP TABLE IF EXISTS department_user_role_history;
            DROP FUNCTION IF EXISTS aims_assignment_history_append_only();
            SQL);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => $before, 'after' => $after, 'source' => self::SOURCE], self::JSON),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
