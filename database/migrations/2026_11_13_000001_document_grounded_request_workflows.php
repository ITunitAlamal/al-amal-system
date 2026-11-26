<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Request workflows aligned with the approved documents (SRS §3.4.3, §3.5, §6.7–§6.9; ED, HR and Finance
 * requirement docs). New versions are published; published versions are never edited (SRS CORE08).
 *
 * - MAINTENANCE v2: requester → head (or the ED directly when nobody heads the requester) → ED approval and
 *   referral to HR → HR classifies (internal: technician; external: ED path approval → HR cost → ED cost approval
 *   → accounts → HR coordinates → payment) → technician needs → ED approves needs → accounts → stores / purchase
 *   → execution → requester verification → ED approves closure. Finance stages fall back to the Finance head
 *   while no clerk holds the finance role, so a request never stops for lack of an assignee.
 * - LEAVE_* v2: after the final decision the head documents informing the employee and HR registers the leave;
 *   the balance is debited once, at registration (SRS LV02, §6.7). Leave status "pending_registration" added.
 * - «rentals.decide»: rental approvals and decisions belong to the executive director's role.
 * - The open maintenance request stuck on v1 (its next stages had no holder) moves to v2 at the same stage, with
 *   a timeline event and an audit row (documented migration, SRS CORE08).
 *
 * Backup taken before running: C:\Projects\al-amal-backups\al_amal_db_20261022_210929_before_request_workflows.dump
 */
return new class extends Migration
{
    private const HR = 'HR';

    private const FINANCE = 'FIN';

    private const SOURCE = 'ترحيل 2026_11_13_000001 (مواءمة مسارات الطلبات مع الوثائق المعتمدة)';

    public function up(): void
    {
        $hr = (int) DB::table('departments')->where('code', self::HR)->value('id');
        $finance = (int) DB::table('departments')->where('code', self::FINANCE)->value('id');
        if ($hr <= 0 || $finance <= 0) {
            throw new RuntimeException('قسما الموارد البشرية والحسابات مطلوبان لتهيئة المسارات.');
        }

        DB::statement('ALTER TABLE employee_leave_requests DROP CONSTRAINT leave_request_status_check');
        DB::statement("ALTER TABLE employee_leave_requests ADD CONSTRAINT leave_request_status_check CHECK (status IN ('draft', 'submitted', 'manager_approved', 'pending_registration', 'returned', 'approved', 'rejected', 'cancelled'))");

        $this->grantRentalDecisions();

        $maintenanceVersion = $this->publish('MAINTENANCE', 'إصدار مطابق للوثائق: مسار داخلي وخارجي، اعتماد المدير للاحتياجات والتكلفة والإغلاق، وبدائل لمراحل الحسابات.',
            $this->maintenanceSteps($hr, $finance), $this->maintenanceTransitions());
        $this->publish('LEAVE_EMPLOYEE', 'بعد الاعتماد: إبلاغ رئيس القسم للموظف ثم تسجيل الموارد البشرية والخصم مرة واحدة.', [
            ['requester', 'تقديم طلب الإجازة', 10, 'start', 'requester', null, null],
            ['head_review', 'مراجعة رئيس القسم والتوصية', 20, 'task', 'department_head', null, null],
            ['final_decision', 'الاعتماد النهائي', 30, 'decision', 'role', 'executive_director', ['department_scope' => 'any', 'delegation_scope' => 'leave.final_approval']],
            ['head_notice', 'إبلاغ الموظف بالقرار وتوثيقه', 40, 'task', 'department_head', null, ['fallback_department_id' => $hr, 'subject_status' => 'pending_registration']],
            ['hr_registration', 'تسجيل الإجازة وخصم الرصيد', 50, 'task', 'department_head_of', null, ['department_id' => $hr, 'fallback_role_code' => 'executive_director', 'subject_status' => 'pending_registration']],
            ['approved', 'إجازة معتمدة ومسجلة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'head_review', 'submit', 'إرسال إلى رئيس القسم', false, ['requester_has_department_head' => true]],
            ['requester', 'final_decision', 'submit_executive', 'إرسال للاعتماد النهائي مباشرة (لا يوجد رئيس قسم)', false, ['requester_has_department_head' => false]],
            ['head_review', 'final_decision', 'recommend', 'موافقة مبدئية ورفع للاعتماد النهائي', false, null],
            ['head_review', 'requester', 'return', 'إعادة للموظف للتعديل', true, null],
            ['head_review', 'rejected', 'reject', 'رفض الطلب', true, null],
            ['final_decision', 'head_notice', 'approve', 'اعتماد الإجازة', false, null],
            ['final_decision', 'requester', 'return', 'إعادة لمقدم الطلب للاستكمال', true, null],
            ['final_decision', 'rejected', 'reject', 'رفض الإجازة', true, null],
            ['head_notice', 'hr_registration', 'notified', 'تم إبلاغ الموظف (اذكر الطريقة والوقت)', true, null],
            ['hr_registration', 'approved', 'register', 'تسجيل الإجازة وخصم الرصيد', false, null],
            ['hr_registration', 'final_decision', 'return', 'إعادة إلى صاحب الاعتماد (تعارض في الرصيد أو البيانات)', true, null],
        ]);
        $this->publish('LEAVE_HEAD', 'بعد قرار المدير التنفيذي: تسجيل الموارد البشرية والخصم مرة واحدة.', [
            ['requester', 'تقديم طلب الإجازة', 10, 'start', 'requester', null, null],
            ['final_decision', 'قرار المدير التنفيذي', 20, 'decision', 'role', 'executive_director', ['department_scope' => 'any']],
            ['hr_registration', 'تسجيل الإجازة وخصم الرصيد', 30, 'task', 'department_head_of', null, ['department_id' => $hr, 'fallback_role_code' => 'executive_director', 'subject_status' => 'pending_registration']],
            ['approved', 'إجازة معتمدة ومسجلة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'final_decision', 'submit', 'إرسال إلى المدير التنفيذي', false, null],
            ['final_decision', 'hr_registration', 'approve', 'اعتماد الإجازة', false, null],
            ['final_decision', 'requester', 'return', 'إعادة لمقدم الطلب للتعديل', true, null],
            ['final_decision', 'rejected', 'reject', 'رفض الإجازة', true, null],
            ['hr_registration', 'approved', 'register', 'تسجيل الإجازة وخصم الرصيد', false, null],
            ['hr_registration', 'final_decision', 'return', 'إعادة إلى المدير التنفيذي (تعارض في الرصيد أو البيانات)', true, null],
        ]);
        $this->publish('LEAVE_EXECUTIVE', 'بعد قرار مجلس الإدارة: تسجيل الموارد البشرية والخصم مرة واحدة.', [
            ['requester', 'تقديم طلب الإجازة', 10, 'start', 'requester', null, null],
            ['board_decision', 'قرار مجلس الإدارة', 20, 'decision', 'role', 'board_secretary', ['department_scope' => 'any']],
            ['hr_registration', 'تسجيل الإجازة وخصم الرصيد', 30, 'task', 'department_head_of', null, ['department_id' => $hr, 'subject_status' => 'pending_registration']],
            ['approved', 'إجازة معتمدة ومسجلة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'board_decision', 'submit', 'إرسال إلى مجلس الإدارة', false, null],
            ['board_decision', 'hr_registration', 'approve', 'اعتماد بقرار مجلس الإدارة (اذكر رقم القرار)', true, null],
            ['board_decision', 'requester', 'return', 'إعادة للمدير التنفيذي للتعديل', true, null],
            ['board_decision', 'rejected', 'reject', 'رفض بقرار مجلس الإدارة', true, null],
            ['hr_registration', 'approved', 'register', 'تسجيل الإجازة وخصم الرصيد', false, null],
            ['hr_registration', 'board_decision', 'return', 'إعادة إلى أمين سر المجلس (تعارض في الرصيد أو البيانات)', true, null],
        ]);

        $this->migrateOpenMaintenance($maintenanceVersion);
    }

    /**
     * Rolled back only before any request runs on the new versions.
     */
    public function down(): void
    {
        $versions = DB::table('audit_logs')->where('action', 'workflow.version.published')->where('subject_type', 'workflow_versions')
            ->whereRaw("metadata::jsonb ->> 'source' = ?", [self::SOURCE])->pluck('subject_id')->map(fn ($id): int => (int) $id);
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versions)->exists()) {
            throw new RuntimeException('توجد معاملات على الإصدارات الجديدة؛ التراجع يتطلب ترحيلًا موثقًا.');
        }
        if (DB::table('employee_leave_requests')->where('status', 'pending_registration')->exists()) {
            throw new RuntimeException('توجد إجازات بانتظار التسجيل؛ لا يمكن التراجع.');
        }
        DB::statement('ALTER TABLE employee_leave_requests DROP CONSTRAINT leave_request_status_check');
        DB::statement("ALTER TABLE employee_leave_requests ADD CONSTRAINT leave_request_status_check CHECK (status IN ('draft', 'submitted', 'manager_approved', 'returned', 'approved', 'rejected', 'cancelled'))");
        foreach ($versions as $versionId) {
            $definition = DB::table('workflow_versions')->where('id', $versionId)->value('workflow_definition_id');
            $previous = DB::table('workflow_versions')->where('workflow_definition_id', $definition)->where('id', '<>', $versionId)->orderByDesc('version_number')->value('id');
            DB::table('workflow_transitions')->where('workflow_version_id', $versionId)->delete();
            DB::table('workflow_steps')->where('workflow_version_id', $versionId)->delete();
            DB::table('workflow_versions')->where('id', $versionId)->delete();
            DB::table('workflow_versions')->where('id', $previous)->update(['status' => 'published', 'updated_at' => now()]);
        }
        $permission = DB::table('permissions')->where('code', 'rentals.decide')->value('id');
        if ($permission) {
            DB::table('permission_role')->where('permission_id', $permission)->delete();
            DB::table('permissions')->where('id', $permission)->delete();
        }
    }

    private function grantRentalDecisions(): void
    {
        $decide = $this->ensure('permissions', ['code' => 'rentals.decide'], ['name' => 'القرار في طلبات الاستئجار واعتماد العقود ومعاملات التجديد والإخلاء والتسوية']);
        $view = (int) DB::table('permissions')->where('code', 'rentals.view')->value('id');
        $executive = (int) DB::table('roles')->where('code', 'executive_director')->value('id');
        if ($executive <= 0) {
            return;
        }
        foreach (array_filter([$decide, $view]) as $permission) {
            if (! DB::table('permission_role')->where('role_id', $executive)->where('permission_id', $permission)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $executive, 'permission_id' => $permission]);
            }
        }
        $this->audit('access.permission.granted', 'roles', $executive, ['role' => 'executive_director', 'permissions' => ['rentals.decide', 'rentals.view']]);
    }

    /**
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>
     */
    private function maintenanceSteps(int $hr, int $finance): array
    {
        $any = ['department_scope' => 'any'];
        $hrStage = ['department_id' => $hr, 'requester_may_act' => true, 'fallback_role_code' => 'executive_director'];
        $financeStage = fn (array $extra = []): array => [...$any, 'requester_may_act' => true, ...$extra, 'fallback_department_id' => $finance];

        return [
            ['requester', 'إنشاء وإرسال الطلب', 10, 'start', 'requester', null, null],
            ['department_head', 'مراجعة رئيس قسم مقدم الطلب', 20, 'task', 'department_head', null, null],
            ['executive_director', 'اعتماد المدير التنفيذي والإحالة إلى الموارد البشرية', 30, 'decision', 'role', 'executive_director', $any],
            ['hr_head', 'تصنيف الموارد البشرية (داخلي/خارجي) وتكليف الفني', 40, 'decision', 'department_head_of', null, $hrStage],
            ['technician', 'الفحص الفني وتحديد الاحتياجات', 50, 'task', 'pick', 'maintenance_technician', [...$any, 'requester_may_act' => true]],
            ['ed_needs', 'اعتماد المدير التنفيذي للاحتياجات', 60, 'decision', 'role', 'executive_director', $any],
            ['accounting', 'مراجعة الحسابات والتحقق عبر المخازن', 70, 'task', 'role', 'finance_accountant', $financeStage()],
            ['stores', 'فحص توفر المواد في المخزن', 80, 'decision', 'role', 'finance_warehouse', $financeStage(['fallback_role_code' => 'storekeeper'])],
            ['procurement', 'تقدير تكلفة الشراء', 90, 'task', 'role', 'finance_procurement', $financeStage(['fallback_role_code' => 'procurement_officer'])],
            ['financial_approval', 'الاعتماد المالي للشراء من المدير التنفيذي', 100, 'decision', 'role', 'executive_director', $any],
            ['higher_approval', 'اعتماد الجهة الأعلى صلاحية', 110, 'task', 'role', 'finance_board_treasurer', $any],
            ['purchase', 'تنفيذ الشراء واستلام المواد', 120, 'task', 'role', 'finance_procurement', $financeStage(['fallback_role_code' => 'procurement_officer'])],
            ['materials', 'صرف المواد وتسليمها للفني', 130, 'task', 'role', 'finance_warehouse', $financeStage(['fallback_role_code' => 'storekeeper'])],
            ['execution', 'تنفيذ أعمال الصيانة وتقرير الإنجاز', 140, 'task', 'actor_of_step', null, ['step_code' => 'technician', 'requester_may_act' => true]],
            ['verification', 'تحقق مقدم الطلب من الإنجاز', 150, 'task', 'requester', null, null],
            ['ed_external', 'موافقة المدير التنفيذي على التنفيذ الخارجي', 160, 'decision', 'role', 'executive_director', $any],
            ['hr_cost', 'إعداد التكلفة ومستندات التنفيذ الخارجي', 170, 'task', 'department_head_of', null, $hrStage],
            ['ed_cost', 'اعتماد المدير التنفيذي للتكلفة', 180, 'decision', 'role', 'executive_director', $any],
            ['ext_accounting', 'الحسابات: الموازنة وتوفير الاحتياجات للتنفيذ الخارجي', 190, 'task', 'role', 'finance_accountant', $financeStage()],
            ['ext_execution', 'تنسيق التنفيذ الخارجي ومحضر الاستلام والإنجاز', 200, 'task', 'department_head_of', null, $hrStage],
            ['ext_payment', 'صرف الأجرة وفق العقد ومحضر الاستلام', 210, 'task', 'role', 'finance_cash_executor', $financeStage()],
            ['ext_verification', 'تحقق مقدم الطلب من التنفيذ الخارجي', 220, 'task', 'requester', null, null],
            ['ed_closure', 'اعتماد الإغلاق من المدير التنفيذي', 230, 'decision', 'role', 'executive_director', $any],
            ['completed', 'إغلاق طلب الصيانة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: ?array<string, mixed>}>
     */
    private function maintenanceTransitions(): array
    {
        return [
            ['requester', 'department_head', 'submit', 'إرسال إلى رئيس القسم', false, ['requester_has_department_head' => true]],
            ['requester', 'executive_director', 'submit_executive', 'إرسال إلى المدير التنفيذي مباشرة (لا يوجد رئيس أعلى)', false, ['requester_has_department_head' => false]],
            ['department_head', 'executive_director', 'forward', 'رفع إلى المدير التنفيذي', false, null],
            ['department_head', 'requester', 'return', 'إرجاع للموظف', true, null],
            ['department_head', 'rejected', 'reject', 'رفض الطلب', true, null],
            ['executive_director', 'hr_head', 'approve', 'اعتماد وإحالة إلى الموارد البشرية', false, null],
            ['executive_director', 'requester', 'return', 'إعادة لمقدم الطلب للاستكمال', true, null],
            ['executive_director', 'rejected', 'reject', 'رفض من المدير التنفيذي', true, null],
            ['hr_head', 'technician', 'assign', 'داخلي: تكليف الفني', false, null],
            ['hr_head', 'ed_external', 'external', 'خارجي: رفع طلب التنفيذ الخارجي للمدير (اذكر المبرر)', true, null],
            ['hr_head', 'executive_director', 'return', 'إعادة إلى المدير التنفيذي', true, null],
            ['technician', 'ed_needs', 'submit_needs', 'رفع الاحتياجات لاعتماد المدير التنفيذي', false, null],
            ['technician', 'execution', 'no_materials', 'لا يحتاج مواد: بدء التنفيذ', false, null],
            ['technician', 'hr_head', 'return', 'إعادة إلى الموارد البشرية (يتطلب تنفيذًا خارجيًا أو استكمالًا)', true, null],
            ['ed_needs', 'accounting', 'approve', 'اعتماد الاحتياجات وإحالتها للحسابات', false, null],
            ['ed_needs', 'technician', 'return', 'إعادة للفني لتعديل الاحتياجات', true, null],
            ['ed_needs', 'rejected', 'reject', 'رفض الاحتياجات', true, null],
            ['accounting', 'stores', 'check_stock', 'إحالة لفحص المخزن', false, null],
            ['accounting', 'ed_needs', 'return', 'إعادة إلى المدير التنفيذي', true, null],
            ['stores', 'materials', 'available', 'المواد متوفرة', false, ['stock_status' => 'available']],
            ['stores', 'procurement', 'unavailable', 'المواد غير متوفرة أو ناقصة', false, ['stock_status' => ['unavailable', 'partial']]],
            ['procurement', 'financial_approval', 'request_approval', 'إرسال تكلفة الشراء للاعتماد', false, null],
            ['financial_approval', 'purchase', 'approve', 'اعتماد الشراء (حتى 500 ₪)', false, ['estimated_cost' => ['lte' => 500]]],
            ['financial_approval', 'higher_approval', 'escalate', 'تصعيد للجهة الأعلى', true, ['estimated_cost' => ['gt' => 500]]],
            ['financial_approval', 'procurement', 'return', 'إرجاع للمشتريات', true, null],
            ['financial_approval', 'rejected', 'reject', 'رفض الشراء', true, null],
            ['higher_approval', 'purchase', 'approve', 'اعتماد الجهة الأعلى', false, null],
            ['higher_approval', 'procurement', 'return', 'إرجاع للمشتريات', true, null],
            ['purchase', 'materials', 'received', 'استلام المواد', false, null],
            ['materials', 'execution', 'issue', 'صرف المواد للفني', false, null],
            ['execution', 'verification', 'finish', 'إتمام الصيانة ورفع التقرير', false, null],
            ['verification', 'ed_closure', 'accept', 'تأكيد الإنجاز ورفعه لاعتماد الإغلاق', false, null],
            ['verification', 'execution', 'return', 'إرجاع لاستكمال الصيانة', true, null],
            ['ed_external', 'hr_cost', 'approve', 'الموافقة على التنفيذ الخارجي وإعادتها لإعداد التكلفة', false, null],
            ['ed_external', 'hr_head', 'return', 'إعادة إلى الموارد البشرية', true, null],
            ['ed_external', 'rejected', 'reject', 'رفض التنفيذ الخارجي', true, null],
            ['hr_cost', 'ed_cost', 'submit_cost', 'رفع التكلفة والمستندات للمدير التنفيذي', true, null],
            ['ed_cost', 'ext_accounting', 'approve', 'اعتماد التكلفة وإحالتها للحسابات', false, null],
            ['ed_cost', 'hr_cost', 'return', 'إعادة لتعديل التكلفة', true, null],
            ['ed_cost', 'rejected', 'reject', 'رفض التكلفة', true, null],
            ['ext_accounting', 'ext_execution', 'provide', 'تأكيد الموازنة وتوفير الاحتياجات للموارد البشرية', false, null],
            ['ext_accounting', 'ed_cost', 'return', 'إعادة إلى المدير التنفيذي', true, null],
            ['ext_execution', 'ext_payment', 'completed_work', 'إثبات الإنجاز ومحضر الاستلام ورفعه للصرف', true, null],
            ['ext_payment', 'ext_verification', 'paid', 'تم صرف الأجرة وفق العقد', false, null],
            ['ext_payment', 'ext_execution', 'return', 'إعادة لاستكمال المستندات', true, null],
            ['ext_verification', 'ed_closure', 'accept', 'تأكيد الإنجاز ورفعه لاعتماد الإغلاق', false, null],
            ['ext_verification', 'ext_execution', 'return', 'إرجاع لاستكمال التنفيذ الخارجي', true, null],
            ['ed_closure', 'completed', 'close', 'اعتماد الإغلاق', false, null],
            ['ed_closure', 'hr_head', 'return', 'إعادة للمتابعة لدى الموارد البشرية', true, null],
        ];
    }

    /**
     * New version of an existing definition, validated like a manual publish, then published (the previous one retired).
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool, 5: ?array<string, mixed>}>  $transitions
     */
    private function publish(string $code, string $note, array $steps, array $transitions): int
    {
        $definition = DB::table('workflow_definitions')->where('code', $code)->first();
        if (! $definition) {
            throw new RuntimeException("تعريف المسار {$code} غير موجود.");
        }
        $now = now();
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definition->id,
            'version_number' => (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1,
            'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $stepIds = [];
        foreach ($steps as [$stepCode, $name, $order, $type, $assignment, $role, $config]) {
            $stepIds[$stepCode] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $stepCode, 'name' => $name, 'sort_order' => $order,
                'step_type' => $type, 'assignment_type' => $assignment, 'assigned_role_code' => $role,
                'assignment_config' => $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE),
                'sla_hours' => null, 'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($transitions as [$from, $to, $action, $name, $requiresComment, $conditions]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $stepIds[$from], 'to_step_id' => $stepIds[$to],
                'action_code' => $action, 'name' => $name,
                'conditions' => $conditions === null ? null : json_encode($conditions, JSON_UNESCAPED_UNICODE),
                'requires_comment' => $requiresComment, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $problems = app(WorkflowPublishingService::class)->problems($versionId);
        if ($problems !== []) {
            throw new RuntimeException("تعذر نشر إصدار {$code}: ".implode(' | ', $problems));
        }
        $retired = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')->pluck('id')->all();
        DB::table('workflow_versions')->whereIn('id', $retired)->update(['status' => 'retired', 'updated_at' => $now]);
        DB::table('workflow_versions')->where('id', $versionId)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        $this->audit('workflow.version.published', 'workflow_versions', $versionId, ['workflow' => $code, 'retired_versions' => $retired, 'note' => $note]);

        return $versionId;
    }

    /**
     * Open maintenance requests on the retired version move to the same stage of the new version (SRS CORE08: a
     * request changes version only by a documented migration). Their assignees and history are kept.
     */
    private function migrateOpenMaintenance(int $versionId): void
    {
        $definition = DB::table('workflow_versions')->where('id', $versionId)->value('workflow_definition_id');
        $instances = DB::table('workflow_instances as i')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('v.workflow_definition_id', $definition)->where('i.workflow_version_id', '<>', $versionId)
            ->where('i.status', 'in_progress')
            ->get(['i.*', 's.code as step_code']);

        foreach ($instances as $instance) {
            $target = DB::table('workflow_steps')->where('workflow_version_id', $versionId)->where('code', $instance->step_code)->first();
            if (! $target) {
                continue; // no matching stage: it stays on its version and appears in the readiness checks
            }
            $now = now();
            DB::table('workflow_instances')->where('id', $instance->id)->update([
                'workflow_version_id' => $versionId, 'current_step_id' => $target->id,
                'revision' => (int) $instance->revision + 1, 'updated_at' => $now,
            ]);
            DB::table('workflow_events')->insert([
                'workflow_instance_id' => $instance->id, 'workflow_transition_id' => null,
                'from_step_id' => $instance->current_step_id, 'to_step_id' => $target->id,
                'actor_id' => null, 'assigned_to_id' => $instance->current_assignee_id,
                'action' => 'version_migrated',
                'comment' => 'نُقلت المعاملة إلى الإصدار المعتمد من المسار عند المرحلة نفسها؛ المراحل التالية في الإصدار السابق لم يكن لها من ينفذها.',
                'metadata' => json_encode(['from_version_id' => (int) $instance->workflow_version_id, 'to_version_id' => $versionId, 'step' => $instance->step_code], JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now, 'created_at' => $now,
            ]);
            $this->audit('workflow.instance.migrated', 'workflow_instances', (int) $instance->id, [
                'reference' => $instance->reference_no, 'from_version_id' => (int) $instance->workflow_version_id,
                'to_version_id' => $versionId, 'step' => $instance->step_code,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => null, 'after' => $after, 'source' => self::SOURCE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     */
    private function ensure(string $table, array $key, array $values): int
    {
        $id = DB::table($table)->where($key)->value('id');

        return $id ? (int) $id : (int) DB::table($table)->insertGetId([...$key, ...$values, 'created_at' => now(), 'updated_at' => now()]);
    }
};
