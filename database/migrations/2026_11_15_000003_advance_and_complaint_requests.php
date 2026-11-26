<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal requests every employee can make from «طلب جديد» (owner request 2026-10-23):
 *
 * - ADVANCE «طلب سلفة»: the usual chain — the employee's head (or the executive director directly when the
 *   employee has no head) → executive director → Accounts pay it and record the voucher number from the external
 *   accounting software.
 * - COMPLAINT «تقديم شكوى»: straight to the executive director, who answers it, assigns someone to handle it, or
 *   files it with a reason; the employee reads the answer and closes it or objects. Confidential: only the people
 *   on the complaint see it (SRS §3.3.10.2 and TA07: whoever is not concerned does not see the details).
 *
 * workflow_definitions.is_confidential keeps such requests away from department heads, the system administrator's
 * technical access, institution-wide lists and indicators.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_15_000003 (طلب سلفة وتقديم شكوى لجميع الموظفين)';

    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->boolean('is_confidential')->default(false);
        });

        $finance = (int) DB::table('departments')->where('code', 'FIN')->value('id');
        if ($finance <= 0) {
            throw new RuntimeException('قسم الحسابات مطلوب لمسار السلفة.');
        }
        $any = ['department_scope' => 'any'];

        $advance = $this->definition('ADVANCE', 'طلب سلفة', 'سلفة على الراتب: رئيس القسم ثم المدير التنفيذي، ثم تصرفها الحسابات وتسجل رقم سند الصرف.', 'ADV', false);
        $this->field($advance, 'ADVANCE', 'amount', 'مبلغ السلفة (₪)', 'number', ['min' => 1], 10);
        $this->field($advance, 'ADVANCE', 'installments', 'عدد أقساط السداد الشهرية', 'number', ['min' => 1, 'max' => 24], 20);
        $this->publish($advance, 'ADVANCE', [
            ['requester', 'تقديم طلب السلفة', 10, 'start', 'requester', null, null],
            ['department_head', 'مراجعة رئيس القسم والتوصية', 20, 'task', 'department_head', null, null],
            ['executive_director', 'اعتماد المدير التنفيذي', 30, 'decision', 'role', 'executive_director', $any],
            ['accounts_disbursement', 'صرف السلفة من الحسابات وتسجيل رقم السند', 40, 'task', 'role', 'finance_accountant', [...$any, 'fallback_department_id' => $finance]],
            ['completed', 'صرف السلفة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'department_head', 'submit', 'إرسال إلى رئيس القسم', false, ['requester_has_department_head' => true]],
            ['requester', 'executive_director', 'submit_executive', 'إرسال إلى المدير التنفيذي مباشرة (لا يوجد رئيس أعلى)', false, ['requester_has_department_head' => false]],
            ['department_head', 'executive_director', 'recommend', 'موافقة مبدئية ورفع للمدير التنفيذي', false, null],
            ['department_head', 'requester', 'return', 'إعادة للموظف للتعديل', true, null],
            ['department_head', 'rejected', 'reject', 'رفض الطلب', true, null],
            ['executive_director', 'accounts_disbursement', 'approve', 'اعتماد السلفة وإحالتها للحسابات', false, null],
            ['executive_director', 'requester', 'return', 'إعادة للموظف للاستكمال', true, null],
            ['executive_director', 'rejected', 'reject', 'رفض السلفة', true, null],
            ['accounts_disbursement', 'completed', 'disbursed', 'تم الصرف (اذكر رقم سند الصرف وتاريخه من البرنامج المحاسبي)', true, null],
            ['accounts_disbursement', 'executive_director', 'return', 'إعادة إلى المدير التنفيذي', true, null],
        ]);

        $complaint = $this->definition('COMPLAINT', 'تقديم شكوى', 'شكوى سرية تصل إلى المدير التنفيذي مباشرة، ولا يطلع عليها إلا مقدمها ومن يكلفه المدير بمعالجتها.', 'CMP', true);
        $this->publish($complaint, 'COMPLAINT', [
            ['requester', 'كتابة الشكوى وإرسالها', 10, 'start', 'requester', null, null],
            ['ed_review', 'نظر المدير التنفيذي في الشكوى', 20, 'decision', 'role', 'executive_director', $any],
            ['handling', 'معالجة الشكوى (يكلفه المدير التنفيذي)', 30, 'task', 'pick', 'department_head', $any],
            ['requester_ack', 'اطلاع مقدم الشكوى على الرد', 40, 'task', 'requester', null, null],
            ['completed', 'إغلاق الشكوى', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'حفظ الشكوى دون إجراء', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'ed_review', 'submit', 'إرسال الشكوى إلى المدير التنفيذي', false, null],
            ['ed_review', 'requester_ack', 'respond', 'الرد على الشكوى', true, null],
            ['ed_review', 'handling', 'assign', 'تكليف مسؤول بمعالجة الشكوى', false, null],
            ['ed_review', 'requester', 'return', 'طلب استكمال من مقدم الشكوى', true, null],
            ['ed_review', 'rejected', 'reject', 'حفظ الشكوى دون إجراء (اذكر السبب)', true, null],
            ['handling', 'ed_review', 'report', 'رفع نتيجة المعالجة إلى المدير التنفيذي', true, null],
            ['requester_ack', 'completed', 'accept', 'قبول الرد وإغلاق الشكوى', false, null],
            ['requester_ack', 'ed_review', 'object', 'اعتراض على الرد (اذكر السبب)', true, null],
        ]);
    }

    /**
     * Rolled back only before any such request is made.
     */
    public function down(): void
    {
        $definitions = DB::table('workflow_definitions')->whereIn('code', ['ADVANCE', 'COMPLAINT'])->pluck('id');
        $versions = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitions)->pluck('id');
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()) {
            throw new RuntimeException('توجد طلبات سلفة أو شكاوى؛ لا يمكن التراجع.');
        }
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versions)->delete();
        DB::table('workflow_versions')->whereIn('id', $versions)->delete();
        DB::table('custom_field_definitions')->where('entity_type', 'aims_request')->whereIn('scope_key', ['ADVANCE', 'COMPLAINT'])->delete();
        DB::table('workflow_definitions')->whereIn('id', $definitions)->delete();
        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->dropColumn('is_confidential');
        });
    }

    private function definition(string $code, string $name, string $description, string $prefix, bool $confidential): int
    {
        return (int) DB::table('workflow_definitions')->insertGetId([
            'code' => $code, 'subject_type' => 'aims_request', 'name' => $name, 'description' => $description,
            'is_active' => true, 'is_user_startable' => true, 'reference_prefix' => $prefix, 'is_confidential' => $confidential,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int>  $validation
     */
    private function field(int $definitionId, string $scope, string $code, string $label, string $type, array $validation, int $order): void
    {
        DB::table('custom_field_definitions')->insert([
            'entity_type' => 'aims_request', 'scope_key' => $scope, 'step_code' => null, 'code' => $code, 'label_ar' => $label,
            'field_type' => $type, 'is_required' => true, 'is_sensitive' => false, 'validation' => json_encode($validation),
            'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: ?array<string, mixed>}>  $transitions
     */
    private function publish(int $definitionId, string $code, array $steps, array $transitions): void
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
            throw new RuntimeException("تعذر نشر مسار {$code}: ".implode(' | ', $problems));
        }
        DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'workflow.version.published', 'subject_type' => 'workflow_versions', 'subject_id' => (string) $versionId,
            'metadata' => json_encode(['before' => null, 'after' => ['workflow' => $code, 'version' => 1], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
