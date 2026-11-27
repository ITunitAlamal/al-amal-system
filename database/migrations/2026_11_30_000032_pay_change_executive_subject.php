<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pay and bank changes («تعديل راتب موظف أو حسابه البنكي», EMPLOYEE_PAY_CHANGE), owner decision of 2026-11-27: a
 * change to the executive director's own pay or bank record is decided by the Head of Accounts (the appointed head
 * of FIN holding the «رئيس قسم» role there), never by the executive director himself; every other employee's change
 * stays with the executive director.
 *
 * The route is data: HR's send action splits on the fact «employee_is_executive», which
 * App\Services\Hr\EmployeePayChangeWorkflow::conditionContext supplies (the employee's account holds an effective
 * executive_director assignment):
 * - «submit» (hr → ed_decision) now requires employee_is_executive = false;
 * - «submit_accounts» (hr → accounts_decision) requires employee_is_executive = true;
 * - the new decision stage «accounts_decision» (department_head_of FIN) approves, returns to HR or rejects, like
 *   «ed_decision». The workflow subject refuses any other decider for the executive director's change.
 *
 * Data only. The change is a new published version cloned from the current one and validated like a manual
 * publish: a problem this patch would introduce stops the migration, one the current version already has does not.
 * The previous version is retired for new requests; running requests keep the version they started with (none is
 * open today). An open draft gets the same change and is renumbered after the new version. Every change writes an
 * audit row. Safe to run twice: a version that already has the stage is left alone.
 */
return new class extends Migration
{
    private const CODE = 'EMPLOYEE_PAY_CHANGE';

    private const SUBJECT = 'employee_pay_change';

    private const STEP = 'accounts_decision';

    private const SOURCE = 'ترحيل 2026_11_30_000032 (قرار رئيس قسم الحسابات في تعديل راتب المدير التنفيذي أو حسابه البنكي)';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function up(): void
    {
        $definition = DB::table('workflow_definitions')->where('code', self::CODE)->where('subject_type', self::SUBJECT)->first();
        if (! $definition) {
            return;
        }
        $finance = DB::table('departments')->where('code', 'FIN')->whereNull('deleted_at')->value('id');
        if (! $finance) {
            throw new RuntimeException('قسم الحسابات (FIN) غير موجود؛ لا يمكن إسناد قرار تعديل راتب المدير التنفيذي إلى رئيسه.');
        }

        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')->orderBy('id')->get() as $draft) {
            $this->patch($draft, (int) $finance);
        }

        $published = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->orderByDesc('version_number')->first();
        if (! $published || ! $this->patchable($published)) {
            return;
        }

        $version = $this->cloneVersion($definition, $published);
        $validator = app(WorkflowPublishingService::class);
        $before = $validator->problems((int) $version->id);
        $this->patch($version, (int) $finance);
        $introduced = array_values(array_diff($validator->problems((int) $version->id), $before));
        if ($introduced !== []) {
            throw new RuntimeException('تعذر نشر الإصدار الجديد من مسار تعديل الرواتب: '.implode(' | ', $introduced));
        }

        $now = now();
        $retired = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('workflow_versions')->whereIn('id', $retired)->update(['status' => 'retired', 'updated_at' => $now]);
        DB::table('workflow_versions')->where('id', $version->id)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        $this->audit('workflow.version.published', 'workflow_versions', (int) $version->id,
            ['status' => 'draft', 'retired_versions' => $retired], ['status' => 'published'], DB::table('workflow_versions')->where('id', $version->id)->first());

        // An open draft now sorts below the version just published; it moves after it (draftFor takes the highest).
        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')
            ->where('version_number', '<', (int) $version->version_number)->orderBy('version_number')->get() as $draft) {
            $number = (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1;
            DB::table('workflow_versions')->where('id', $draft->id)->update(['version_number' => $number, 'updated_at' => $now]);
            $this->audit('workflow.version.updated', 'workflow_versions', (int) $draft->id, ['version_number' => (int) $draft->version_number],
                ['version_number' => $number, 'after_published' => (int) $version->id], DB::table('workflow_versions')->where('id', $draft->id)->first());
        }
    }

    /**
     * The published version and its history stay; requests decided on it keep their record.
     */
    public function down(): void
    {
        // Nothing to undo.
    }

    /** The version has HR's start stage, the executive director's decision and the two end stages, and not yet the new stage. */
    private function patchable(object $version): bool
    {
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $version->id)->pluck('id', 'code');

        return $steps->has('hr') && $steps->has('ed_decision') && $steps->has('approved') && $steps->has('rejected') && ! $steps->has(self::STEP);
    }

    private function patch(object $version, int $financeId): void
    {
        if (! $this->patchable($version)) {
            return;
        }
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $version->id)->get()->keyBy('code');
        $ed = $steps->get('ed_decision');
        $order = (int) $ed->sort_order + 5;
        if ($steps->contains(fn (object $step): bool => (int) $step->sort_order === $order)) {
            $order = (int) $steps->max('sort_order') + 10;
        }
        $config = ['department_id' => $financeId];
        $row = [
            'workflow_version_id' => $version->id, 'code' => self::STEP, 'name' => 'قرار رئيس قسم الحسابات (تعديل يخص المدير التنفيذي)',
            'sort_order' => $order, 'step_type' => 'decision', 'assignment_type' => 'department_head_of', 'assigned_role_code' => null,
            'assignment_config' => json_encode($config, self::JSON), 'sla_hours' => $ed->sla_hours, 'is_required' => true,
        ];
        $stepId = (int) DB::table('workflow_steps')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('workflow.step.created', 'workflow_steps', $stepId, null, [...$row, 'assignment_config' => $config], $version);

        // HR's existing send action now applies to everyone but the executive director.
        $submit = DB::table('workflow_transitions')->where('workflow_version_id', $version->id)->where('from_step_id', $steps['hr']->id)
            ->where('to_step_id', $ed->id)->orderBy('id')->first();
        if ($submit && ($submit->conditions === null || $submit->conditions === '' || $submit->conditions === 'null')) {
            $conditions = ['employee_is_executive' => false];
            DB::table('workflow_transitions')->where('id', $submit->id)->update(['conditions' => json_encode($conditions, self::JSON), 'updated_at' => now()]);
            $this->audit('workflow.transition.updated', 'workflow_transitions', (int) $submit->id, ['conditions' => null], ['conditions' => $conditions, 'from' => 'hr', 'to' => 'ed_decision'], $version);
        }

        foreach ([
            [$steps['hr']->id, $stepId, 'submit_accounts', 'رفع الطلب إلى رئيس قسم الحسابات (تعديل يخص المدير التنفيذي)', false, ['employee_is_executive' => true]],
            [$stepId, $steps['approved']->id, 'approve', 'اعتماد التعديل', false, null],
            [$stepId, $steps['hr']->id, 'return', 'إعادة إلى الموارد البشرية (اذكر السبب)', true, null],
            [$stepId, $steps['rejected']->id, 'reject', 'رفض التعديل (اذكر السبب)', true, null],
        ] as [$from, $to, $action, $name, $comment, $conditions]) {
            if (DB::table('workflow_transitions')->where('workflow_version_id', $version->id)->where('from_step_id', $from)->where('action_code', $action)->exists()) {
                continue;
            }
            $transition = [
                'workflow_version_id' => $version->id, 'from_step_id' => $from, 'to_step_id' => $to, 'action_code' => $action, 'name' => $name,
                'conditions' => $conditions === null ? null : json_encode($conditions, self::JSON), 'requires_comment' => $comment, 'is_active' => true,
            ];
            $id = (int) DB::table('workflow_transitions')->insertGetId([...$transition, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('workflow.transition.created', 'workflow_transitions', $id, null, [...$transition, 'conditions' => $conditions], $version);
        }
    }

    /** A new draft holding an exact copy of the version's stages and actions (as WorkflowPublishingService::draftFor). */
    private function cloneVersion(object $definition, object $source): object
    {
        $now = now();
        $id = (int) DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definition->id,
            'version_number' => (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1,
            'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $steps = [];
        foreach (DB::table('workflow_steps')->where('workflow_version_id', $source->id)->orderBy('sort_order')->get() as $step) {
            $row = array_diff_key((array) $step, array_flip(['id', 'workflow_version_id', 'created_at', 'updated_at']));
            $steps[(int) $step->id] = (int) DB::table('workflow_steps')->insertGetId([...$row, 'workflow_version_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('workflow_transitions')->where('workflow_version_id', $source->id)->orderBy('id')->get() as $transition) {
            $row = array_diff_key((array) $transition, array_flip(['id', 'workflow_version_id', 'from_step_id', 'to_step_id', 'created_at', 'updated_at']));
            DB::table('workflow_transitions')->insert([
                ...$row, 'workflow_version_id' => $id,
                'from_step_id' => $steps[(int) $transition->from_step_id], 'to_step_id' => $steps[(int) $transition->to_step_id],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $version = DB::table('workflow_versions')->where('id', $id)->first();
        $this->audit('workflow.version.drafted', 'workflow_versions', $id, null, ['cloned_from' => (int) $source->id, 'cloned_version' => (int) $source->version_number], $version);

        return $version;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, ?array $before, array $after, object $version): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => $before, 'after' => [...$after, 'workflow' => self::CODE, 'version' => (int) $version->version_number,
                'version_status' => $version->status], 'source' => self::SOURCE], self::JSON),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
