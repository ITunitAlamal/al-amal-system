<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The works contract of an external job, recorded on the request itself (SRS §3.5.4.4 «العقد يتضمن المنفذ ...
 * والتكلفة»; TRANSFORM §17 General Services ↔ Accounting). Two fields, filled by HR with the action that sends the
 * cost on, so the executive director decides on a stated figure and Accounts pay the wage against it:
 * - MAINTENANCE «إعداد التكلفة ومستندات التنفيذ الخارجي» (hr_cost), action submit_cost, before the director's
 *   approval of the cost;
 * - SERVICE «إعداد التكلفة التقديرية وعقد تنفيذ الأعمال» (hr_cost_contract), action contract_sent, where the
 *   director is informed of the cost (no second approval, as before).
 * «المنفذ» (contractor_name) and «قيمة عقد التنفيذ (₪)» (contract_amount) belong to that one action: required with
 * it, kept with its timeline event (each pass keeps its own figure), and cleared from the current values when the
 * cost is sent back for change, as for the fields of 2026_11_30_000023. Nothing is written to accounting records.
 *
 * Data only: no table, stage or transition changes. Field definitions are per workflow, not per version. The SERVICE
 * action that asked to type the cost and contractor in its note is renamed once both fields are in place, only while
 * it still carries the installed name (published version and open draft, as 000023 does). An existing definition
 * with the same code is never overwritten. Every change writes an audit row. Safe to run twice.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000031 (المنفذ وقيمة عقد التنفيذ الخارجي في طلبات الصيانة والخدمات)';

    private const ENTITY = 'aims_request';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var array<string, array{step: string, action: string, director: string, rename: ?array{0: string, 1: string}}> */
    private const WORKFLOWS = [
        'MAINTENANCE' => ['step' => 'hr_cost', 'action' => 'submit_cost', 'director' => 'المبلغ الذي يعتمده المدير التنفيذي', 'rename' => null],
        'SERVICE' => ['step' => 'hr_cost_contract', 'action' => 'contract_sent', 'director' => 'المبلغ الذي يُبلَّغ به المدير التنفيذي',
            'rename' => ['إبلاغ المدير بالتكلفة وإرسال العقد للحسابات (اذكر التكلفة والمنفذ)', 'إبلاغ المدير بالتكلفة وإرسال العقد للحسابات (مع المنفذ وقيمة العقد)']],
    ];

    public function up(): void
    {
        foreach (self::WORKFLOWS as $workflow => $spec) {
            if (! DB::table('workflow_definitions')->where('code', $workflow)->where('subject_type', self::ENTITY)->exists()) {
                continue;
            }
            $contractor = $this->field($workflow, $spec['step'], $spec['action'], 'contractor_name', 'المنفذ (المقاول أو الجهة)', 'text', ['max_length' => 150],
                'اسم المقاول أو الجهة المنفذة كما في عقد تنفيذ الأعمال.', 10);
            $amount = $this->field($workflow, $spec['step'], $spec['action'], 'contract_amount', 'قيمة عقد التنفيذ (₪)', 'number', ['min' => 1, 'max' => 99999999],
                $spec['director'].'، وتصرف الحسابات الأجرة على أساسه. لا يُسجَّل من هذا الطلب قيد مالي في النظام.', 20);
            if ($spec['rename'] !== null && $contractor && $amount) {
                $this->rename($workflow, $spec['step'], $spec['action'], ...$spec['rename']);
            }
        }
    }

    /**
     * The fields may already hold values of running requests, so the rollback keeps them; an administrator can switch
     * them off in «الحقول الديناميكية».
     */
    public function down(): void
    {
        // Nothing to undo.
    }

    /**
     * Makes sure the field of one action exists and says whether it is in place for that action. A definition that
     * already uses the code is left exactly as it is; it counts only when it is active on the same stage.
     *
     * @param  array<string, mixed>  $validation
     */
    private function field(string $workflow, string $step, string $action, string $code, string $label, string $type, array $validation, string $help, int $order): bool
    {
        $existing = DB::table('custom_field_definitions')->where('entity_type', self::ENTITY)->where('scope_key', $workflow)->where('code', $code)->first();
        if ($existing) {
            return (bool) $existing->is_active && $existing->step_code === $step;
        }
        $row = [
            'entity_type' => self::ENTITY, 'scope_key' => $workflow, 'step_code' => $step, 'code' => $code, 'label_ar' => $label, 'help_text' => $help,
            'field_type' => $type, 'is_required' => true, 'is_sensitive' => false,
            'validation' => json_encode($validation, self::JSON), 'visible_when' => json_encode(['action_codes' => [$action]], self::JSON),
            'sort_order' => $order, 'is_active' => true,
        ];
        $id = (int) DB::table('custom_field_definitions')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('custom_field.created', 'custom_field_definitions', $id, null,
            [...$row, 'validation' => $validation, 'visible_when' => ['action_codes' => [$action]], 'workflow' => $workflow]);

        return true;
    }

    /** Renames the action on the published version and an open draft, only while it still carries the installed name. */
    private function rename(string $workflow, string $from, string $action, string $installed, string $name): void
    {
        $rows = DB::table('workflow_transitions as t')
            ->join('workflow_steps as s', 's.id', '=', 't.from_step_id')
            ->join('workflow_versions as v', 'v.id', '=', 't.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('d.code', $workflow)->where('d.subject_type', self::ENTITY)->whereIn('v.status', ['published', 'draft'])
            ->where('s.code', $from)->where('t.action_code', $action)->where('t.name', $installed)
            ->orderBy('t.id')->get(['t.id', 'v.version_number', 'v.status']);
        foreach ($rows as $row) {
            DB::table('workflow_transitions')->where('id', $row->id)->update(['name' => $name, 'updated_at' => now()]);
            $this->audit('workflow.transition.updated', 'workflow_transitions', (int) $row->id, ['name' => $installed],
                ['name' => $name, 'from' => $from, 'action' => $action, 'workflow' => $workflow, 'version' => (int) $row->version_number, 'version_status' => $row->status]);
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
