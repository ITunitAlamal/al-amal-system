<?php

namespace App\Models;

use App\Services\Organization\ScopedDelegations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    private ?bool $systemAdminSnapshot = null;

    /**
     * Reuse this check only while rendering a read-only menu, never across operations or requests.
     *
     * @template T
     *
     * @param  callable(): T  $render
     * @return T
     */
    public function withSystemAdminSnapshot(callable $render): mixed
    {
        $previous = $this->systemAdminSnapshot;
        $this->systemAdminSnapshot = $this->isSystemAdmin();
        try {
            return $render();
        } finally {
            $this->systemAdminSnapshot = $previous;
        }
    }

    /**
     * Working permissions the appointed head of a department holds through the organizational structure, with
     * no separate role. Owner decision (2026-10-23): the assets department is the buildings and rentals
     * department, so its head views and prepares the rentals file. Decisions (rentals.decide) stay with the
     * executive director's role and are never granted this way. The HR head sees and requests changes to staff pay
     * and bank data; the change itself takes effect only after the executive director approves it. The HR head also
     * keeps the confidential register of administrative actions («الإجراءات الإدارية»); nobody decides their own entry.
     *
     * @var array<string, list<string>>
     */
    public const DEPARTMENT_HEAD_PERMISSIONS = [
        'ASSETS' => ['rentals.view', 'rentals.manage'],
        'HR' => ['hr.employees.pay.view', 'hr.employees.bank.view', 'hr.employees.pay.request', 'hr.actions.view', 'hr.actions.manage', 'hr.actions.decide'],
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'department_id',
        'employee_number',
        'is_active',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    // =================================================
    // 1. العلاقات
    // =================================================

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(DepartmentUserRole::class);
    }

    // الربط بملف الموظف الشامل - اختياري
    // الحسابات الحالية قد لا تمتلك ملف موظف بعد.
    public function employeeProfile(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    // =================================================
    // 2. مدير النظام
    // =================================================

    public function isSystemAdmin(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->systemAdminSnapshot !== null) {
            return $this->systemAdminSnapshot;
        }

        return $this->roleAssignments()->effective()
            ->whereNull('department_id')
            ->whereHas('role', function ($query) {
                $query->where('code', 'system_admin');
            })
            ->exists();
    }

    // =================================================
    // 3. رئيس قسم الموارد البشرية
    // =================================================

    public function isHrDepartmentHead(): bool
    {
        return $this->headsDepartment('HR');
    }

    /**
     * Whether the user is the appointed head of the active department with this code. The head may belong to
     * another department (owner decision: one person may head several departments); what counts is the
     * organizational head assignment plus the «رئيس قسم» role held in that department itself.
     */
    public function headsDepartment(string $departmentCode): bool
    {
        return $this->headedDepartmentId($departmentCode) !== null;
    }

    private function headedDepartmentId(string $departmentCode): ?int
    {
        if (! $this->is_active) {
            return null;
        }

        $department = Department::query()
            ->where('code', $departmentCode)
            ->where('is_active', true)
            ->where('head_user_id', $this->id)
            ->first();

        if (! $department) {
            return null;
        }

        $holdsHeadRole = $this->roleAssignments()->effective()
            ->where('department_id', $department->id)
            ->whereHas('role', function ($query) {
                $query->where('code', 'department_head');
            })
            ->exists();

        return $holdsHeadRole ? (int) $department->id : null;
    }

    /**
     * A working permission the user holds as the appointed head of the department that owns it
     * (DEPARTMENT_HEAD_PERMISSIONS), optionally limited to one department scope.
     */
    private function holdsThroughDepartmentHeadship(string $permissionCode, ?int $departmentId = null): bool
    {
        foreach (self::DEPARTMENT_HEAD_PERMISSIONS as $departmentCode => $permissions) {
            if (! in_array($permissionCode, $permissions, true)) {
                continue;
            }
            $headed = $this->headedDepartmentId($departmentCode);
            if ($headed !== null && ($departmentId === null || $departmentId === $headed)) {
                return true;
            }
        }

        return false;
    }

    // =================================================
    // 4. إدارة الهيكل الإداري
    // =================================================

    public function canManageOrganization(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->isSystemAdmin()
            || $this->isHrDepartmentHead();
    }

    // =================================================
    // 5. إدارة الموظفين
    // =================================================

    public function canManageEmployees(): bool
    {
        return $this->canManageOrganization();
    }

    // =================================================
    // 6. التحقق من صلاحية في أي نطاق
    // =================================================

    public function hasPermissionInAnyScope(
        string $permissionCode
    ): bool {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isSystemAdmin()) {
            return true;
        }

        return $this->roleAssignments()->effective()
            ->whereHas(
                'role.permissions',
                function ($query) use ($permissionCode) {
                    $query->where('code', $permissionCode);
                }
            )
            ->exists()
            || $this->holdsThroughDepartmentHeadship($permissionCode)
            // A scoped delegation counts only where it is scoped (whole-file permissions: the owning department).
            || app(ScopedDelegations::class)->grantsInAnyScope($this, $permissionCode);
    }

    /**
     * A business decision permission (approve, reject, decide) held through an assigned role. Unlike
     * hasPermissionInAnyScope(), the system administrator's technical role does not grant it (SRS §2.4).
     */
    public function holdsDecisionPermission(string $permissionCode): bool
    {
        return $this->is_active && $this->roleAssignments()->effective()
            ->whereHas('role.permissions', fn ($query) => $query->where('code', $permissionCode))
            ->exists();
    }

    /**
     * A confidential-data permission (staff pay, bank accounts) held through an assigned role or through the
     * headship of the department that owns it. The system administrator's technical role does not grant it.
     */
    public function holdsConfidentialPermission(string $permissionCode): bool
    {
        return $this->holdsDecisionPermission($permissionCode) || ($this->is_active && $this->holdsThroughDepartmentHeadship($permissionCode));
    }

    // =================================================
    // 7. التحقق من الصلاحية حسب القسم
    // =================================================

    public function hasPermission(
        string $permissionCode,
        ?int $departmentId = null
    ): bool {
        return $this->hasPermissionDirectly($permissionCode, $departmentId)
            || ($this->is_active && app(ScopedDelegations::class)->grants($this, $permissionCode, $departmentId));
    }

    /**
     * The permission through the user's own roles or department headship, without delegations; a delegation can
     * only be created from a permission held this way, so nobody re-delegates what was delegated to them.
     */
    public function hasPermissionDirectly(
        string $permissionCode,
        ?int $departmentId = null
    ): bool {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isSystemAdmin()) {
            return true;
        }

        // صلاحيتان إداريتان حصريتان.
        // لا نعتمد على إسناد permission عام لمنحهما.
        if (in_array($permissionCode, [
            'system.departments.manage',
            'system.users.manage',
        ], true)) {
            return $this->canManageOrganization();
        }

        return $this->roleAssignments()->effective()
            ->where(function ($query) use ($departmentId) {
                if ($departmentId === null) {
                    $query->whereNull('department_id');
                } else {
                    $query->where(function ($q) use ($departmentId) {
                        $q->where('department_id', $departmentId)
                            ->orWhereNull('department_id');
                    });
                }
            })
            ->whereHas(
                'role.permissions',
                function ($query) use ($permissionCode) {
                    $query->where('code', $permissionCode);
                }
            )
            ->exists()
            || $this->holdsThroughDepartmentHeadship($permissionCode, $departmentId);
    }
}
