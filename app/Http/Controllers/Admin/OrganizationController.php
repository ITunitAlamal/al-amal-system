<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Organization\OrganizationStructure;
use App\Services\Organization\ScopedDelegations;
use App\Services\Organization\StructureChanges;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «الهيكل التنظيمي»: the approved tree (offices, departments, functional units), structural proposals decided by
 * the executive director, the archiving review with its blockers, and the delegation policy. Organization managers
 * (system administrator, HR head) see everything; the executive director decides; a department head sees the tree
 * and proposes for their own department.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationStructure $structure,
        private readonly StructureChanges $changes,
        private readonly ScopedDelegations $delegations,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->viewer($request);
        $manager = $user->canManageOrganization();
        $executive = $this->delegations->isExecutive($user);

        return Inertia::render('Admin/Organization/Index', [
            'tree' => $this->structure->tree(),
            'types' => OrganizationStructure::TYPES,
            'changeLabels' => OrganizationStructure::CHANGE_LABELS,
            'statusLabels' => OrganizationStructure::STATUS_LABELS,
            'changes' => $this->changeRows(),
            'inventory' => $manager || $executive ? $this->structure->inventory() : [],
            'policy' => DB::table('delegable_permissions as d')->join('permissions as p', 'p.code', '=', 'd.permission_code')->orderBy('d.permission_code')
                ->get(['d.id', 'd.permission_code', 'p.name', 'd.max_days', 'd.requires_executive', 'd.is_active', 'd.note']),
            'delegations' => $this->delegations->listFor($user),
            'delegationOptions' => $executive ? $this->delegations->options($user) : ['permissions' => [], 'scopes' => []],
            'eligibleHeads' => $manager ? $this->eligibleHeads() : [],
            'can' => ['manage' => $manager, 'executive' => $executive, 'admin' => $user->isSystemAdmin(),
                'propose' => $this->changes->canPropose($user), 'headed' => $this->changes->headedDepartments($user)],
        ]);
    }

    public function updateDetails(Request $request, int $department): RedirectResponse
    {
        $user = $this->viewer($request);
        abort_unless($this->changes->canPropose($user, $department), 403, 'يعدّل الوصف مدير النظام ورئيس الموارد البشرية ورئيس القسم لقسمه ووحداته.');
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'responsibilities' => ['nullable', 'string', 'max:4000'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ], [], ['description' => 'الوصف', 'responsibilities' => 'المسؤوليات', 'effective_from' => 'تاريخ السريان']);
        DB::transaction(function () use ($request, $department, $data, $user): void {
            $before = DB::table('departments')->where('id', $department)->whereNull('deleted_at')->lockForUpdate()->first(['description', 'responsibilities', 'effective_from']);
            abort_unless($before, 404);
            DB::table('departments')->where('id', $department)->update([...$data, 'updated_at' => now()]);
            $this->audit->recordRow('organization.details.updated', 'departments', $department, (array) $before, $data, $user->id, $request->ip());
        });

        return back()->with('success', 'حُفظ الوصف والمسؤوليات.');
    }

    /**
     * Appoints (or clears) the head of a department or executive office. As before, the person must already hold the
     * «رئيس قسم» role in that department (the executive director's role counts for an executive office). A unit has
     * a coordinator named by its department head instead.
     */
    public function updateHead(Request $request, int $department): RedirectResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canManageOrganization(), 403, 'تعيين رؤساء الأقسام لمدير النظام ورئيس الموارد البشرية.');
        $data = $request->validate(['head_user_id' => ['nullable', 'integer']], [], ['head_user_id' => 'الرئيس']);
        $head = isset($data['head_user_id']) ? (int) $data['head_user_id'] : null;
        DB::transaction(function () use ($request, $department, $head, $user): void {
            $node = DB::table('departments')->where('id', $department)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($node, 404);
            if ($node->node_type === 'unit') {
                throw ValidationException::withMessages(['head_user_id' => 'للوحدة منسق يسمّيه رئيس القسم من «قسمي»، لا رئيس.']);
            }
            if ($head !== null && ! in_array($head, array_column($this->eligibleHeads()[(int) $node->id] ?? [], 'id'), true)) {
                throw ValidationException::withMessages(['head_user_id' => 'أسند لهذا المستخدم دور «رئيس قسم» في هذا القسم أولًا من «حسابات الموظفين والأدوار».']);
            }
            DB::table('departments')->where('id', $department)->update(['head_user_id' => $head, 'updated_at' => now()]);
            $this->audit->recordRow('organization.head.assigned', 'departments', $department, ['head_user_id' => $node->head_user_id], ['head_user_id' => $head], $user->id, $request->ip());
        });

        return back()->with('success', $head ? 'عُيّن الرئيس.' : 'أُلغي تعيين الرئيس.');
    }

    public function propose(Request $request): RedirectResponse
    {
        $user = $this->viewer($request);
        $id = $this->changes->propose($user, $request->all(), $request->ip());

        return redirect()->route('admin.organization.changes.show', $id)->with('success', 'رُفع المقترح إلى المدير التنفيذي. يُطبَّق على الهيكل عند اعتماده.');
    }

    public function suggest(Request $request): RedirectResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canManageOrganization(), 403);
        $added = $this->changes->suggestDefaults();

        return back()->with('success', $added ? "أُضيف {$added} اقتراح وحدة للمراجعة." : 'لا اقتراحات جديدة؛ كل الوحدات المقترحة موجودة أو قيد المراجعة.');
    }

    public function submitSuggestion(Request $request, int $change): RedirectResponse
    {
        $user = $this->viewer($request);
        $data = $request->validate(['rationale' => ['nullable', 'string', 'max:4000']]);
        $this->changes->submitSuggestion($user, $change, (string) ($data['rationale'] ?? ''), $request->ip());

        return back()->with('success', 'رُفع الاقتراح إلى المدير التنفيذي.');
    }

    public function dismissSuggestion(Request $request, int $change): RedirectResponse
    {
        $this->changes->dismissSuggestion($this->viewer($request), $change, $request->ip());

        return back()->with('success', 'استُبعد الاقتراح.');
    }

    public function withdraw(Request $request, int $change): RedirectResponse
    {
        $user = $this->viewer($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'سبب السحب']);
        $this->changes->withdraw($user, $change, $data['reason'], $request->ip());

        return back()->with('success', 'سُحب المقترح.');
    }

    public function showChange(Request $request, int $change, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $row = DB::table('department_structure_changes')->where('id', $change)->first();
        abort_unless($row, 404);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $participant = $instance && $visibility->accessLevel($user, (int) $instance->id) !== null;
        abort_unless($user->is_active && ($participant || app(NavigationAccessRules::class)->allows($user, 'org_structure')), 403);

        return Inertia::render('Admin/Organization/Change', [
            'change' => $this->changeRows($change)[0],
            'blockers' => $row->change_type === 'retire' && $row->department_id && $row->status !== 'applied' ? $this->structure->retireBlockers((int) $row->department_id) : [],
            'progress' => $instance ? $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision,
                'status' => $instance->status, 'actions' => $engine->availableActions($instance, $user)] : null,
            'canWithdraw' => in_array($row->status, ['in_review', 'returned'], true) && (int) $row->proposed_by === (int) $user->id,
        ]);
    }

    public function updatePolicy(Request $request, int $policy): RedirectResponse
    {
        $user = $this->viewer($request);
        abort_unless($this->delegations->isExecutive($user), 403, 'سياسة التفويض قرار للمدير التنفيذي؛ مدير النظام يطّلع عليها فقط.');
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'max_days' => ['required', 'integer', 'between:1,365'],
            'requires_executive' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $policy, $data, $user): void {
            $before = DB::table('delegable_permissions')->where('id', $policy)->lockForUpdate()->first();
            abort_unless($before, 404);
            DB::table('delegable_permissions')->where('id', $policy)->update([...$data, 'updated_by' => $user->id, 'updated_at' => now()]);
            $this->audit->recordRow('organization.delegation_policy.updated', 'delegable_permissions', $policy,
                array_intersect_key((array) $before, $data), $data, $user->id, $request->ip());
        });
        ScopedDelegations::flush();

        return back()->with('success', 'حُدّثت سياسة التفويض.');
    }

    /**
     * Active users who may head each department: holders of «رئيس قسم» in it, or of the executive director's role
     * for an executive office.
     *
     * @return array<int, list<array{id: int, name: string}>>
     */
    private function eligibleHeads(): array
    {
        $rows = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('departments as d', 'd.id', '=', 'a.department_id')->where('u.is_active', true)->whereNull('d.deleted_at')
            ->where(fn ($q) => $q->where('r.code', 'department_head')->orWhere(fn ($o) => $o->where('r.code', 'executive_director')->where('d.node_type', 'office')))
            ->where(fn ($q) => $q->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', today()->toDateString()))
            ->orderBy('u.name')->get(['a.department_id', 'u.id', 'u.name']);
        $heads = [];
        foreach ($rows as $row) {
            $heads[(int) $row->department_id][(int) $row->id] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return array_map('array_values', $heads);
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && app(NavigationAccessRules::class)->allows($user, 'org_structure'), 403, 'الهيكل التنظيمي لمدير النظام ورئيس الموارد البشرية والمدير التنفيذي ورؤساء الأقسام.');

        return $user;
    }

    /** @return list<array<string, mixed>> */
    private function changeRows(?int $id = null): array
    {
        return DB::table('department_structure_changes as c')->leftJoin('departments as d', 'd.id', '=', 'c.department_id')->leftJoin('departments as p', 'p.id', '=', 'c.parent_id')
            ->leftJoin('users as a', 'a.id', '=', 'c.proposed_by')->leftJoin('users as b', 'b.id', '=', 'c.decided_by')->leftJoin('workflow_instances as w', 'w.id', '=', 'c.workflow_instance_id')
            ->when($id !== null, fn ($q) => $q->where('c.id', $id), fn ($q) => $q->whereNot('c.status', 'withdrawn')->limit(200))
            ->orderByRaw("CASE c.status WHEN 'in_review' THEN 0 WHEN 'returned' THEN 1 WHEN 'suggested' THEN 2 ELSE 3 END")->orderByDesc('c.id')
            ->get(['c.*', 'd.name as node', 'd.code as node_code', 'p.name as parent', 'a.name as proposer', 'b.name as decider', 'w.reference_no'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'type' => $row->change_type, 'type_label' => OrganizationStructure::CHANGE_LABELS[$row->change_type] ?? $row->change_type,
                'status' => $row->status, 'status_label' => OrganizationStructure::STATUS_LABELS[$row->status] ?? $row->status, 'node' => $row->node, 'node_code' => $row->node_code,
                'parent' => $row->parent, 'proposed_name' => $row->proposed_name, 'rationale' => $row->rationale, 'authority' => $row->authority, 'proposer' => $row->proposer,
                'decider' => $row->decider, 'decided_at' => $row->decided_at, 'applied_at' => $row->applied_at, 'created_at' => $row->created_at, 'reference_no' => $row->reference_no,
                'details' => json_decode((string) $row->details, true) ?: []])->all();
    }
}
