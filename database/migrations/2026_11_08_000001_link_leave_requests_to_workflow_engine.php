<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave requests run through the workflow engine (owner decisions of 2026-10-22, «Dynamic Leave Management»).
 *
 * - A workflow definition can belong to a module record (here: a leave request) instead of a generic request.
 * - A leave request keeps a link to its workflow instance; the legacy single-step mode stays available.
 * - "returned" is a leave status (sent back to the employee for changes), and "workflow" an approval mode.
 * - Administrative delegations: the executive director may delegate a scoped approval (e.g. the final
 *   approval of employees' leave) to a named person for a period. Nothing is delegated by this migration.
 * - Two leave workflows are created as drafts, following the approved chain:
 *   employee → department head (review and recommendation) → executive director (final decision);
 *   department heads → executive director directly.
 *
 * Additive only. Backup taken before running: C:\Projects\al-amal-backups\al_amal_db_20261022_082841_before_leave_workflow_link.dump
 */
return new class extends Migration
{
    private const LEAVE_SUBJECT = 'employee_leave_request';

    /** @var list<array{0: string, 1: string, 2: string}> code, label, description */
    private const EVENTS = [
        ['approval_delegation_issued', 'تفويض إداري صادر لك', 'للمفوَّض عند إصدار المدير التنفيذي تفويضًا له.'],
        ['approval_delegation_revoked', 'إلغاء تفويض إداري', 'للمفوَّض عند إلغاء التفويض أو انتهائه وإعادة معاملاته.'],
        ['leave_decision_notice', 'إشعار بقرار إجازة', 'لرئيس قسم الموظف وللموارد البشرية بعد اعتماد الإجازة أو رفضها.'],
    ];

    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->string('subject_type', 60)->default('aims_request')->after('code');
        });

        Schema::table('employee_leave_requests', function (Blueprint $table): void {
            $table->foreignId('workflow_instance_id')->nullable()->after('status')->unique()
                ->constrained('workflow_instances')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE employee_leave_requests DROP CONSTRAINT leave_request_status_check');
        DB::statement("ALTER TABLE employee_leave_requests ADD CONSTRAINT leave_request_status_check CHECK (status IN ('draft', 'submitted', 'manager_approved', 'returned', 'approved', 'rejected', 'cancelled'))");

        Schema::table('leave_module_settings', function (Blueprint $table): void {
            $table->string('employee_workflow_code', 100)->nullable();
            $table->string('head_workflow_code', 100)->nullable();
        });
        DB::statement('ALTER TABLE leave_module_settings DROP CONSTRAINT leave_settings_modes_check');
        DB::statement("ALTER TABLE leave_module_settings ADD CONSTRAINT leave_settings_modes_check CHECK (
            (counting_mode IS NULL OR counting_mode IN ('calendar', 'working'))
            AND (approval_mode IS NULL OR approval_mode IN ('hr_only', 'manager_then_hr', 'department_or_executive', 'workflow'))
            AND (grant_mode IS NULL OR grant_mode = 'full_year'))");

        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_code', 100);
            $table->foreignId('delegator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_user_id')->constrained('users')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('decision_reference', 150);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->index(['scope_code', 'status']);
            $table->index(['delegate_user_id', 'status']);
        });
        DB::statement("ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_status_check CHECK (status IN ('active', 'revoked'))");
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_period_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_parties_check CHECK (delegator_user_id <> delegate_user_id)');
        // One active delegation per scope: a new one requires revoking the current one first.
        DB::statement("CREATE UNIQUE INDEX approval_delegations_one_active ON approval_delegations (scope_code) WHERE status = 'active'");

        foreach (self::EVENTS as [$code, $label, $description]) {
            DB::table('notification_settings')->insertOrIgnore([
                'event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! DB::table('navigation_items')->where('code', 'approval_delegations')->exists()) {
            $id = DB::table('navigation_items')->insertGetId([
                'code' => 'approval_delegations', 'parent_id' => null, 'label_ar' => 'التفويضات الإدارية',
                'icon' => 'key', 'route_name' => 'admin.approval-delegations.index', 'access_rule' => 'approval_delegations',
                'sort_order' => 35, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit('navigation.item.created', 'navigation_items', $id, ['code' => 'approval_delegations', 'label_ar' => 'التفويضات الإدارية']);
        }

        $this->createWorkflow('LEAVE_EMPLOYEE', 'طلب إجازة موظف', 'إجازات الموظفين: مراجعة وتوصية رئيس القسم، ثم الاعتماد النهائي للمدير التنفيذي (أو لمن يفوّضه رسميًا).', [
            ['requester', 'تقديم طلب الإجازة', 10, 'start', 'requester', null, null],
            ['head_review', 'مراجعة رئيس القسم والتوصية', 20, 'task', 'department_head', null, ['fallback_role_code' => 'executive_director']],
            ['final_decision', 'الاعتماد النهائي', 30, 'decision', 'role', 'executive_director', ['department_scope' => 'any', 'delegation_scope' => 'leave.final_approval']],
            ['approved', 'اعتماد الإجازة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'head_review', 'submit', 'إرسال إلى رئيس القسم', false],
            ['head_review', 'final_decision', 'recommend', 'موافقة مبدئية ورفع للاعتماد النهائي', false],
            ['head_review', 'requester', 'return', 'إعادة للموظف للتعديل', true],
            ['head_review', 'rejected', 'reject', 'رفض الطلب', true],
            ['final_decision', 'approved', 'approve', 'اعتماد الإجازة', false],
            ['final_decision', 'head_review', 'return', 'إعادة إلى رئيس القسم', true],
            ['final_decision', 'rejected', 'reject', 'رفض الإجازة', true],
        ]);

        $this->createWorkflow('LEAVE_HEAD', 'طلب إجازة رئيس قسم', 'إجازات رؤساء الأقسام (ومنهم رئيس الموارد البشرية): قرار المدير التنفيذي مباشرة، ثم إشعار الموارد البشرية.', [
            ['requester', 'تقديم طلب الإجازة', 10, 'start', 'requester', null, null],
            ['final_decision', 'قرار المدير التنفيذي', 20, 'decision', 'role', 'executive_director', ['department_scope' => 'any']],
            ['approved', 'اعتماد الإجازة', 900, 'end', null, null, ['outcome' => 'completed']],
            ['rejected', 'رفض الطلب', 910, 'end', null, null, ['outcome' => 'rejected']],
        ], [
            ['requester', 'final_decision', 'submit', 'إرسال إلى المدير التنفيذي', false],
            ['final_decision', 'approved', 'approve', 'اعتماد الإجازة', false],
            ['final_decision', 'requester', 'return', 'إعادة لمقدم الطلب للتعديل', true],
            ['final_decision', 'rejected', 'reject', 'رفض الإجازة', true],
        ]);
    }

    /**
     * Rollback for a failed rollout only: refused once a leave request is linked to a workflow.
     */
    public function down(): void
    {
        if (DB::table('employee_leave_requests')->whereNotNull('workflow_instance_id')->exists()
            || DB::table('employee_leave_requests')->where('status', 'returned')->exists()) {
            throw new RuntimeException('توجد طلبات إجازة مرتبطة بمسار العمل؛ لا يمكن التراجع دون ترحيل معتمد.');
        }

        $definitionIds = DB::table('workflow_definitions')->where('subject_type', self::LEAVE_SUBJECT)->pluck('id');
        $versionIds = DB::table('workflow_versions')->whereIn('workflow_definition_id', $definitionIds)->pluck('id');
        if (DB::table('workflow_instances')->whereIn('workflow_version_id', $versionIds)->exists()) {
            throw new RuntimeException('توجد معاملات على مسارات الإجازات؛ لا يمكن التراجع.');
        }
        DB::table('workflow_transitions')->whereIn('workflow_version_id', $versionIds)->delete();
        DB::table('workflow_steps')->whereIn('workflow_version_id', $versionIds)->delete();
        DB::table('workflow_versions')->whereIn('id', $versionIds)->delete();
        DB::table('workflow_definitions')->whereIn('id', $definitionIds)->delete();

        DB::table('navigation_items')->where('code', 'approval_delegations')->update(['is_active' => false, 'updated_at' => now()]);
        Schema::dropIfExists('approval_delegations');

        DB::table('leave_module_settings')->where('approval_mode', 'workflow')->update(['approval_mode' => 'department_or_executive']);
        DB::statement('ALTER TABLE leave_module_settings DROP CONSTRAINT leave_settings_modes_check');
        DB::statement("ALTER TABLE leave_module_settings ADD CONSTRAINT leave_settings_modes_check CHECK (
            (counting_mode IS NULL OR counting_mode IN ('calendar', 'working'))
            AND (approval_mode IS NULL OR approval_mode IN ('hr_only', 'manager_then_hr', 'department_or_executive'))
            AND (grant_mode IS NULL OR grant_mode = 'full_year'))");
        Schema::table('leave_module_settings', function (Blueprint $table): void {
            $table->dropColumn(['employee_workflow_code', 'head_workflow_code']);
        });

        DB::statement('ALTER TABLE employee_leave_requests DROP CONSTRAINT leave_request_status_check');
        DB::statement("ALTER TABLE employee_leave_requests ADD CONSTRAINT leave_request_status_check CHECK (status IN ('draft', 'submitted', 'manager_approved', 'approved', 'rejected', 'cancelled'))");
        Schema::table('employee_leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workflow_instance_id');
        });

        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->dropColumn('subject_type');
        });
    }

    /**
     * A workflow definition with version 1 as a draft. The system admin reviews it and publishes it from
     * «مسارات العمل»; publishing validates the path and is recorded in the audit log.
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: string, 4: ?string, 5: ?string, 6: ?array<string, mixed>}>  $steps
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>  $transitions
     */
    private function createWorkflow(string $code, string $name, string $description, array $steps, array $transitions): void
    {
        if (DB::table('workflow_definitions')->where('code', $code)->exists()) {
            return;
        }
        $now = now();
        $definitionId = DB::table('workflow_definitions')->insertGetId([
            'code' => $code, 'subject_type' => self::LEAVE_SUBJECT, 'name' => $name, 'description' => $description,
            'is_active' => true, 'is_user_startable' => false, 'reference_prefix' => 'LV', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft',
            'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $stepIds = [];
        foreach ($steps as [$stepCode, $stepName, $order, $type, $assignment, $role, $config]) {
            $stepIds[$stepCode] = DB::table('workflow_steps')->insertGetId([
                'workflow_version_id' => $versionId, 'code' => $stepCode, 'name' => $stepName, 'sort_order' => $order,
                'step_type' => $type, 'assignment_type' => $assignment, 'assigned_role_code' => $role,
                'assignment_config' => $config === null ? null : json_encode($config), 'sla_hours' => null,
                'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($transitions as [$from, $to, $action, $label, $requiresComment]) {
            DB::table('workflow_transitions')->insert([
                'workflow_version_id' => $versionId, 'from_step_id' => $stepIds[$from], 'to_step_id' => $stepIds[$to],
                'action_code' => $action, 'name' => $label, 'conditions' => null, 'requires_comment' => $requiresComment,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->audit('workflow.definition.created', 'workflow_definitions', $definitionId, ['code' => $code, 'name' => $name, 'subject_type' => self::LEAVE_SUBJECT, 'version' => 'draft']);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => $id,
            'metadata' => json_encode(['before' => null, 'after' => $after, 'source' => 'قرار صاحب المشروع 2026-10-22 (مسارات الإجازات)'], JSON_UNESCAPED_UNICODE),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
