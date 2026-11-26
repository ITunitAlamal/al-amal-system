<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who sees which part of an employee file. A section the viewer may not see is never sent to the browser.
 *
 * - HR management (system administrator or the appointed HR head) manages profiles, imports and documents.
 * - Pay and full bank accounts need a confidential permission held through a role or the HR headship; the
 *   system administrator's technical role does not grant them (pay data is masked for it).
 * - The executive director sees profiles and pay (decides pay changes), not identity documents.
 * - A department head sees the job, qualifications, work contact, leave, attendance and ordinary documents of their own
 *   department's staff only.
 * - Employees see their own file; pay only when the administration enables it, and the bank account masked.
 */
final class EmployeeAccess
{
    public const SECTIONS = ['personal', 'identity', 'contact', 'address', 'job', 'qualifications', 'pay', 'bank', 'bank_full', 'leave', 'attendance', 'photo', 'documents', 'history', 'provenance'];

    public function canManage(User $user): bool
    {
        return $user->is_active && $user->canManageEmployees();
    }

    public function canImport(User $user): bool
    {
        return $this->canManage($user);
    }

    public function canViewPay(User $user): bool
    {
        return $user->holdsConfidentialPermission('hr.employees.pay.view');
    }

    public function canViewBank(User $user): bool
    {
        return $user->holdsConfidentialPermission('hr.employees.bank.view');
    }

    public function canRequestPayChange(User $user): bool
    {
        return $user->holdsConfidentialPermission('hr.employees.pay.request');
    }

    /** Verifying documents and approving self-uploaded photos are HR decisions, not technical tasks. */
    public function canVerify(User $user): bool
    {
        return $user->is_active && $user->isHrDepartmentHead();
    }

    public function isExecutive(User $user): bool
    {
        return $user->is_active && $user->roleAssignments()->whereHas('role', fn ($query) => $query->where('code', 'executive_director'))->exists();
    }

    public function isSelf(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && (int) $employee->user_id === (int) $user->id;
    }

    /** The appointed head of the employee's current department (structure head with the head role there). */
    public function leadsDepartmentOf(User $user, Employee $employee): bool
    {
        if (! $user->is_active || ! $employee->department_id) {
            return false;
        }

        return DB::table('departments as d')
            ->join('department_user_roles as a', fn ($join) => $join->on('a.department_id', '=', 'd.id')->where('a.user_id', $user->id))
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('d.id', $employee->department_id)->where('d.is_active', true)->where('d.head_user_id', $user->id)
            ->where('r.code', 'department_head')->exists();
    }

    public function canView(User $user, Employee $employee): bool
    {
        return $user->is_active && ($this->canManage($user) || $this->isExecutive($user) || $this->isSelf($user, $employee) || $this->leadsDepartmentOf($user, $employee));
    }

    /**
     * @return array<string, bool>
     */
    public function sections(User $user, Employee $employee): array
    {
        $manage = $this->canManage($user);
        $self = $this->isSelf($user, $employee);
        $executive = $this->isExecutive($user);
        $head = $this->leadsDepartmentOf($user, $employee);
        $any = $manage || $self || $executive || $head;
        $pay = $this->canViewPay($user) || ($self && (bool) DB::table('hr_employee_file_settings')->where('id', 1)->value('self_view_pay'));

        return [
            'personal' => $manage || $self || $executive,
            'identity' => $manage || $self,
            'contact' => $any,
            'address' => $manage || $self,
            'job' => $any,
            'qualifications' => $any,
            'pay' => $any && $pay,
            'bank' => $manage || $self || $this->canViewBank($user),
            'bank_full' => $this->canViewBank($user),
            'leave' => $any,
            'attendance' => $any,
            'photo' => $any,
            'documents' => $any,
            'history' => $manage,
            'provenance' => $manage,
        ];
    }

    /**
     * Document classifications the viewer may open for this employee.
     *
     * @return list<string>
     */
    public function documentClasses(User $user, Employee $employee): array
    {
        if ($this->canManage($user) || $this->isSelf($user, $employee)) {
            return ['normal', 'confidential', 'identity'];
        }
        if ($this->isExecutive($user)) {
            return ['normal', 'confidential'];
        }

        return $this->leadsDepartmentOf($user, $employee) ? ['normal'] : [];
    }

    /** HR uploads any document; an employee may add ordinary documents (certificates) to their own file for HR verification. */
    public function canUploadDocument(User $user, Employee $employee, string $classification): bool
    {
        return $this->canManage($user) || ($this->isSelf($user, $employee) && $classification === 'normal');
    }

    public function canUploadPhoto(User $user, Employee $employee): bool
    {
        if ($this->canManage($user)) {
            return true;
        }

        return $this->isSelf($user, $employee) && DB::table('hr_employee_file_settings')->where('id', 1)->value('self_photo_upload') === 'review';
    }

    public static function maskAccount(?string $last4): ?string
    {
        return $last4 === null || $last4 === '' ? null : '••••'.$last4;
    }
}
