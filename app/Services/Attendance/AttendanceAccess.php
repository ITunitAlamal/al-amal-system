<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Services\Hr\EmployeeAccess;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what in «الحضور والانصراف». Roles come from the existing RBAC; nothing here is granted by a URL.
 *
 * - HR operations (system administrator or the HR head): roster templates, assignments, exemptions, manual punches.
 * - Roster approvals (assignments, exceptions, exemptions): the executive director or the system administrator (unchanged).
 * - HR business decisions (registering a permission on an employee's behalf, revoking an approved permission,
 *   reversing an approved swap): the HR head or the executive director — not the technical administrator.
 * - The administrative-actions register is confidential: «hr.actions.*» held through a role or the HR headship.
 *   The system administrator's technical role does not grant it.
 * - Employees act on their own file only; a department head sees their own department's staff.
 */
final class AttendanceAccess
{
    public function __construct(private EmployeeAccess $employees, private AttendanceSettings $settings) {}

    public function isExecutive(User $user): bool
    {
        return $this->employees->isExecutive($user);
    }

    public function isHrOperator(User $user): bool
    {
        return $user->is_active && ($user->isSystemAdmin() || $user->isHrDepartmentHead());
    }

    public function canViewLedger(User $user): bool
    {
        return $this->isHrOperator($user) || $this->isExecutive($user);
    }

    public function canApproveRoster(User $user): bool
    {
        return $user->is_active && ($user->isSystemAdmin() || $this->isExecutive($user));
    }

    public function canDecideHr(User $user): bool
    {
        return $user->is_active && ($user->isHrDepartmentHead() || $this->isExecutive($user));
    }

    public function canViewHrActions(User $user): bool
    {
        return $user->holdsConfidentialPermission('hr.actions.view');
    }

    public function canManageHrActions(User $user): bool
    {
        return $user->holdsConfidentialPermission('hr.actions.manage');
    }

    public function canDecideHrActions(User $user): bool
    {
        return $user->holdsConfidentialPermission('hr.actions.decide');
    }

    /** The employee file linked to this account, if any. */
    public function ownEmployee(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->whereNull('deleted_at')->first();
    }

    /** HR, the executive director, the employee themself, or the head of their department. */
    public function canViewEmployee(User $user, Employee $employee): bool
    {
        return $this->employees->canView($user, $employee) && ($this->employees->sections($user, $employee)['attendance'] ?? false);
    }

    /**
     * Colleagues an employee may propose a swap with: active staff, of the same department unless HR disabled that rule.
     *
     * @return list<array{id: int, name: string, number: ?string}>
     */
    public function swapCandidates(Employee $employee): array
    {
        $sameDepartment = $this->settings->get('swap_same_department');
        if ($sameDepartment && ! $employee->department_id) {
            return [];
        }

        return DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active')
            ->when($sameDepartment, fn ($query) => $query->where('department_id', $employee->department_id))->where('id', '<>', $employee->id)
            ->orderBy('first_name_ar')->get(['id', 'employee_number', 'first_name_ar', 'father_name_ar', 'family_name_ar'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => AttendanceLedger::fullName($row), 'number' => $row->employee_number])->all();
    }
}
