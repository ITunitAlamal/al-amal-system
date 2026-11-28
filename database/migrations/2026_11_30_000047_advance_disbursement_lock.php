<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADVANCE disbursement locked against reopening (Engineering 01, WF-03).
 *
 * The ADVANCE stage accounts_disbursement records the cash disbursement of an advance. Like the stages 000039 locked
 * (MAINTENANCE/SERVICE materials, purchase, ext_payment; PURCHASE stores), it gets "reopen_locked": true in its step
 * configuration, in every version, so WorkflowEngine::reopenTargets never reopens a completed advance at or before
 * the disbursement and the advance is not paid twice.
 *
 * Effect: accounts_disbursement leads straight to the end step (disbursed -> completed), so no stage is reached after
 * the locked exit and a disbursed advance cannot be reopened at all; it is corrected by a new request, as the refusal
 * message says. An advance that ended without passing the disbursement (rejected) can still be reopened. A reopening
 * after payment that does not repeat it would need a new post-disbursement stage, which is an owner decision.
 *
 * Same deliberate deviation as 000039 from "a workflow change is a new published version": a reopening applies only
 * to completed requests, which stay on the version they ran on, so the flag is set in place on the existing versions,
 * with one audit row per step. Routing, holders, conditions, transitions and running requests are unchanged. A step
 * whose configuration already says reopen_locked (true, or false set by an administrator) is left as it is.
 *
 * Safe to run twice.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000047 (قفل إعادة الفتح بعد صرف السلفة)';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var array<string, list<string>> workflow => step codes that record a payment */
    private const REOPEN_LOCKED = [
        'ADVANCE' => ['accounts_disbursement'],
    ];

    public function up(): void
    {
        foreach (self::REOPEN_LOCKED as $workflow => $codes) {
            $steps = DB::table('workflow_steps as s')
                ->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
                ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('d.code', $workflow)->whereIn('s.code', $codes)->whereIn('s.step_type', ['start', 'task', 'decision'])
                ->orderBy('s.id')->get(['s.id', 's.code', 's.assignment_config', 'v.version_number']);
            foreach ($steps as $step) {
                $config = is_string($step->assignment_config) ? (json_decode($step->assignment_config, true) ?: []) : [];
                if (array_key_exists('reopen_locked', $config)) {
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
     * Removes the lock this migration set (only a lock that is still true), with an audit row per step.
     */
    public function down(): void
    {
        foreach (self::REOPEN_LOCKED as $workflow => $codes) {
            $steps = DB::table('workflow_steps as s')
                ->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
                ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('d.code', $workflow)->whereIn('s.code', $codes)->orderBy('s.id')->get(['s.id', 's.code', 's.assignment_config', 'v.version_number']);
            foreach ($steps as $step) {
                $config = is_string($step->assignment_config) ? (json_decode($step->assignment_config, true) ?: []) : [];
                if (($config['reopen_locked'] ?? null) !== true) {
                    continue;
                }
                $after = $config;
                unset($after['reopen_locked']);
                DB::table('workflow_steps')->where('id', $step->id)->update(['assignment_config' => $after === [] ? null : json_encode($after, self::JSON), 'updated_at' => now()]);
                $this->audit('workflow.step.updated', 'workflow_steps', (int) $step->id, ['assignment_config' => $config],
                    ['assignment_config' => $after, 'workflow' => $workflow, 'version' => (int) $step->version_number, 'step' => $step->code]);
            }
        }
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
