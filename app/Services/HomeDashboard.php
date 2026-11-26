<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Services\Configuration\MasterDataLookup;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Departments\DepartmentWorkspace;
use App\Services\Finance\FinanceApprovalQueue;
use App\Services\Finance\FinanceGuard;
use App\Services\Progress\TransactionTracker;
use App\Services\Workflow\WorkflowCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data for the personal home page ("مساحتي"): what to start, what waits for me,
 * where my requests are, my leave balances and my employment file.
 */
final class HomeDashboard
{
    private const LIST_LIMIT = 6;

    public function __construct(
        private readonly TransactionTracker $tracker,
        private readonly WorkflowCatalog $catalog,
        private readonly LeaveApproverResolver $leaveApprover,
        private readonly EmployeeLeaveBalanceService $leaveBalance,
        private readonly EmployeeLeaveEntitlementService $leaveEntitlement,
        private readonly NavigationAccessRules $accessRules,
        private readonly MasterDataLookup $masterData,
        private readonly SelfEvaluation $evaluation,
        private readonly FinanceApprovalQueue $financeQueue,
        private readonly FinanceGuard $financeGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $employee = $user->employeeProfile;
        $tasks = $this->tracker->tasksOf($user);
        $requests = $this->tracker->overview($user, self::LIST_LIMIT);

        return [
            'roles' => $this->roleNames($user),
            'departmentName' => $user->department_id ? DB::table('departments')->where('id', $user->department_id)->value('name') : null,
            'startOptions' => $this->startOptions($user),
            'stats' => [
                'tasks' => $tasks->count(),
                'overdue' => $tasks->where('overdue', true)->count(),
                'open' => $requests['open'],
                'completed' => $requests['completed'],
            ],
            'tasks' => $tasks->take(8)->values()->all(),
            'myRequests' => $requests['latest'],
            'leaveBalances' => $employee ? $this->leaveBalances($employee) : null,
            'leaveBasis' => $employee ? $this->leaveEntitlement->basis($employee) : null,
            'evaluation' => $this->evaluation->forUser($user),
            'team' => $this->teamPanel($user),
            'executive' => $this->leaveApprover->isExecutive((int) $user->id) ? $this->executivePanel() : null,
            'finance' => $this->financePanel($user),
            'profile' => $employee ? $this->profile($employee, $user) : null,
        ];
    }

    /**
     * For department heads: their departments as recorded in the organization (departments.head_user_id).
     *
     * @return array<string, mixed>|null
     */
    private function teamPanel(User $user): ?array
    {
        $departments = DB::table('departments')->where('head_user_id', $user->id)->where('is_active', true)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name']);
        if ($departments->isEmpty()) {
            return null;
        }
        $ids = $departments->pluck('id')->all();
        $today = now()->toDateString();

        return [
            'departments' => $departments->pluck('name')->all(),
            'pendingLeaves' => DB::table('employee_leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')
                ->whereIn('e.department_id', $ids)->where('l.status', 'submitted')->count(),
            'onLeaveToday' => DB::table('employee_leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')
                ->whereIn('e.department_id', $ids)->where('l.status', 'approved')
                ->where('l.start_date', '<=', $today)->where('l.end_date', '>=', $today)
                ->orderBy('e.first_name_ar')->limit(8)->get(['e.first_name_ar', 'e.family_name_ar', 'l.end_date'])
                ->map(fn (object $row): array => ['name' => trim($row->first_name_ar.' '.$row->family_name_ar), 'until' => $row->end_date])->all(),
            // Confidential requests (complaints) are not counted for the head.
            'inProgress' => DB::table('workflow_instances')->whereIn('department_id', $ids)->where('status', 'in_progress')->whereNotIn('workflow_version_id', WorkflowVisibilityService::confidentialVersions())->count(),
            'overdue' => DB::table('workflow_instances')->whereIn('department_id', $ids)->where('status', 'in_progress')->whereNotNull('sla_breached_at')->whereNotIn('workflow_version_id', WorkflowVisibilityService::confidentialVersions())->count(),
            'workspaceUrl' => app(DepartmentWorkspace::class)->departments($user) !== [] ? route('department.workspace', [], false) : null,
        ];
    }

    /**
     * For the executive director: organization-wide progress and decisions waiting at the top.
     *
     * @return array<string, mixed>
     */
    private function executivePanel(): array
    {
        $prorata = DB::table('leave_module_settings')->where('id', 1)->value('prorata_status');

        return [
            'inProgress' => DB::table('workflow_instances')->where('status', 'in_progress')->count(),
            'overdue' => DB::table('workflow_instances')->where('status', 'in_progress')->whereNotNull('sla_breached_at')->count(),
            'completedThisMonth' => DB::table('workflow_instances')->where('status', 'completed')->where('completed_at', '>=', now()->startOfMonth())->count(),
            'decisions' => array_values(array_filter([
                $prorata === 'proposed' ? ['label' => 'سياسة الاحتساب النسبي للإجازات بانتظار قرارك', 'url' => route('admin.leaves.settings', [], false)] : null,
            ])),
        ];
    }

    /**
     * Finance work for whoever holds finance permissions: approvals per their real permissions.
     *
     * @return array<string, mixed>|null
     */
    private function financePanel(User $user): ?array
    {
        if ($this->financeQueue->isApprover($user)) {
            $counts = $this->financeQueue->counts($user);

            return ['approver' => true, 'counts' => $counts, 'total' => array_sum($counts), 'url' => '/finance/approvals'];
        }
        if ($this->financeGuard->allows($user, 'finance.view')) {
            return ['approver' => false, 'counts' => [], 'total' => 0, 'url' => route('finance.index', [], false)];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function roleNames(User $user): array
    {
        return DB::table('department_user_roles as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $user->id)
            ->distinct()
            ->orderBy('r.name')
            ->pluck('r.name')
            ->all();
    }

    /**
     * Every request a user can make, on every user's home page (owner request 2026-10-23): the leave request is
     * always listed; when the employee file is not complete it shows why instead of opening.
     *
     * @return list<array{label: string, url: ?string, reason?: string}>
     */
    private function startOptions(User $user): array
    {
        $options = [$this->accessRules->allows($user, 'leave_requester')
            ? ['label' => 'طلب إجازة', 'url' => route('leaves.create', [], false)]
            : ['label' => 'طلب إجازة', 'url' => null, 'reason' => $this->accessRules->explain($user, 'leave_requester')]];
        foreach ($this->catalog->startableFor($user) as $definition) {
            $reason = $this->catalog->unavailableReason($user, $definition);
            $options[] = $reason === null
                ? ['label' => $definition->name, 'url' => route('requests.create', ['code' => $definition->code], false)]
                : ['label' => $definition->name, 'url' => null, 'reason' => $reason];
        }

        return $options;
    }

    /**
     * Balances for the current year, for leave types the employee is entitled to or has movement in.
     *
     * @return list<array<string, mixed>>
     */
    private function leaveBalances(Employee $employee): array
    {
        $year = (int) now()->year;
        $today = now()->toDateString();
        $types = DB::table('master_data_values as v')
            ->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'leave_types')->where('t.is_active', true)->where('v.is_active', true)
            ->orderBy('v.sort_order')->orderBy('v.name_ar')
            ->get(['v.id', 'v.name_ar']);

        $balances = [];
        foreach ($types as $type) {
            $summary = $this->leaveBalance->summary((int) $employee->id, (int) $type->id, $year);
            $entitlement = $this->leaveEntitlement->isCompensatoryType((int) $type->id)
                ? ['status' => 'compensatory', 'annual_days' => null]
                : $this->leaveEntitlement->explain($employee, (int) $type->id, $today);
            $hasActivity = $summary['opening_recorded'] || $summary['movements'] != 0.0 || $summary['reserved'] != 0.0;
            if (! $hasActivity && ! in_array($entitlement['status'], ['defined', 'below_first_tier'], true)) {
                continue;
            }
            $balances[] = [
                'type' => $type->name_ar,
                'annualDays' => $entitlement['annual_days'],
                'entitlement' => $entitlement,
                'openingRecorded' => $summary['opening_recorded'],
                'registered' => $summary['remaining'],
                'reserved' => $summary['reserved'],
                'available' => $summary['available'],
            ];
        }

        return $balances;
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Employee $employee, User $user): array
    {
        $managerId = $this->leaveApprover->approverFor((object) ['employee_id' => $employee->id, 'requested_by' => $user->id]);

        return [
            'employeeNumber' => $employee->employee_number,
            'jobTitle' => $employee->job_title_id ? DB::table('job_titles')->where('id', $employee->job_title_id)->value('name_ar') : null,
            'employmentTerm' => $this->masterData->label($employee->employment_term_value_id ? (int) $employee->employment_term_value_id : null),
            'workStartDate' => $employee->work_start_date ? Carbon::parse($employee->work_start_date)->toDateString() : null,
            'manager' => $managerId && $managerId !== (int) $user->id ? DB::table('users')->where('id', $managerId)->value('name') : null,
            'profileComplete' => $employee->onboarding_status === 'completed',
        ];
    }
}
