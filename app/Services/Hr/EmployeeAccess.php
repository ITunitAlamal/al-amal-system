<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\User;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Facades\DB;

/**
 * Who sees which part of an employee file. A section the viewer may not see is never sent to the browser.
 *
 * - HR management (system administrator or the appointed HR head) manages profiles, imports and documents.
 * - HR staff (a role holding hr.files.prepare in the HR department or one of its units) prepare new files and changes
 *   for the HR head's approval (EmployeeFileChanges) and attach ordinary and identity documents, which the HR head
 *   verifies. They see the file without pay, bank, confidential documents or account actions.
 * - Pay and full bank accounts need a confidential permission held through a role or the HR headship; the
 *   system administrator's technical role does not grant them (pay data is masked for it).
 * - The executive director sees profiles and pay (decides pay changes), not identity documents. A change to his own
 *   pay or bank record is decided by the Head of Accounts, who sees that request only.
 * - A department head sees the job, qualifications, work contact, leave, attendance and ordinary documents of their own
 *   department's staff only.
 * - Employees see their own file; pay only when the administration enables it, and the bank account masked.
 */
final class EmployeeAccess
{
    /** The permission of HR staff who prepare files and changes for the HR head's approval. */
    public const PREPARE_PERMISSION = 'hr.files.prepare';

    /** @var array<int, bool> */
    private array $preparers = [];

    public const SECTIONS = ['personal', 'identity', 'contact', 'address', 'job', 'qualifications', 'pay', 'bank', 'bank_full', 'leave', 'attendance', 'photo', 'documents', 'history', 'provenance'];

    public function canManage(User $user): bool
    {
        return $user->is_active && $user->canManageEmployees();
    }

    /**
     * An HR staff member who prepares files for review: an effective role holding hr.files.prepare in the HR
     * department, one of its units, or organisation-wide. HR management itself acts directly and never prepares.
     */
    public function canPrepare(User $user): bool
    {
        if (! $user->is_active || $this->canManage($user)) {
            return false;
        }

        return $this->preparers[(int) $user->id] ??= DB::table('department_user_roles as a')
            ->join('permission_role as pr', 'pr.role_id', '=', 'a.role_id')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('a.user_id', $user->id)->where('p.code', self::PREPARE_PERMISSION)->whereRaw(EffectiveAssignments::sql('a'))
            ->where(fn ($query) => $query->whereNull('a.department_id')->orWhereIn('a.department_id', HrServicesDashboard::departmentIds() ?: [0]))
            ->exists();
    }

    /** Opens the employee files list, the forms and the files: HR management or HR staff. */
    public function canOpenFiles(User $user): bool
    {
        return $this->canManage($user) || $this->canPrepare($user);
    }

    /** Approves or returns what HR staff prepared: the appointed HR head only (approved HR requirements §7.1). */
    public function canReviewChanges(User $user): bool
    {
        return $this->canVerify($user);
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
        return $user->is_active && $user->roleAssignments()->effective()->whereHas('role', fn ($query) => $query->where('code', 'executive_director'))->exists();
    }

    /** The Head of Accounts: the appointed head of FIN holding the «رئيس قسم» role there. */
    public function isAccountsHead(User $user): bool
    {
        return $user->is_active && $user->headsDepartment('FIN');
    }

    /** The Head of Accounts who decides the executive director's pay change today (isAccountsHead), or null when none is appointed. */
    public function accountsHeadId(): ?int
    {
        $headId = DB::table('departments')->where('code', 'FIN')->where('is_active', true)->whereNull('deleted_at')->value('head_user_id');
        $head = $headId === null ? null : User::find((int) $headId);

        return $head !== null && $this->isAccountsHead($head) ? (int) $head->id : null;
    }

    /** Whether the employee's own account holds an effective executive_director assignment (owner decision 1, 2026-11-27). */
    public function employeeIsExecutive(int $employeeId): bool
    {
        $userId = DB::table('employees')->where('id', $employeeId)->value('user_id');

        return $userId !== null && DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $userId)->where('r.code', 'executive_director')->whereRaw(EffectiveAssignments::sql('a'))->exists();
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
            ->where('r.code', 'department_head')->whereRaw(EffectiveAssignments::sql('a'))->exists();
    }

    public function canView(User $user, Employee $employee): bool
    {
        return $user->is_active && ($this->canOpenFiles($user) || $this->isExecutive($user) || $this->isSelf($user, $employee) || $this->leadsDepartmentOf($user, $employee));
    }

    /**
     * @return array<string, bool>
     */
    public function sections(User $user, Employee $employee): array
    {
        $manage = $this->canManage($user);
        // HR staff see what they prepare (everything but pay, bank and the confidential documents).
        $staff = $this->canPrepare($user);
        $self = $this->isSelf($user, $employee);
        $executive = $this->isExecutive($user);
        $head = $this->leadsDepartmentOf($user, $employee);
        $any = $manage || $staff || $self || $executive || $head;
        $pay = $this->canViewPay($user) || ($self && (bool) DB::table('hr_employee_file_settings')->where('id', 1)->value('self_view_pay'));

        return [
            'personal' => $manage || $staff || $self || $executive,
            'identity' => $manage || $staff || $self,
            'contact' => $any,
            'address' => $manage || $staff || $self,
            'job' => $any,
            'qualifications' => $any,
            'pay' => $any && $pay,
            'bank' => $manage || $self || $this->canViewBank($user),
            'bank_full' => $this->canViewBank($user),
            'leave' => $any,
            'attendance' => $any,
            'photo' => $any,
            'documents' => $any,
            'history' => $manage || $staff,
            'provenance' => $manage || $staff,
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
        if ($this->canPrepare($user)) {
            return ['normal', 'identity'];
        }

        return $this->leadsDepartmentOf($user, $employee) ? ['normal'] : [];
    }

    /**
     * HR uploads any document; HR staff ordinary and identity documents; an employee ordinary documents (certificates) to
     * their own file. Every upload waits for the HR head's verification (EmployeeFiles::verify).
     */
    public function canUploadDocument(User $user, Employee $employee, string $classification): bool
    {
        return $this->canManage($user)
            || ($this->canPrepare($user) && in_array($classification, ['normal', 'identity'], true))
            || ($this->isSelf($user, $employee) && $classification === 'normal');
    }

    public function canUploadPhoto(User $user, Employee $employee): bool
    {
        // HR staff upload a photo that, like an employee's own, appears after the HR head's review.
        if ($this->canManage($user) || $this->canPrepare($user)) {
            return true;
        }

        return $this->isSelf($user, $employee) && DB::table('hr_employee_file_settings')->where('id', 1)->value('self_photo_upload') === 'review';
    }

    public static function maskAccount(?string $last4): ?string
    {
        return $last4 === null || $last4 === '' ? null : '••••'.$last4;
    }
}
