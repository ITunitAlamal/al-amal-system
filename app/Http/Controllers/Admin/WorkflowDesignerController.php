<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Workflow\ApprovalDelegations;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\ConditionEvaluator;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowPublishingService;
use App\Services\Workflow\WorkflowSubjects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workflow designer (SRS CFG05 / §84 مصمم سير العمل). System administrators only.
 * Only draft versions can be edited; published versions are read-only.
 */
class WorkflowDesignerController extends Controller
{
    private const STEP_TYPES = ['start' => 'بداية', 'task' => 'مهمة', 'decision' => 'قرار', 'end' => 'نهاية'];

    public function __construct(
        private readonly WorkflowPublishingService $publishing,
        private readonly ConditionEvaluator $conditions,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $versions = DB::table('workflow_versions')->orderBy('version_number')
            ->get(['id', 'workflow_definition_id', 'version_number', 'status', 'published_at'])
            ->groupBy('workflow_definition_id');

        return Inertia::render('Admin/Workflows/Index', [
            'definitions' => DB::table('workflow_definitions')->orderBy('name')->get()
                ->map(fn (object $definition): array => [
                    ...(array) $definition,
                    'subject_label' => WorkflowSubjects::LABELS[$definition->subject_type] ?? $definition->subject_type,
                    'is_module' => $definition->subject_type !== WorkflowEngine::SUBJECT_TYPE,
                    'versions' => $versions->get($definition->id, collect())->values(),
                ]),
        ]);
    }

    public function storeDefinition(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', Rule::unique('workflow_definitions', 'code')],
            ...$this->definitionRules(),
        ]);

        $versionId = DB::transaction(function () use ($request, $data): int {
            $now = now();
            $definitionId = DB::table('workflow_definitions')->insertGetId([...$data, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            $versionId = DB::table('workflow_versions')->insertGetId([
                'workflow_definition_id' => $definitionId, 'version_number' => 1, 'status' => 'draft',
                'created_by' => $request->user()->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            // A minimal skeleton: the request is created by its requester and ends approved or rejected.
            foreach ([
                ['submission', 'تقديم الطلب', 10, 'start', 'requester', null],
                ['completed', 'مكتمل', 900, 'end', null, ['outcome' => 'completed']],
                ['rejected', 'مرفوض', 910, 'end', null, ['outcome' => 'rejected']],
            ] as [$code, $name, $order, $type, $assignment, $config]) {
                DB::table('workflow_steps')->insert([
                    'workflow_version_id' => $versionId, 'code' => $code, 'name' => $name, 'sort_order' => $order,
                    'step_type' => $type, 'assignment_type' => $assignment,
                    'assignment_config' => $config ? json_encode($config) : null,
                    'is_required' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->audit->recordRow('workflow.definition.created', 'workflow_definitions', $definitionId, null, $data, $request->user()->id, $request->ip());

            return $versionId;
        });

        return redirect()->route('admin.workflows.versions.edit', $versionId)->with('success', 'تم إنشاء المسار كمسودة. أضف المراحل والإجراءات ثم انشره.');
    }

    public function updateDefinition(Request $request, int $definition): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([...$this->definitionRules(), 'is_active' => ['required', 'boolean']]);

        DB::transaction(function () use ($request, $definition, $data): void {
            $before = DB::table('workflow_definitions')->where('id', $definition)->lockForUpdate()->first();
            abort_unless($before, 404);
            // A module workflow (e.g. leave) starts from its module screen, never from «طلباتي».
            if ($before->subject_type !== WorkflowEngine::SUBJECT_TYPE) {
                $data['is_user_startable'] = false;
            }
            DB::table('workflow_definitions')->where('id', $definition)->update([...$data, 'updated_at' => now()]);
            $this->audit->recordRow('workflow.definition.updated', 'workflow_definitions', $definition, (array) $before, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تم تحديث بيانات المسار.');
    }

    public function draft(Request $request, int $definition): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(DB::table('workflow_definitions')->where('id', $definition)->exists(), 404);
        $versionId = $this->publishing->draftFor($definition, $request->user(), $request->ip());

        return redirect()->route('admin.workflows.versions.edit', $versionId);
    }

    public function edit(Request $request, int $version): Response
    {
        $this->authorizeAdmin($request);
        $row = DB::table('workflow_versions')->where('id', $version)->first();
        abort_unless($row, 404);
        $definition = DB::table('workflow_definitions')->where('id', $row->workflow_definition_id)->first();

        return Inertia::render('Admin/Workflows/Edit', [
            'definition' => [...(array) $definition, 'subject_label' => WorkflowSubjects::LABELS[$definition->subject_type] ?? $definition->subject_type],
            'delegationScopes' => ApprovalDelegations::SCOPES,
            'version' => $row,
            'versions' => DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)
                ->orderByDesc('version_number')->get(['id', 'version_number', 'status', 'published_at']),
            'steps' => DB::table('workflow_steps')->where('workflow_version_id', $version)->orderBy('sort_order')->get()
                ->map(fn (object $step): array => [...(array) $step, 'assignment_config' => json_decode((string) $step->assignment_config, true) ?: (object) []]),
            'transitions' => DB::table('workflow_transitions')->where('workflow_version_id', $version)->orderBy('id')->get()
                ->map(fn (object $transition): array => [...(array) $transition, 'conditions' => $transition->conditions ? json_decode((string) $transition->conditions, true) : null]),
            'problems' => $row->status === 'draft' ? $this->publishing->problems($version) : [],
            'warnings' => $row->status === 'draft' ? $this->publishing->warnings($version) : [],
            'stepTypes' => self::STEP_TYPES,
            'assignmentTypes' => AssigneeResolver::TYPES,
            'departmentScopes' => AssigneeResolver::DEPARTMENT_SCOPES,
            'conditionOperators' => ConditionEvaluator::OPERATORS,
            'roles' => DB::table('roles')->orderBy('name')->get(['code', 'name']),
            'departments' => DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'users' => DB::table('users')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeStep(Request $request, int $version): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('workflow_steps', 'code')->where('workflow_version_id', $version)],
            ...$this->stepRules($version),
        ]);

        DB::transaction(function () use ($request, $version, $data): void {
            $this->lockDraft($version);
            $id = DB::table('workflow_steps')->insertGetId([...$this->stepRow($data), 'code' => $data['code'], 'workflow_version_id' => $version, 'is_required' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('workflow.step.created', 'workflow_steps', $id, null, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تمت إضافة المرحلة.');
    }

    public function updateStep(Request $request, int $version, int $step): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate($this->stepRules($version, $step));

        DB::transaction(function () use ($request, $version, $step, $data): void {
            $this->lockDraft($version);
            $before = DB::table('workflow_steps')->where('id', $step)->where('workflow_version_id', $version)->first();
            abort_unless($before, 404);
            DB::table('workflow_steps')->where('id', $step)->update([...$this->stepRow($data), 'updated_at' => now()]);
            $this->audit->recordRow('workflow.step.updated', 'workflow_steps', $step, (array) $before, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تم تحديث المرحلة.');
    }

    public function destroyStep(Request $request, int $version, int $step): RedirectResponse
    {
        $this->authorizeAdmin($request);

        DB::transaction(function () use ($request, $version, $step): void {
            $this->lockDraft($version);
            $before = DB::table('workflow_steps')->where('id', $step)->where('workflow_version_id', $version)->first();
            abort_unless($before, 404);
            DB::table('workflow_transitions')->where('workflow_version_id', $version)
                ->where(fn ($query) => $query->where('from_step_id', $step)->orWhere('to_step_id', $step))->delete();
            DB::table('workflow_steps')->where('id', $step)->delete();
            $this->audit->recordRow('workflow.step.deleted', 'workflow_steps', $step, (array) $before, null, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تم حذف المرحلة من المسودة مع إجراءاتها.');
    }

    public function storeTransition(Request $request, int $version): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validateTransition($request, $version);

        DB::transaction(function () use ($request, $version, $data): void {
            $this->lockDraft($version);
            $id = DB::table('workflow_transitions')->insertGetId([...$data, 'workflow_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('workflow.transition.created', 'workflow_transitions', $id, null, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تمت إضافة الإجراء.');
    }

    public function updateTransition(Request $request, int $version, int $transition): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $this->validateTransition($request, $version);

        DB::transaction(function () use ($request, $version, $transition, $data): void {
            $this->lockDraft($version);
            $before = DB::table('workflow_transitions')->where('id', $transition)->where('workflow_version_id', $version)->first();
            abort_unless($before, 404);
            DB::table('workflow_transitions')->where('id', $transition)->update([...$data, 'updated_at' => now()]);
            $this->audit->recordRow('workflow.transition.updated', 'workflow_transitions', $transition, (array) $before, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تم تحديث الإجراء.');
    }

    public function destroyTransition(Request $request, int $version, int $transition): RedirectResponse
    {
        $this->authorizeAdmin($request);

        DB::transaction(function () use ($request, $version, $transition): void {
            $this->lockDraft($version);
            $before = DB::table('workflow_transitions')->where('id', $transition)->where('workflow_version_id', $version)->first();
            abort_unless($before, 404);
            DB::table('workflow_transitions')->where('id', $transition)->delete();
            $this->audit->recordRow('workflow.transition.deleted', 'workflow_transitions', $transition, (array) $before, null, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تم حذف الإجراء من المسودة.');
    }

    public function publish(Request $request, int $version): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->publishing->publish($version, $request->user(), $request->ip());

        return back()->with('success', 'تم نشر الإصدار. الطلبات الجديدة تستخدمه، والطلبات الجارية تكمل على إصدارها السابق.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'تصميم مسارات العمل متاح لمدير النظام فقط.');
    }

    private function lockDraft(int $version): void
    {
        $row = DB::table('workflow_versions')->where('id', $version)->lockForUpdate()->first();
        abort_unless($row, 404);
        if ($row->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'الإصدار المنشور لا يُعدّل. أنشئ مسودة جديدة للتعديل.']);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function definitionRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_user_startable' => ['required', 'boolean'],
            'reference_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function stepRules(int $version, ?int $ignoreStep = null): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'sort_order' => ['required', 'integer', 'min:0', Rule::unique('workflow_steps', 'sort_order')->where('workflow_version_id', $version)->ignore($ignoreStep)],
            'step_type' => ['required', Rule::in(array_keys(self::STEP_TYPES))],
            'assignment_type' => ['nullable', 'required_unless:step_type,end', Rule::in(array_keys(AssigneeResolver::TYPES))],
            'assigned_role_code' => ['nullable', 'required_if:assignment_type,role,pick', Rule::exists('roles', 'code')],
            'assignment_config' => ['nullable', 'array'],
            'assignment_config.department_id' => ['nullable', 'required_if:assignment_type,department_head_of', 'integer', Rule::exists('departments', 'id')],
            'assignment_config.user_id' => ['nullable', 'required_if:assignment_type,user', 'integer', Rule::exists('users', 'id')],
            'assignment_config.department_scope' => ['nullable', Rule::in(array_keys(AssigneeResolver::DEPARTMENT_SCOPES))],
            'assignment_config.escalate_to_role_code' => ['nullable', Rule::exists('roles', 'code')],
            'assignment_config.fallback_role_code' => ['nullable', Rule::exists('roles', 'code')],
            'assignment_config.fallback_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'assignment_config.requester_may_act' => ['nullable', 'boolean'],
            'assignment_config.inform_role_codes' => ['nullable', 'array'],
            'assignment_config.inform_role_codes.*' => ['string', Rule::exists('roles', 'code')],
            'assignment_config.subject_status' => ['nullable', Rule::in(['pending_registration'])],
            // Supervision keys (StepAssignments) are installed by migrations; editing a step must not drop them.
            'assignment_config.supervisor_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'assignment_config.assignable' => ['nullable', 'boolean'],
            'assignment_config.field_work' => ['nullable', 'boolean'],
            'assignment_config.step_code' => ['nullable', 'required_if:assignment_type,actor_of_step', Rule::exists('workflow_steps', 'code')->where('workflow_version_id', $version)],
            'assignment_config.outcome' => ['nullable', Rule::in(['completed', 'rejected'])],
            'assignment_config.delegation_scope' => ['nullable', Rule::in(array_keys(ApprovalDelegations::SCOPES))],
            // A stage that records a stock issue or a payment; a reopened request never returns before it (WorkflowEngine::reopenTargets).
            'assignment_config.reopen_locked' => ['nullable', 'boolean'],
            'sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stepRow(array $data): array
    {
        $config = array_filter($data['assignment_config'] ?? [], fn ($value): bool => $value !== null && $value !== '');

        return [
            'name' => $data['name'],
            'sort_order' => $data['sort_order'],
            'step_type' => $data['step_type'],
            'assignment_type' => $data['step_type'] === 'end' ? null : $data['assignment_type'],
            'assigned_role_code' => in_array($data['assignment_type'] ?? null, ['role', 'pick'], true) ? $data['assigned_role_code'] : null,
            'assignment_config' => $config === [] ? null : json_encode($config),
            'sla_hours' => $data['step_type'] === 'end' ? null : ($data['sla_hours'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTransition(Request $request, int $version): array
    {
        $stepRule = Rule::exists('workflow_steps', 'id')->where('workflow_version_id', $version);
        $data = $request->validate([
            'from_step_id' => ['required', 'integer', $stepRule],
            'to_step_id' => ['required', 'integer', 'different:from_step_id', $stepRule],
            'action_code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:200'],
            'requires_comment' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'conditions' => ['nullable', 'array'],
        ]);

        $conditions = $data['conditions'] ?? null;
        $conditions = $conditions === [] ? null : $conditions;
        $problems = $this->conditions->problems($conditions);
        if ($problems !== []) {
            throw ValidationException::withMessages(['conditions' => $problems]);
        }
        $data['conditions'] = $conditions === null ? null : json_encode($conditions, JSON_UNESCAPED_UNICODE);

        return $data;
    }
}
