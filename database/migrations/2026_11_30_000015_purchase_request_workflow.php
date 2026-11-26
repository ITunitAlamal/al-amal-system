<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «طلب شراء» from «طلباتي» (owner request 2026-11-02) and the missing reject/return decisions of the Accounts
 * purchase cycle.
 *
 * PURCHASE (aims_request): the employee → the employee's department head (need) → procurement. Procurement's
 * acceptance opens a draft purchase request in Accounts linked to the request (step effect finance_purchase_draft,
 * one per request), which then follows the existing cycle: head of Accounts review → executive director approval →
 * board treasurer above the policy threshold → quotes and order → receipt by the stores → invoice and the external
 * voucher number. The request itself does not repeat those approvals; its page shows the purchase's progress.
 *
 * finance_purchase_requests: rejected_by and decision_note record a rejection or a return to procurement.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000015 (طلب شراء من «طلباتي» ورفض/إعادة طلبات الشراء)';

    public function up(): void
    {
        Schema::table('finance_purchase_requests', function (Blueprint $table): void {
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_note')->nullable();
        });
        // One purchase per request: a repeated hand-off never opens a second one.
        DB::statement("CREATE UNIQUE INDEX finance_purchase_requests_aims_request_uniq ON finance_purchase_requests (source_id) WHERE source_module = 'aims_request'");

        $finance = (int) DB::table('departments')->where('code', 'FIN')->value('id');
        if ($finance <= 0) {
            throw new RuntimeException('قسم الحسابات مطلوب لمسار طلب الشراء.');
        }
        $any = ['department_scope' => 'any'];
        $definition = (int) DB::table('workflow_definitions')->insertGetId([
            'code' => 'PURCHASE', 'subject_type' => 'aims_request', 'name' => 'طلب شراء',
            'description' => 'طلب احتياج يمر برئيس القسم ثم المشتريات، فتفتح المشتريات طلب شراء في الحسابات يكمل مراجعة رئيس الحسابات واعتماد المدير التنفيذي وأمين الصندوق عند تجاوز الحد.',
            'is_active' => true, 'is_user_startable' => true, 'reference_prefix' => 'PUR', 'is_confidential' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->field('items', 'الأصناف المطلوبة والكميات', 'textarea', true, ['max_length' => 3000], 10);
        $this->field('purpose', 'سبب الحاجة', 'textarea', true, ['max_length' => 2000], 20);
        $this->field('estimated_amount', 'التكلفة التقديرية بالشيكل (إن عُرفت)', 'number', false, ['min' => 0], 30);
        $this->field('needed_by', 'تاريخ الحاجة', 'date', false, [], 40);

        $this->publish($definition, [
            ['requester', 'تقديم طلب الشراء', 10, 'start', 'requester', null, null],
            ['department_head', 'مراجعة رئيس القسم للحاجة', 20, 'task', 'department_head', null, null],
            ['procurement', 'استلام المشتريات للطلب', 30, 'task', 'role', 'finance_procurement', [...$any, 'fallback_department_id' => $finance]],
            ['handed', 'أُحيل إلى دورة الشراء في الحسابات', 900, 'end', null, null, ['outcome' => 'completed', 'effect' => 'finance_purchase_draft']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'department_head', 'submit', 'إرسال إلى رئيس القسم', false, ['requester_has_department_head' => true]],
            ['requester', 'procurement', 'submit_procurement', 'إرسال إلى المشتريات مباشرة (لا يوجد رئيس أعلى)', false, ['requester_has_department_head' => false]],
            ['department_head', 'procurement', 'recommend', 'الموافقة على الحاجة وإحالتها إلى المشتريات', false, null],
            ['department_head', 'requester', 'return', 'إعادة للموظف للتعديل', true, null],
            ['department_head', 'rejected', 'reject', 'رفض الطلب', true, null],
            ['procurement', 'handed', 'accept', 'استلام الطلب وفتح طلب شراء في الحسابات', false, null],
            ['procurement', 'requester', 'return', 'إعادة للموظف للاستيضاح', true, null],
            ['procurement', 'rejected', 'reject', 'رفض الطلب (اذكر السبب)', true, null],
        ]);
    }

    /** Rolled back only before any purchase request was made or rejected/returned. */
    public function down(): void
    {
        $definitions = DB::table('workflow_definitions')->where('code', 'PURCHASE')->pluck('id');
        $versions = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitions)->pluck('id');
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()
            || DB::table('finance_purchase_requests')->whereNotNull('rejected_by')->orWhereNotNull('decision_note')->exists()) {
            throw new RuntimeException('توجد طلبات شراء أو قرارات رفض/إعادة؛ لا يمكن التراجع.');
        }
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_versions')->whereIn('id', $versions)->delete();
        DB::table('custom_field_definitions')->where('entity_type', 'aims_request')->where('scope_key', 'PURCHASE')->delete();
        DB::table('workflow_definitions')->whereIn('id', $definitions)->delete();
        DB::statement('DROP INDEX IF EXISTS finance_purchase_requests_aims_request_uniq');
        Schema::table('finance_purchase_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn('decision_note');
        });
    }

    /** @param array<string, int> $validation */
    private function field(string $code, string $label, string $type, bool $required, array $validation, int $order): void
    {
        DB::table('custom_field_definitions')->insert([
            'entity_type' => 'aims_request', 'scope_key' => 'PURCHASE', 'step_code' => null, 'code' => $code, 'label_ar' => $label,
            'field_type' => $type, 'is_required' => $required, 'is_sensitive' => false, 'validation' => json_encode($validation),
            'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: ?array<string, mixed>}>  $transitions
     */
    private function publish(int $definitionId, array $steps, array $transitions): void
    {
        $now = now();
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $stepIds = [];
        foreach ($steps as [$stepCode, $name, $order, $type, $assignment, $role, $config]) {
            $stepIds[$stepCode] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $stepCode, 'name' => $name, 'sort_order' => $order, 'step_type' => $type,
                'assignment_type' => $assignment, 'assigned_role_code' => $role,
                'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($transitions as [$from, $to, $action, $name, $requiresComment, $conditions]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $stepIds[$from], 'to_step_id' => $stepIds[$to], 'action_code' => $action,
                'name' => $name, 'conditions' => $conditions === null ? null : json_encode($conditions, JSON_UNESCAPED_UNICODE),
                'requires_comment' => $requiresComment, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $problems = app(WorkflowPublishingService::class)->problems($versionId);
        if ($problems !== []) {
            throw new RuntimeException('تعذر نشر مسار PURCHASE: '.implode(' | ', $problems));
        }
        DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'workflow.version.published', 'subject_type' => 'workflow_versions', 'subject_id' => (string) $versionId,
            'metadata' => json_encode(['before' => null, 'after' => ['workflow' => 'PURCHASE', 'version' => 1], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
