<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Services\Workflow\AssigneeResolver;
use App\Services\WorkflowVisibilityService;
use Illuminate\Support\Facades\DB;

/**
 * Setup gaps that stop real work, read from live data: people, departments,
 * roles that published workflows depend on, and leave balances.
 */
final class SystemReadiness
{
    private const NAME_LIMIT = 8;

    public function __construct(private readonly AssigneeResolver $assignees) {}

    /**
     * @return list<array{code: string, title: string, ok: bool, items: list<string>, url: ?string, action: ?string}>
     */
    public function checks(): array
    {
        return [
            $this->check('workflow_roles', 'كل مرحلة في المسارات المنشورة لها من ينفذها', $this->workflowGaps(),
                'admin.employees.index', 'إسناد الأدوار'),
            $this->check('stalled_requests', 'لا توجد معاملات مفتوحة بلا مسؤول حالي', $this->requestsWithoutHolder(),
                'admin.employees.index', 'إسناد الأدوار'),
            $this->check('executive', 'يوجد مدير تنفيذي واحد نشط', $this->executiveGaps(),
                'admin.employees.index', 'إسناد الأدوار'),
            $this->check('department_heads', 'لكل قسم نشط رئيس', $this->departmentsWithoutHead(),
                'admin.departments.vue', 'إدارة الأقسام'),
            $this->check('inactive_department_staff', 'لا يوجد موظفون نشطون في أقسام موقوفة', $this->staffInInactiveDepartments(),
                'admin.departments.vue', 'إدارة الأقسام'),
            $this->check('employee_profiles', 'لكل مستخدم ملف موظف مكتمل', $this->usersWithoutProfile(),
                'admin.employee-profiles.index', 'ملفات الموظفين'),
            $this->check('leave_balances', 'سُجّل الرصيد الافتتاحي للإجازات لهذا العام', $this->employeesWithoutOpeningBalance(),
                'leaves.balances', 'أرصدة الإجازات'),
        ];
    }

    /**
     * @param  list<string>  $gaps
     * @return array{code: string, title: string, ok: bool, items: list<string>, url: ?string, action: ?string}
     */
    private function check(string $code, string $title, array $gaps, string $routeName, string $action): array
    {
        $shown = array_slice($gaps, 0, self::NAME_LIMIT);
        if (count($gaps) > self::NAME_LIMIT) {
            $shown[] = 'و'.(count($gaps) - self::NAME_LIMIT).' غيرها';
        }

        return [
            'code' => $code,
            'title' => $title,
            'ok' => $gaps === [],
            'items' => $shown,
            'url' => route($routeName, [], false),
            'action' => $action,
        ];
    }

    /**
     * @return list<string>
     */
    private function workflowGaps(): array
    {
        $gaps = [];
        $steps = DB::table('workflow_steps as s')
            ->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.status', 'published')->where('d.is_active', true)
            ->where('s.step_type', '<>', 'end')
            ->orderBy('d.name')->orderBy('s.sort_order')
            ->get(['s.*', 'd.name as workflow_name']);

        $roles = DB::table('roles')->pluck('name', 'code')->all();
        $roleCodes = array_keys($roles);
        $eligibleHolders = [];
        foreach ($steps as $step) {
            foreach ($this->assignees->problems($step, $roleCodes) as $problem) {
                $gaps[] = $step->workflow_name.': '.$problem;
            }
            $assignmentKey = json_encode([$step->step_type, $step->assignment_type, $step->assigned_role_code, $step->assignment_config], JSON_THROW_ON_ERROR);
            if (! ($eligibleHolders[$assignmentKey] ??= $this->assignees->hasEligibleHolder($step))) {
                $gaps[] = $step->workflow_name.': لا يوجد من ينفذ مرحلة «'.$step->name.'»'
                    .($step->assigned_role_code ? ' (دور «'.(($roles[$step->assigned_role_code] ?? null) ?: $step->assigned_role_code).'» بلا حامل ولا بديل)' : '')
                    .'؛ ستتوقف المعاملات عند المرحلة السابقة لها.';
            }
        }

        return array_values(array_unique($gaps));
    }

    /**
     * Open requests nobody can act on now (their holder was deactivated or lost the role): metadata only.
     *
     * @return list<string>
     */
    private function requestsWithoutHolder(): array
    {
        return DB::table('workflow_instances as i')
            ->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.status', 'in_progress')
            ->whereNotIn('i.workflow_version_id', WorkflowVisibilityService::confidentialVersions())
            ->whereNotExists(fn ($query) => $query->from('workflow_participants as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->whereColumn('p.workflow_instance_id', 'i.id')
                ->where('p.participation_type', 'assignee')->where('p.is_active', true)->where('u.is_active', true))
            ->orderBy('i.id')
            ->get(['i.reference_no', 's.name'])
            ->map(fn (object $row): string => $row->reference_no.' — متوقفة عند «'.$row->name.'»')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function executiveGaps(): array
    {
        $holders = $this->activeHolders('executive_director');

        return match (true) {
            $holders === 0 => ['لا يوجد من يحمل دور المدير التنفيذي؛ إجازات رؤساء الأقسام لن تجد من يعتمدها.'],
            $holders > 1 => ['يحمل دور المدير التنفيذي أكثر من شخص؛ اعتماد إجازات رؤساء الأقسام يتطلب شخصًا واحدًا.'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function departmentsWithoutHead(): array
    {
        return DB::table('departments as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.head_user_id')
            ->where('d.is_active', true)->whereNull('d.deleted_at')
            ->where(fn ($query) => $query->whereNull('d.head_user_id')->orWhere('u.is_active', false))
            ->orderBy('d.name')
            ->pluck('d.name')->all();
    }

    /**
     * @return list<string>
     */
    private function staffInInactiveDepartments(): array
    {
        return DB::table('users as u')
            ->join('departments as d', 'd.id', '=', 'u.department_id')
            ->where('u.is_active', true)
            ->where('d.is_active', false)
            ->orderBy('u.name')
            ->get(['u.name', 'd.name as department'])
            ->map(fn (object $row): string => $row->name.' — '.$row->department)
            ->all();
    }

    /**
     * Active staff (anyone holding a role other than system administration) without a completed employee file.
     *
     * @return list<string>
     */
    private function usersWithoutProfile(): array
    {
        return DB::table('users as u')
            ->leftJoin('employees as e', fn ($join) => $join->on('e.user_id', '=', 'u.id')->whereNull('e.deleted_at'))
            ->where('u.is_active', true)
            ->whereExists(fn ($query) => $query->from('department_user_roles as a')
                ->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('a.user_id', 'u.id')
                ->where('r.code', '<>', 'system_admin'))
            ->where(fn ($query) => $query->whereNull('e.id')->orWhere('e.onboarding_status', '<>', 'completed'))
            ->orderBy('u.name')
            ->get(['u.name', 'e.id as employee_id'])
            ->map(fn (object $row): string => $row->name.($row->employee_id ? ' (الملف غير مكتمل)' : ' (لا يوجد ملف)'))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function employeesWithoutOpeningBalance(): array
    {
        $year = (int) now()->year;
        $leaveTypeIds = DB::table('master_data_values as v')
            ->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'leave_types')->where('t.is_active', true)->where('v.is_active', true)
            ->where('v.code', '<>', 'compensatory_leave')
            ->pluck('v.id');
        if ($leaveTypeIds->isEmpty()) {
            return [];
        }

        return DB::table('employees as e')
            ->whereNull('e.deleted_at')
            ->where('e.onboarding_status', 'completed')
            ->where('e.employment_status', 'active')
            ->whereNotExists(fn ($query) => $query->from('employee_leave_opening_balances as b')
                ->whereColumn('b.employee_id', 'e.id')
                ->where('b.balance_year', $year)
                ->whereIn('b.leave_type_value_id', $leaveTypeIds))
            ->orderBy('e.first_name_ar')
            ->get(['e.first_name_ar', 'e.family_name_ar'])
            ->map(fn (object $row): string => trim($row->first_name_ar.' '.$row->family_name_ar))
            ->all();
    }

    private function activeHolders(string $roleCode): int
    {
        return DB::table('department_user_roles as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', $roleCode)->where('u.is_active', true)
            ->distinct()->count('u.id');
    }
}
