<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Services\Hr\EmployeeAccess;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what in «الحضور والانصراف». Roles come from the existing RBAC; nothing here is granted by a URL.
 *
 * - HR operations (system administrator or the HR head): roster templates, assignments, exemptions, manual punches.
 * - Roster approvals (assignments, exceptions, exemptions, emergency changes, schedule changes): the executive
 *   director only (2026-10-22 centralization; the technical administrator takes no business decision).
 * - Manual punch-direction corrections (owner decision 8, 2026-11-27): the HR head; the executive director when the
 *   HR head asked for it or the punch is the HR head's own. Never the requester or the employee concerned.
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
        return $user->is_active && $this->isExecutive($user);
    }

    /** The appointed HR head: structure head of HR holding the «رئيس قسم» role there (as User::isHrDepartmentHead). */
    public function hrHeadId(): ?int
    {
        $id = DB::table('departments as d')->join('users as u', 'u.id', '=', 'd.head_user_id')
            ->join('department_user_roles as a', fn ($join) => $join->on('a.user_id', '=', 'u.id')->on('a.department_id', '=', 'd.id'))
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('d.code', 'HR')->where('d.is_active', true)->where('u.is_active', true)->where('r.code', 'department_head')
            ->whereRaw(EffectiveAssignments::sql('a'))->value('u.id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<int> active holders of an effective executive_director assignment */
    public function executiveIds(): array
    {
        return DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', 'executive_director')->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))
            ->distinct()->pluck('a.user_id')->map(fn ($id): int => (int) $id)->all();
    }

    /** Whether the user decides manual punch corrections at all (the rows are narrowed by correctionsToDecide). */
    public function mayDecideCorrections(User $user): bool
    {
        return $user->is_active && ($user->isHrDepartmentHead() || $this->isExecutive($user));
    }

    /**
     * The manual punch-direction corrections in the given status this user decides (owner decision 8): the HR head
     * decides; the executive director decides those the HR head asked for or that concern the HR head's own punch
     * (and all of them while no HR head is appointed, as the leave chain). Nobody decides a correction they asked for
     * or one of their own punches; the system administrator decides none. Null when the user decides none.
     * The query is on «attendance_punch_corrections as c» joined to «attendance_punches as p».
     */
    public function correctionsToDecide(int $userId, string $status = 'pending'): ?Builder
    {
        $hr = $this->hrHeadId();
        if ($hr !== $userId && ! in_array($userId, $this->executiveIds(), true)) {
            return null;
        }
        $ownPunch = fn ($query, int $id) => $query->select(DB::raw(1))->from('employees as ce')->whereColumn('ce.id', 'p.employee_id')->where('ce.user_id', $id);

        return DB::table('attendance_punch_corrections as c')->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')
            ->where('c.status', $status)->whereNotNull('p.employee_id')
            ->where(fn ($query) => $query->whereNull('c.requested_by')->orWhere('c.requested_by', '<>', $userId))
            ->whereNotExists(fn ($query) => $ownPunch($query, $userId))
            ->when($hr !== null && $hr !== $userId, fn ($query) => $query->where(fn ($conflict) => $conflict->where('c.requested_by', $hr)
                ->orWhereExists(fn ($own) => $ownPunch($own, $hr))));
    }

    /** Whether somebody may decide this correction, so a request nobody can decide is never left pending. */
    public function correctionHasDecider(int $correctionId): bool
    {
        foreach (array_unique(array_filter([$this->hrHeadId(), ...$this->executiveIds()])) as $userId) {
            if ($this->correctionsToDecide($userId)?->where('c.id', $correctionId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Who confirms the instant of a device punch held for review because its wall time was repeated or skipped by a
     * daylight-saving change: the HR head, as for punch-direction corrections (owner decision 8); the executive
     * director for the HR head's own punch, or while no HR head is appointed. Never the employee concerned, never
     * the system administrator.
     */
    public function mayResolvePunchTime(User $user, ?int $employeeId): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $owner = $employeeId === null ? null : DB::table('employees')->where('id', $employeeId)->value('user_id');
        if ($owner !== null && (int) $owner === (int) $user->id) {
            return false;
        }
        $hr = $this->hrHeadId();
        if ($hr !== null && $hr === (int) $user->id) {
            return true;
        }

        return in_array((int) $user->id, $this->executiveIds(), true) && ($hr === null || ($owner !== null && (int) $owner === $hr));
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
