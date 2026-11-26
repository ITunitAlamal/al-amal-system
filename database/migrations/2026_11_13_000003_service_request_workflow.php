<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * General service requests (SRS §3.5.4, SRV01/SRV02, §6.9.1; HR requirements doc §10), e.g. moving furniture,
 * preparing a hall, cleaning, temporary workers.
 *
 * Employee → head (or the ED directly) → ED approval and referral to HR → HR decides:
 * - internal: HR assigns the person and records the result (workers often have no system account);
 * - external: HR notifies the ED of the need ← ED approves and returns it ← HR prepares the estimated cost (the ED
 *   is informed, not asked to approve again, SRS §3.5.4.4) and the works contract for Accounts ← the work is done and
 *   documented ← Accounts pay per the contract ← receipt minutes.
 * The requesting party verifies, then the executive director approves the closing (as for maintenance; see C1).
 */
return new class extends Migration
{
    private const CODE = 'SERVICE';

    private const SOURCE = 'ترحيل 2026_11_13_000003 (مسار طلبات الخدمات العامة)';

    public function up(): void
    {
        if (DB::table('workflow_definitions')->where('code', self::CODE)->exists()) {
            return;
        }
        $hr = (int) DB::table('departments')->where('code', 'HR')->value('id');
        $finance = (int) DB::table('departments')->where('code', 'FIN')->value('id');
        if ($hr <= 0 || $finance <= 0) {
            throw new RuntimeException('قسما الموارد البشرية والحسابات مطلوبان لتهيئة مسار الخدمات.');
        }

        DB::table('notification_settings')->insertOrIgnore([
            'event_code' => 'workflow_step_informed', 'label_ar' => 'إبلاغ للعلم بمرحلة معاملة',
            'description' => 'لمن تحددهم المرحلة للعلم فقط (مثل إبلاغ المدير بالتكلفة التقديرية لخدمة خارجية)، دون أن يكون اعتمادًا.',
            'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => self::CODE, 'subject_type' => 'aims_request', 'name' => 'طلب خدمة عامة',
            'description' => 'نقل أثاث، تجهيز قاعة، تنظيف، عمال مؤقتون وما شابه: تنفيذ داخلي بتكليف الموارد البشرية، أو خارجي بعقد تنفيذ أعمال وصرف من الحسابات.',
            'is_active' => true, 'is_user_startable' => true, 'reference_prefix' => 'SRV', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // The request form asks for the same location and priority as a maintenance request.
        foreach (DB::table('custom_field_definitions')->where('entity_type', 'aims_request')->where('scope_key', 'MAINTENANCE')
            ->whereNull('step_code')->whereIn('code', ['location', 'priority'])->get() as $field) {
            $row = (array) $field;
            unset($row['id']);
            DB::table('custom_field_definitions')->insert([...$row, 'scope_key' => self::CODE, 'created_at' => $now, 'updated_at' => $now]);
        }

        $any = ['department_scope' => 'any'];
        $hrStage = ['department_id' => $hr, 'requester_may_act' => true, 'fallback_role_code' => 'executive_director'];
        $versionId = $this->createVersion($definitionId, [
            ['requester', 'إنشاء وإرسال الطلب', 10, 'start', 'requester', null, null],
            ['department_head', 'مراجعة رئيس قسم مقدم الطلب', 20, 'task', 'department_head', null, null],
            ['executive_director', 'اعتماد المدير التنفيذي والإحالة إلى الموارد البشرية', 30, 'decision', 'role', 'executive_director', $any],
            ['hr_classify', 'تحديد الموارد البشرية: تنفيذ داخلي أو عمال خارجيون', 40, 'decision', 'department_head_of', null, $hrStage],
            ['internal_execution', 'تكليف المنفذ الداخلي وتوثيق الإنجاز', 50, 'task', 'department_head_of', null, $hrStage],
            ['ed_external', 'موافقة المدير التنفيذي على الاستعانة بعمال خارجيين', 60, 'decision', 'role', 'executive_director', $any],
            ['hr_cost_contract', 'إعداد التكلفة التقديرية وعقد تنفيذ الأعمال', 70, 'task', 'department_head_of', null, $hrStage],
            ['ext_work', 'تنفيذ الأعمال الخارجية وتوثيق الإنجاز قبل الصرف', 80, 'task', 'department_head_of', null, [...$hrStage, 'inform_role_codes' => ['executive_director']]],
            ['ext_payment', 'صرف الأجرة وفق العقد ومستندات الإنجاز', 90, 'task', 'role', 'finance_cash_executor', [...$any, 'requester_may_act' => true, 'fallback_department_id' => $finance]],
            ['ext_receipt', 'توثيق محضر الاستلام', 100, 'task', 'department_head_of', null, $hrStage],
            ['verification', 'تحقق الجهة الطالبة من انتهاء الخدمة', 110, 'task', 'requester', null, null],
            ['ed_closure', 'اعتماد الإغلاق من المدير التنفيذي', 120, 'decision', 'role', 'executive_director', $any],
            ['completed', 'إغلاق طلب الخدمة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'department_head', 'submit', 'إرسال إلى رئيس القسم', false, ['requester_has_department_head' => true]],
            ['requester', 'executive_director', 'submit_executive', 'إرسال إلى المدير التنفيذي مباشرة (لا يوجد رئيس أعلى)', false, ['requester_has_department_head' => false]],
            ['department_head', 'executive_director', 'forward', 'رفع إلى المدير التنفيذي', false, null],
            ['department_head', 'requester', 'return', 'إرجاع للموظف', true, null],
            ['department_head', 'rejected', 'reject', 'رفض الطلب', true, null],
            ['executive_director', 'hr_classify', 'approve', 'اعتماد وإحالة إلى الموارد البشرية', false, null],
            ['executive_director', 'requester', 'return', 'إعادة لمقدم الطلب للاستكمال', true, null],
            ['executive_director', 'rejected', 'reject', 'رفض من المدير التنفيذي', true, null],
            ['hr_classify', 'internal_execution', 'internal', 'داخلي: تكليف موظف أو عامل داخلي', false, null],
            ['hr_classify', 'ed_external', 'external', 'خارجي: إشعار المدير بالحاجة لعمال خارجيين (اذكر مبرر عدم التنفيذ الداخلي)', true, null],
            ['hr_classify', 'executive_director', 'return', 'إعادة إلى المدير التنفيذي', true, null],
            ['internal_execution', 'verification', 'done', 'تم التنفيذ (اذكر المنفذ والموعد والنتيجة)', true, null],
            ['internal_execution', 'hr_classify', 'return', 'إعادة التصنيف (يتطلب تنفيذًا خارجيًا)', true, null],
            ['ed_external', 'hr_cost_contract', 'approve', 'الموافقة وإعادتها للموارد البشرية لإعداد التكلفة', false, null],
            ['ed_external', 'hr_classify', 'return', 'إعادة إلى الموارد البشرية', true, null],
            ['ed_external', 'rejected', 'reject', 'رفض الاستعانة بعمال خارجيين', true, null],
            ['hr_cost_contract', 'ext_work', 'contract_sent', 'إبلاغ المدير بالتكلفة وإرسال العقد للحسابات (اذكر التكلفة والمنفذ)', true, null],
            ['ext_work', 'ext_payment', 'completed_work', 'إثبات الإنجاز ورفعه للصرف', true, null],
            ['ext_payment', 'ext_receipt', 'paid', 'تم صرف الأجرة وفق العقد', false, null],
            ['ext_payment', 'ext_work', 'return', 'إعادة لاستكمال مستندات الإنجاز', true, null],
            ['ext_receipt', 'verification', 'receipt', 'توثيق محضر الاستلام', true, null],
            ['verification', 'ed_closure', 'accept', 'تأكيد انتهاء الخدمة ورفعه لاعتماد الإغلاق', false, null],
            ['verification', 'hr_classify', 'return', 'إعادة للموارد البشرية لاستكمال الخدمة', true, null],
            ['ed_closure', 'completed', 'close', 'اعتماد الإغلاق', false, null],
            ['ed_closure', 'hr_classify', 'return', 'إعادة للمتابعة لدى الموارد البشرية', true, null],
        ]);

        $problems = app(WorkflowPublishingService::class)->problems($versionId);
        if ($problems !== []) {
            throw new RuntimeException('تعذر نشر مسار الخدمات: '.implode(' | ', $problems));
        }
        DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);

        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => 'workflow.version.published', 'subject_type' => 'workflow_versions', 'subject_id' => (string) $versionId,
            'metadata' => json_encode(['before' => null, 'after' => ['workflow' => self::CODE, 'version' => 1], 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Rolled back only before any service request is made.
     */
    public function down(): void
    {
        $definitionId = DB::table('workflow_definitions')->where('code', self::CODE)->value('id');
        if (! $definitionId) {
            return;
        }
        $versionIds = DB::table('workflow_versions')->where('workflow_definition_id', $definitionId)->pluck('id');
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versionIds)->exists()) {
            throw new RuntimeException('توجد طلبات خدمات؛ لا يمكن التراجع.');
        }
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versionIds)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versionIds)->delete();
        DB::table('workflow_versions')->whereIn('id', $versionIds)->delete();
        DB::table('custom_field_definitions')->where('entity_type', 'aims_request')->where('scope_key', self::CODE)->delete();
        DB::table('workflow_definitions')->where('id', $definitionId)->delete();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: ?array<string, mixed>}>  $transitions
     */
    private function createVersion(int $definitionId, array $steps, array $transitions): int
    {
        $now = now();
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft',
            'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $stepIds = [];
        foreach ($steps as [$code, $name, $order, $type, $assignment, $role, $config]) {
            $stepIds[$code] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $code, 'name' => $name, 'sort_order' => $order,
                'step_type' => $type, 'assignment_type' => $assignment, 'assigned_role_code' => $role,
                'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($transitions as [$from, $to, $action, $name, $requiresComment, $conditions]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $stepIds[$from], 'to_step_id' => $stepIds[$to],
                'action_code' => $action, 'name' => $name, 'conditions' => $conditions === null ? null : json_encode($conditions, JSON_UNESCAPED_UNICODE),
                'requires_comment' => $requiresComment, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $versionId;
    }
};
