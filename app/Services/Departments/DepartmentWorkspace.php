<?php

namespace App\Services\Departments;

use App\Models\User;
use App\Services\Kpi\KpiScope;
use App\Services\WorkflowVisibilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «قسمي»: what a department head needs about the departments they head. Access comes from the
 * organizational assignment (departments.head_user_id) together with the department_head role permissions
 * department.records.view and department.requests.review held in that same department; sub-units of a headed
 * department are included. The system administrator sees every active department. Nothing confidential is
 * returned: no identity numbers, phones, salaries, family or orphan records.
 */
final class DepartmentWorkspace
{
    public const RECORDS = 'department.records.view';

    public const REQUESTS = 'department.requests.review';

    private const LEAVE_STATUS = ['submitted' => 'بانتظار المراجعة', 'manager_approved' => 'بانتظار القرار النهائي', 'pending_registration' => 'معتمدة بانتظار الإبلاغ والتسجيل', 'approved' => 'معتمدة ومسجلة'];

    public function __construct(private readonly WorkflowVisibilityService $visibility) {}

    /**
     * Departments the user may open in «قسمي», with their active sub-units.
     *
     * @return list<int>
     */
    public function departments(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        $tree = $this->tree();
        if ($user->isSystemAdmin()) {
            return array_keys($tree);
        }
        $roots = DB::table('departments')->where('head_user_id', $user->id)->where('is_active', true)->whereNull('deleted_at')
            ->pluck('id')->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $user->hasPermission(self::RECORDS, $id) && $user->hasPermission(self::REQUESTS, $id))
            ->values()->all();

        return $this->withDescendants($roots, $tree);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, ?int $departmentId): array
    {
        $allowed = $this->departments($user);
        abort_if($allowed === [], 403, '«قسمي» متاح لرئيس القسم المعيّن في الهيكل التنظيمي ويحمل صلاحيات رئيس القسم فيه.');
        abort_if($departmentId !== null && ! in_array($departmentId, $allowed, true), 403, 'القسم المختار خارج الأقسام التي ترأسها.');
        $scope = $departmentId === null ? $allowed : array_values(array_intersect($this->withDescendants([$departmentId], $this->tree()), $allowed));
        $names = DB::table('departments')->whereIn('id', $allowed)->pluck('name', 'id');
        $today = now()->toDateString();

        $staff = $this->staff($scope);
        $leaves = $this->leaves($user, $scope, $today);
        $requests = $this->requests($user, $scope);
        $received = $this->received($user, $scope);
        $kpi = KpiScope::resolve($user);

        return [
            'departments' => collect($allowed)->map(fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? '#'.$id])->sortBy('name')->values(),
            'selected' => $departmentId,
            'scopeNames' => collect($scope)->map(fn (int $id): string => $names[$id] ?? '#'.$id)->sort()->values(),
            'counts' => [
                'staff' => $staff->count(),
                'active_staff' => $staff->where('active', true)->count(),
                'pending_leaves' => $leaves->where('pending', true)->count(),
                'on_leave_today' => $leaves->where('today', true)->count(),
                'open_requests' => $requests->count(),
                'overdue_requests' => $requests->where('overdue', true)->count(),
                'received' => $received->count(),
                'received_overdue' => $received->where('overdue', true)->count(),
            ],
            'staff' => $staff->values(),
            'leaves' => $leaves->values(),
            'requests' => $requests->values(),
            'received' => $received->values(),
            'kpiUrl' => $kpi && ($departmentId === null || $kpi->covers($departmentId))
                ? route('kpi.index', $departmentId ? ['department_id' => $departmentId] : [], false) : null,
        ];
    }

    /**
     * @param  list<int>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function staff(array $scope): Collection
    {
        $roles = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->whereIn('a.user_id', DB::table('users')->whereIn('department_id', $scope)->select('id'))
            ->get(['a.user_id', 'r.name'])->groupBy('user_id');
        $onLeave = DB::table('employee_leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->where('l.status', 'approved')->where('l.start_date', '<=', now()->toDateString())->where('l.end_date', '>=', now()->toDateString())
            ->pluck('e.user_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $accounts = DB::table('users as u')
            ->leftJoin('employees as e', fn ($join) => $join->on('e.user_id', '=', 'u.id')->whereNull('e.deleted_at'))
            ->leftJoin('job_titles as t', 't.id', '=', 'e.job_title_id')
            ->leftJoin('departments as d', 'd.id', '=', 'u.department_id')
            ->whereIn('u.department_id', $scope)->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.employee_number', 'u.is_active', 'd.name as department', 'e.onboarding_status', 't.name_ar as job_title', 'e.id as profile_id'])
            ->map(fn (object $row): array => [
                'key' => 'u'.$row->id, 'profile_id' => $row->profile_id ? (int) $row->profile_id : null,
                'name' => $row->name, 'employee_number' => $row->employee_number, 'department' => $row->department,
                'job_title' => $row->job_title, 'roles' => ($roles[$row->id] ?? collect())->pluck('name')->unique()->values()->all(),
                'active' => (bool) $row->is_active,
                'account' => $row->is_active ? 'نشط' : ($row->onboarding_status === 'pending_activation' ? 'بانتظار التفعيل' : 'موقوف'),
                'profile' => $row->onboarding_status === null ? 'لا يوجد ملف موظف' : ($row->onboarding_status === 'completed' ? 'مكتمل' : 'غير مكتمل'),
                'on_leave_today' => in_array((int) $row->id, $onLeave, true),
            ]);
        $withoutAccount = DB::table('employees as e')->leftJoin('job_titles as t', 't.id', '=', 'e.job_title_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->whereNull('e.deleted_at')->whereNull('e.user_id')->whereIn('e.department_id', $scope)->orderBy('e.first_name_ar')
            ->get(['e.id', 'e.employee_number', 'e.first_name_ar', 'e.family_name_ar', 'e.full_name_ar', 'e.job_title_source', 'e.employment_status', 'e.onboarding_status',
                'd.name as department', 't.name_ar as job_title'])
            ->map(fn (object $row): array => [
                'key' => 'e'.$row->id, 'profile_id' => (int) $row->id, 'name' => $row->full_name_ar ?: trim($row->first_name_ar.' '.$row->family_name_ar),
                'employee_number' => $row->employee_number,
                'department' => $row->department, 'job_title' => $row->job_title ?? $row->job_title_source, 'roles' => [], 'active' => $row->employment_status === 'active',
                'account' => 'بلا حساب دخول', 'profile' => $row->onboarding_status === 'completed' ? 'مكتمل' : 'غير مكتمل', 'on_leave_today' => false,
            ]);

        return $accounts->concat($withoutAccount);
    }

    /**
     * Requests not yet decided, and approved leave running now or starting within 30 days.
     *
     * @param  list<int>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function leaves(User $viewer, array $scope, string $today): Collection
    {
        return DB::table('employee_leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->leftJoin('workflow_instances as i', 'i.id', '=', 'l.workflow_instance_id')
            ->whereIn('e.department_id', $scope)
            ->where(fn ($q) => $q->whereIn('l.status', ['submitted', 'manager_approved', 'pending_registration'])
                ->orWhere(fn ($approved) => $approved->where('l.status', 'approved')->where('l.end_date', '>=', $today)
                    ->where('l.start_date', '<=', now()->addDays(30)->toDateString())))
            ->orderBy('l.start_date')->limit(200)
            ->get(['l.id', 'l.status', 'l.start_date', 'l.end_date', 'l.requested_days', 'e.first_name_ar', 'e.family_name_ar', 't.name_ar as type', 'i.id as instance_id', 'i.public_id'])
            ->map(fn (object $row): array => [
                'key' => $row->id, 'employee' => trim($row->first_name_ar.' '.$row->family_name_ar), 'type' => $row->type,
                'from' => $row->start_date, 'to' => $row->end_date, 'days' => $row->requested_days !== null ? (float) $row->requested_days : null,
                'status' => self::LEAVE_STATUS[$row->status] ?? $row->status, 'pending' => $row->status !== 'approved',
                'today' => $row->status === 'approved' && $row->start_date <= $today && $row->end_date >= $today,
                'url' => $this->link($viewer, $row->instance_id, $row->public_id),
            ]);
    }

    /**
     * Open requests submitted from the departments, wherever they wait now.
     *
     * @param  list<int>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function requests(User $viewer, array $scope): Collection
    {
        return $this->openInstances()->whereIn('i.department_id', $scope)->get()
            ->map(fn (object $row): array => $this->instanceRow($viewer, $row));
    }

    /**
     * Open steps held right now by staff of the departments, for requests from any department.
     *
     * @param  list<int>  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function received(User $viewer, array $scope): Collection
    {
        return $this->openInstances()
            ->whereExists(fn ($holder) => $holder->from('workflow_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->whereColumn('p.workflow_instance_id', 'i.id')->where('p.participation_type', 'assignee')->where('p.is_active', true)
                ->whereIn('u.department_id', $scope))
            ->get()->map(fn (object $row): array => $this->instanceRow($viewer, $row));
    }

    private function openInstances(): Builder
    {
        return DB::table('workflow_instances as i')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as w', 'w.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->leftJoin('departments as d', 'd.id', '=', 'i.department_id')
            ->where('w.is_confidential', false) // complaints are seen only by the people on them, never by the head
            ->where('i.status', 'in_progress')->orderBy('i.started_at')->limit(200)
            ->select(['i.id', 'i.public_id', 'i.reference_no', 'i.started_at', 'i.due_at', 'w.name as workflow', 's.name as step', 'd.name as origin'])
            ->selectSub(DB::table('workflow_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->whereColumn('p.workflow_instance_id', 'i.id')->where('p.participation_type', 'assignee')->where('p.is_active', true)
                ->selectRaw("string_agg(u.name, '، ' ORDER BY u.name)"), 'holders');
    }

    /**
     * @return array<string, mixed>
     */
    private function instanceRow(User $viewer, object $row): array
    {
        return ['key' => $row->id, 'reference' => $row->reference_no, 'workflow' => $row->workflow, 'step' => $row->step, 'origin' => $row->origin,
            'holders' => $row->holders, 'started_at' => $row->started_at, 'due_at' => $row->due_at,
            'overdue' => $row->due_at !== null && $row->due_at < now()->format('Y-m-d H:i:s'),
            'url' => $this->link($viewer, $row->id, $row->public_id)];
    }

    private function link(User $viewer, mixed $instanceId, mixed $publicId): ?string
    {
        return $instanceId && $this->visibility->accessLevel($viewer, (int) $instanceId) !== null
            ? route('workflows.show', ['publicId' => $publicId], false) : null;
    }

    /** @return array<int, int|null> active department id => parent id */
    private function tree(): array
    {
        return DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->pluck('parent_id', 'id')
            ->mapWithKeys(fn ($parent, $id): array => [(int) $id => $parent === null ? null : (int) $parent])->all();
    }

    /**
     * @param  list<int>  $roots
     * @param  array<int, int|null>  $tree
     * @return list<int>
     */
    private function withDescendants(array $roots, array $tree): array
    {
        $found = array_values(array_intersect($roots, array_keys($tree)));
        do {
            $added = false;
            foreach ($tree as $id => $parent) {
                if ($parent !== null && in_array($parent, $found, true) && ! in_array($id, $found, true)) {
                    $found[] = $id;
                    $added = true;
                }
            }
        } while ($added);
        sort($found);

        return $found;
    }
}
