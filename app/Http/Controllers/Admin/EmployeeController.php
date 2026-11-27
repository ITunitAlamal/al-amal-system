<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentUserRole;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Hr\EmployeePlacements;
use App\Services\Organization\AssignmentHistory;
use App\Services\Organization\RoleSeparation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    private const EMPLOYEE_ROLES = [
        'department_employee',
        'department_head',
    ];

    private const EXECUTIVE_ROLE = 'executive_director';

    private const MAINTENANCE_TECHNICIAN_ROLE = 'maintenance_technician';

    private const MAINTENANCE_DEPARTMENT_CODE = 'HR';

    private const SPECIALIZED_ROLES = [
        'procurement_officer',
        'storekeeper',
    ];

    // Verify the code of the accounting, purchasing and stores department.
    private const FINANCE_DEPARTMENT_CODE = 'FIN';

    /**
     * Roles the system administrator may add here as extra assignments beside the main one, e.g. heading a
     * second department (owner decision: one person may head several departments). Finance roles stay on
     * «صلاحيات الحسابات», the board secretary on its own checkbox, executive and admin roles nowhere here.
     */
    private const ADDITIONAL_ROLES = ['department_employee', 'department_head', 'procurement_officer', 'storekeeper', 'maintenance_technician', 'rentals_officer', 'hr_staff'];

    /** Roles that make up the main assignment edited by the form; every other assignment is kept as it is. */
    private const MAIN_ROLES = ['department_employee', 'department_head', 'procurement_officer', 'storekeeper', 'maintenance_technician'];

    /**
     * Records and transmits the board of directors' decisions in the system (BOARD_CORRESPONDENCE). It is held on
     * top of the person's main role and set by the system admin only, never for a holder of the Secretariat duty
     * (owner decision 5, 2026-11-27; RoleSeparation).
     */
    private const BOARD_SECRETARY_ROLE = 'board_secretary';

    private function boardSecretaryRoleId(): ?int
    {
        $id = Role::query()->where('code', self::BOARD_SECRETARY_ROLE)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Adds or removes the board secretary designation (an organization-wide assignment without a department).
     */
    private function setBoardSecretary(Request $request, User $employee, bool $designated, AuditService $audit): void
    {
        $roleId = $this->boardSecretaryRoleId();
        if ($roleId === null) {
            return;
        }
        $held = DepartmentUserRole::query()->where('user_id', $employee->id)->where('role_id', $roleId)->exists();
        if ($designated === $held) {
            return;
        }
        if ($designated && ! $employee->is_active) {
            throw ValidationException::withMessages(['board_secretary' => 'لا تُسند صفة أمين سر مجلس الإدارة إلى حساب غير نشط.']);
        }
        if ($designated) {
            RoleSeparation::assertAllowed((int) $employee->id, self::BOARD_SECRETARY_ROLE, 'board_secretary');
        }

        if ($designated) {
            DepartmentUserRole::create(['user_id' => $employee->id, 'department_id' => null, 'role_id' => $roleId]);
        } else {
            // The ended designation stays readable in department_user_role_history.
            AssignmentHistory::remove(DepartmentUserRole::query()->where('user_id', $employee->id)->where('role_id', $roleId)->pluck('id')->all(),
                (int) $request->user()->id, 'إلغاء صفة أمين سر مجلس الإدارة');
        }
        $audit->record($request, $designated ? 'employee.board_secretary.assigned' : 'employee.board_secretary.removed', $employee,
            ['board_secretary' => $held], ['board_secretary' => $designated]);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless(
            $request->user()?->canManageEmployees(),
            403,
            'لا تملك صلاحية إدارة الموظفين.'
        );
    }

    private function allowedRoles(Request $request)
    {
        $codes = [...self::EMPLOYEE_ROLES, ...self::SPECIALIZED_ROLES, self::MAINTENANCE_TECHNICIAN_ROLE];
        if ($request->user()->isSystemAdmin()) {
            $codes[] = self::EXECUTIVE_ROLE;
        }

        return Role::query()
            ->whereIn('code', $codes)
            ->orderBy('id')
            ->get(['id', 'code', 'name'])
            ->map(fn (Role $role): array => [
                'id' => $role->id, 'code' => $role->code, 'name' => $role->name,
                // These legacy roles carry no permission inside the finance module.
                'hint' => in_array($role->code, self::SPECIALIZED_ROLES, true)
                    ? 'لا يمنح صلاحيات في وحدة الحسابات؛ تُمنح من «صلاحيات الحسابات» في إعدادات النظام.' : null,
            ]);
    }

    private function ensureRoleAllowed(
        Request $request,
        Role $role,
        int $departmentId
    ): void {
        if (in_array($role->code, self::EMPLOYEE_ROLES, true)) {
            return;
        }

        if (in_array($role->code, self::SPECIALIZED_ROLES, true)) {
            $correctDepartment = Department::query()
                ->whereKey($departmentId)
                ->where('code', self::FINANCE_DEPARTMENT_CODE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->exists();

            if (! $correctDepartment) {
                throw ValidationException::withMessages([
                    'department_id' => 'مسؤول المشتريات وأمين المخازن يجب أن يتبعا قسم الحسابات والمشتريات والمخازن النشط.',
                ]);
            }

            return;
        }

        if ($role->code === self::MAINTENANCE_TECHNICIAN_ROLE) {
            $correctDepartment = Department::query()
                ->whereKey($departmentId)
                ->where('code', self::MAINTENANCE_DEPARTMENT_CODE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->exists();

            if (! $correctDepartment) {
                throw ValidationException::withMessages([
                    'department_id' => 'دور فني الصيانة يتطلب قسم الموارد البشرية والخدمات العامة النشط.',
                ]);
            }

            return;
        }

        abort_unless(
            $role->code === self::EXECUTIVE_ROLE
                && $request->user()->isSystemAdmin(),
            403,
            'هذا الدور لا يمكن منحه من شاشة إدارة الموظفين.'
        );

        $isExecutiveDepartment = Department::query()
            ->whereKey($departmentId)
            ->where('code', 'EXEC')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $isExecutiveDepartment) {
            throw ValidationException::withMessages([
                'department_id' => 'دور المدير التنفيذي يتطلب قسم الإدارة التنفيذية النشط.',
            ]);
        }
    }

    private function ensureSingleExecutive(?int $excludedUserId): void
    {
        // قفل صف الدور يمنع تعيين مديرين تنفيذيين بالتوازي.
        $role = Role::query()
            ->where('code', self::EXECUTIVE_ROLE)
            ->lockForUpdate()
            ->firstOrFail();

        $existing = DepartmentUserRole::query()
            ->join('users', 'users.id', '=', 'department_user_roles.user_id')
            ->where('department_user_roles.role_id', $role->id)
            ->where('users.is_active', true);

        if ($excludedUserId !== null) {
            $existing->where('users.id', '!=', $excludedUserId);
        }

        if ($existing->exists()) {
            throw ValidationException::withMessages([
                'role_id' => 'يوجد مدير تنفيذي نشط بالفعل؛ راجع تكليفه قبل إضافة آخر.',
            ]);
        }
    }

    private function ensureEditable(
        Request $request,
        User $employee
    ): void {
        if ($employee->id === $request->user()->id) {
            abort(403, 'لا يمكن تعديل حسابك من شاشة إدارة الموظفين.');
        }

        if ($employee->isSystemAdmin()) {
            abort(403, 'حساب مدير النظام محمي.');
        }

        $hasProtectedRole = $employee->roleAssignments()
            ->whereHas('role', function ($query) {
                $query->whereIn('code', [
                    'system_admin',
                    'executive_director',
                ]);
            })
            ->exists();

        if ($hasProtectedRole) {
            abort(403, 'هذا الحساب يتطلب إدارة صلاحيات خاصة.');
        }
    }

    /**
     * A head must stay active and keep the head role in every department they head: either through the main
     * assignment being saved or through an additional assignment that is kept.
     *
     * @param  Collection<int, DepartmentUserRole>|null  $keptAssignments
     */
    private function ensureHeadAssignmentSafe(
        User $employee,
        array $data,
        ?string $roleCode = null,
        ?Collection $keptAssignments = null
    ): void {
        $heads = Department::query()
            ->where('head_user_id', $employee->id)
            ->get();

        if ($heads->isEmpty()) {
            return;
        }

        $newDepartmentId = (int) $data['department_id'];
        $newActive = (bool) $data['is_active'];
        $headRoleId = (int) Role::query()->where('code', 'department_head')->value('id');

        foreach ($heads as $department) {
            $keepsHeadRole = ($newDepartmentId === (int) $department->id && $roleCode === 'department_head')
                || ($keptAssignments ?? collect())->contains(fn (DepartmentUserRole $item): bool => (int) $item->role_id === $headRoleId
                    && (int) $item->department_id === (int) $department->id);
            if (! $newActive || ! $keepsHeadRole) {
                throw ValidationException::withMessages([
                    'department_id' => 'الموظف رئيس قسم حالي. يجب تعيين رئيس بديل أو إلغاء رئاسته أولًا.',
                ]);
            }
        }
    }

    public function index(Request $request)
    {
        $this->authorizeManagement($request);

        $query = User::query()
            ->with([
                'department:id,name',
                'roleAssignments.role:id,code,name',
            ])
            ->when(! $request->user()->isSystemAdmin(), fn ($users) => $users->where(function ($query): void {
                $query->whereHas('roleAssignments.role', function ($roles): void {
                    $roles->whereIn('code', [
                        ...self::EMPLOYEE_ROLES,
                        ...self::SPECIALIZED_ROLES,
                        self::MAINTENANCE_TECHNICIAN_ROLE,
                        self::EXECUTIVE_ROLE,
                    ]);
                })->orWhereDoesntHave('roleAssignments');
            }))
            ->orderBy('name');

        if (! $request->user()->isSystemAdmin()) {
            $query->whereDoesntHave(
                'roleAssignments.role',
                function ($query) {
                    $query->whereIn('code', [
                        'system_admin',
                        'executive_director',
                    ]);
                }
            );
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'pending'])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $query
            ->when($search !== '', fn ($users) => $users->where(fn ($match) => $match
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('employee_number', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")))
            ->when(($filters['status'] ?? null) === 'active', fn ($users) => $users->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($users) => $users->where('is_active', false)
                ->whereDoesntHave('employeeProfile', fn ($profile) => $profile->where('onboarding_status', 'pending_activation')))
            ->when(($filters['status'] ?? null) === 'pending', fn ($users) => $users->where('is_active', false)
                ->whereHas('employeeProfile', fn ($profile) => $profile->where('onboarding_status', 'pending_activation')));

        $headOf = Department::query()->whereNotNull('head_user_id')->where('is_active', true)
            ->get(['id', 'head_user_id'])->groupBy('head_user_id');
        $departmentNames = Department::query()->pluck('name', 'id');

        $employees = $query->with('employeeProfile:id,user_id,onboarding_status')->paginate(15)->withQueryString()
            ->through(function ($employee) use ($headOf, $departmentNames, $request) {
                $assignment = $employee->roleAssignments
                    ->first(function ($item) use ($employee) {
                        return
                            (int) $item->department_id ===
                            (int) $employee->department_id
                            &&
                            in_array(
                                $item->role?->code,
                                [...self::EMPLOYEE_ROLES, ...self::SPECIALIZED_ROLES, self::MAINTENANCE_TECHNICIAN_ROLE, self::EXECUTIVE_ROLE],
                                true
                            );
                    });
                $heads = $headOf->get($employee->id, collect());

                return [
                    'id' => $employee->id,
                    'can_edit' => (int) $employee->id !== (int) $request->user()->id
                        && ! $employee->roleAssignments->contains(fn ($item) => in_array($item->role?->code, ['system_admin', 'executive_director'], true)),
                    'can_reset_password' => $request->user()->isSystemAdmin() && (int) $employee->id !== (int) $request->user()->id,
                    // «إيقاف الحساب» (accounts are never erased): active accounts only.
                    'can_delete' => $request->user()->isSystemAdmin() && (int) $employee->id !== (int) $request->user()->id && (bool) $employee->is_active
                        && ! $employee->roleAssignments->contains(fn ($item) => in_array($item->role?->code, ['system_admin', 'executive_director'], true)),
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'employee_number' => $employee->employee_number,
                    'department_id' => $employee->department_id,
                    'department_name' => $employee->department?->name,
                    'role_id' => $assignment?->role_id,
                    'role_code' => $assignment?->role?->code,
                    'role_name' => $assignment?->role?->name,
                    'is_active' => (bool) $employee->is_active,
                    // A new employee's account waits for the employee to activate it with the SMS code.
                    'pending_activation' => ! $employee->is_active && $employee->employeeProfile?->onboarding_status === 'pending_activation',
                    'heads_own_department' => $heads->contains('id', (int) $employee->department_id),
                    'board_secretary' => $employee->roleAssignments->contains(fn ($item) => $item->role?->code === self::BOARD_SECRETARY_ROLE),
                    // Every other assignment besides the main one, with where it is managed.
                    'assignments' => $employee->roleAssignments
                        ->filter(fn ($item) => $item->id !== $assignment?->id)
                        ->map(fn ($item): array => [
                            'id' => $item->id, 'role' => $item->role?->name, 'role_code' => $item->role?->code,
                            'department' => $departmentNames[$item->department_id] ?? ($item->department_id ? '#'.$item->department_id : 'كل المؤسسة'),
                            'heads_it' => $item->role?->code === 'department_head' && $heads->contains('id', (int) $item->department_id),
                            'managed_here' => in_array($item->role?->code, self::ADDITIONAL_ROLES, true),
                            'managed_by' => match (true) {
                                in_array($item->role?->code, self::ADDITIONAL_ROLES, true) => null,
                                $item->role?->code === self::BOARD_SECRETARY_ROLE => 'خيار «أمين سر مجلس الإدارة»',
                                str_starts_with((string) $item->role?->code, 'finance_') => '«صلاحيات الحسابات» في إعدادات النظام',
                                default => 'إدارة خاصة',
                            },
                        ])->values()->all(),
                ];
            });

        $departments = Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'head_user_id']);
        $headNames = User::query()->whereIn('id', $departments->pluck('head_user_id')->filter())->pluck('name', 'id');
        $departments = $departments->map(fn (Department $department): array => [
            'id' => $department->id,
            'code' => $department->code,
            'name' => $department->name,
            'head_user_id' => $department->head_user_id,
            'head_name' => $department->head_user_id ? $headNames->get($department->head_user_id) : null,
        ]);

        $navigation = [
            [
                'label' => 'لوحة التحكم',
                'url' => route('ui.preview'),
                'icon' => '▦',
                'active' => false,
            ],
            [
                'label' => 'إدارة الأقسام',
                'url' => route('admin.departments.vue'),
                'icon' => '🏢',
                'active' => false,
            ],
            [
                'label' => 'إدارة الموظفين',
                'url' => route('admin.employees.index'),
                'icon' => '👥',
                'active' => true,
            ],
        ];

        if ($request->user()->isSystemAdmin()) {
            $navigation[] = [
                'label' => 'سجل نشاط النظام',
                'url' => route('admin.audit-logs.index'),
                'icon' => '📋',
                'active' => false,
            ];
        }

        return Inertia::render('Admin/Employees/Index', [
            'username' => $request->user()->name,
            'navigation' => $navigation,
            'employees' => $employees,
            'departments' => $departments,
            'roles' => $this->allowedRoles($request),
            'filters' => ['q' => $search, 'status' => $filters['status'] ?? ''],
            'canDesignateBoard' => $request->user()->isSystemAdmin() && $this->boardSecretaryRoleId() !== null,
            'canManageAssignments' => $request->user()->isSystemAdmin(),
            'assignableRoles' => $request->user()->isSystemAdmin()
                ? Role::query()->whereIn('code', self::ADDITIONAL_ROLES)->orderBy('id')->get(['id', 'code', 'name']) : [],
        ]);
    }

    /**
     * Adds an assignment beside the main one, e.g. head of a second department. System administrator only,
     * with a recorded reason; the role's own department rules still apply.
     */
    public function addAssignment(Request $request, User $employee, AuditService $audit)
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'إضافة التكليفات من صلاحيات مدير النظام.');
        $this->ensureEditable($request, $employee);
        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->whereIn('code', self::ADDITIONAL_ROLES))],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['role_id' => 'الدور', 'department_id' => 'القسم', 'reason' => 'سبب التكليف']);
        $role = Role::findOrFail($data['role_id']);
        if (in_array($role->code, self::MAIN_ROLES, true)) {
            $this->ensureRoleAllowed($request, $role, (int) $data['department_id']);
        }

        DB::transaction(function () use ($request, $employee, $data, $role, $audit): void {
            $locked = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['role_id' => 'لا يُضاف تكليف لحساب غير نشط.']);
            }
            if (DepartmentUserRole::query()->where('user_id', $locked->id)->where('role_id', $role->id)->where('department_id', $data['department_id'])->exists()) {
                throw ValidationException::withMessages(['role_id' => 'هذا التكليف مسند للموظف من قبل.']);
            }
            RoleSeparation::assertAllowed((int) $locked->id, $role->code);
            $assignment = DepartmentUserRole::create(['user_id' => $locked->id, 'department_id' => $data['department_id'], 'role_id' => $role->id]);
            $audit->record($request, 'employee.assignment.added', $locked, null,
                ['assignment_id' => $assignment->id, 'role' => $role->code, 'department_id' => (int) $data['department_id'], 'reason' => $data['reason']]);
        });

        return back()->with('success', 'أُضيف التكليف وسُجل في سجل النشاط. لتعيينه رئيسًا للقسم الآخر استخدم صفحة «الأقسام».');
    }

    /**
     * Removes an extra assignment. The main assignment is changed through the edit form, and the head role of
     * a department the employee still heads is kept until another head is appointed.
     */
    public function removeAssignment(Request $request, User $employee, int $assignment, AuditService $audit)
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'إزالة التكليفات من صلاحيات مدير النظام.');
        $this->ensureEditable($request, $employee);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [], ['reason' => 'سبب الإزالة']);

        DB::transaction(function () use ($request, $employee, $assignment, $data, $audit): void {
            $locked = User::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $row = DepartmentUserRole::query()->with('role:id,code')->whereKey($assignment)->where('user_id', $locked->id)->lockForUpdate()->firstOrFail();
            $code = (string) $row->role?->code;
            abort_unless(in_array($code, self::ADDITIONAL_ROLES, true), 403, 'هذا التكليف يُدار من شاشته المختصة.');
            if (in_array($code, self::MAIN_ROLES, true) && (int) $row->department_id === (int) $locked->department_id) {
                throw ValidationException::withMessages(['assignment' => 'هذا هو التكليف الرئيسي للموظف في قسمه؛ يُعدَّل من نموذج التعديل.']);
            }
            if ($code === 'department_head' && Department::query()->whereKey($row->department_id)->where('head_user_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'الموظف رئيس هذا القسم حاليًا؛ عيّن رئيسًا بديلًا أو ألغِ الرئاسة من صفحة «الأقسام» أولًا.']);
            }
            $before = ['assignment_id' => $row->id, 'role' => $code, 'department_id' => $row->department_id];
            // Ended, not erased: the assignment, its period, who removed it and why stay in department_user_role_history.
            AssignmentHistory::remove((int) $row->id, (int) $request->user()->id, $data['reason']);
            $audit->record($request, 'employee.assignment.removed', $locked, $before, ['reason' => $data['reason']]);
        });

        return back()->with('success', 'أُزيل التكليف وسُجل في سجل النشاط.');
    }

    public function store(
        Request $request,
        AuditService $audit
    ) {
        $this->authorizeManagement($request);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'employee_number' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/D',
                Rule::unique('users', 'employee_number'),
            ],
            'password' => [
                'required',
                'string',
                'min:10',
                'max:128',
                'confirmed',
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],
            'make_department_head' => ['sometimes', 'boolean'],
            'board_secretary' => ['sometimes', 'boolean'],
        ]);

        $role = Role::findOrFail($data['role_id']);

        $this->ensureRoleAllowed(
            $request,
            $role,
            (int) $data['department_id']
        );

        if (($data['board_secretary'] ?? false) && $role->code === self::EXECUTIVE_ROLE) {
            throw ValidationException::withMessages(['board_secretary' => 'لا تُسند صفة أمين سر مجلس الإدارة إلى المدير التنفيذي.']);
        }

        // An archived or current employee profile keeps its number; a new account must not reuse it.
        if (Employee::withTrashed()->where('employee_number', $data['employee_number'])->exists()) {
            throw ValidationException::withMessages([
                'employee_number' => 'الرقم الوظيفي مستخدم في ملف موظف حالي أو مؤرشف.',
            ]);
        }

        DB::transaction(function () use (
            $request,
            $data,
            $role,
            $audit
        ) {
            if ($role->code === self::EXECUTIVE_ROLE) {
                $this->ensureSingleExecutive(null);
            }

            // The password set here is temporary: the employee must replace it at first sign-in.
            $employee = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'employee_number' => $data['employee_number'],
                'password' => $data['password'],
                'department_id' => $data['department_id'],
                'is_active' => true,
                'must_change_password' => true,
            ]);

            DepartmentUserRole::create([
                'user_id' => $employee->id,
                'department_id' => $data['department_id'],
                'role_id' => $role->id,
            ]);

            $audit->record(
                $request,
                'employee.created',
                $employee,
                null,
                [
                    'name' => $employee->name,
                    'employee_number' => $employee->employee_number,
                    'department_id' => $employee->department_id,
                    'role_code' => $role->code,
                    'is_active' => true,
                ]
            );

            if ($role->code === 'department_head' && ($data['make_department_head'] ?? false)) {
                $this->appointHead($request, $employee, (int) $data['department_id'], $audit);
            }
            if ($request->user()->isSystemAdmin() && ($data['board_secretary'] ?? false)) {
                $this->setBoardSecretary($request, $employee, true, $audit);
            }
        }, 3);

        return redirect()
            ->route('admin.employees.index')
            ->with('success', 'تم إنشاء حساب الموظف. يغيّر الموظف كلمة المرور المؤقتة عند أول دخول.');
    }

    /**
     * Makes the account the organizational head of its department, the assignment the workflows route to.
     * An existing head is never replaced from here; that is done on the departments page.
     */
    private function appointHead(Request $request, User $employee, int $departmentId, AuditService $audit): void
    {
        $department = Department::query()->whereKey($departmentId)->lockForUpdate()->firstOrFail();
        if ((int) $department->head_user_id === (int) $employee->id) {
            return;
        }
        if ($department->head_user_id !== null) {
            throw ValidationException::withMessages([
                'make_department_head' => 'للقسم رئيس معيّن حاليًا. يُستبدل من صفحة «الأقسام» إن لزم.',
            ]);
        }
        if (! $employee->is_active) {
            throw ValidationException::withMessages([
                'make_department_head' => 'لا يُعيَّن حساب غير نشط رئيسًا للقسم.',
            ]);
        }

        $before = $department->getAttributes();
        $department->update(['head_user_id' => $employee->id]);
        $audit->record($request, 'department.updated', $department, $before, $department->fresh()->getAttributes());
    }

    public function update(
        Request $request,
        User $employee,
        AuditService $audit
    ) {
        $this->authorizeManagement($request);
        $this->ensureEditable($request, $employee);

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($employee->id),
            ],
            'employee_number' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/D',
                Rule::unique('users', 'employee_number')
                    ->ignore($employee->id),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id'),
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
            'make_department_head' => ['sometimes', 'boolean'],
            'board_secretary' => ['sometimes', 'boolean'],
        ]);

        $role = Role::findOrFail($data['role_id']);

        $this->ensureRoleAllowed(
            $request,
            $role,
            (int) $data['department_id']
        );

        DB::transaction(function () use (
            $request,
            $employee,
            $data,
            $role,
            $audit
        ) {
            $lockedEmployee = User::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureEditable(
                $request,
                $lockedEmployee
            );

            $this->ensureRoleAllowed(
                $request,
                $role,
                (int) $data['department_id']
            );

            if ($role->code === self::EXECUTIVE_ROLE) {
                $this->ensureSingleExecutive((int) $lockedEmployee->id);
            }

            $existingAssignments = DepartmentUserRole::query()
                ->where('user_id', $lockedEmployee->id)
                ->lockForUpdate()
                ->get();

            // The form edits the main assignment only: a main role in the employee's current department.
            // Every other assignment (another department, finance roles, the board secretary designation) is kept.
            $mainRoleIds = Role::query()->whereIn('code', self::MAIN_ROLES)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $mainAssignments = $existingAssignments->filter(fn (DepartmentUserRole $item): bool => in_array((int) $item->role_id, $mainRoleIds, true)
                && (int) $item->department_id === (int) $lockedEmployee->department_id);
            $keptAssignments = $existingAssignments->reject(fn (DepartmentUserRole $item): bool => $mainAssignments->contains('id', $item->id));

            if ($mainAssignments->count() > 1) {
                throw ValidationException::withMessages([
                    'role_id' => 'لدى هذا الموظف أكثر من دور رئيسي في قسمه؛ أزل الزائد من «التكليفات الإضافية» ثم احفظ.',
                ]);
            }

            $this->ensureHeadAssignmentSafe(
                $lockedEmployee,
                $data,
                $role->code,
                $keptAssignments
            );

            // A new employee's account is activated only by the employee with the SMS code, which also sets
            // the password; switching it on here would leave an active account nobody can sign in to.
            if (! $lockedEmployee->is_active && $data['is_active']
                && Employee::query()->where('user_id', $lockedEmployee->id)->where('onboarding_status', 'pending_activation')->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => 'هذا الحساب بانتظار التفعيل. أرسل رمز SMS من ملف الموظف، أو يفعّله مدير النظام من ملف الموظف بكلمة مرور مؤقتة.',
                ]);
            }

            $before = [
                'name' => $lockedEmployee->name,
                'employee_number' => $lockedEmployee->employee_number,
                'department_id' => $lockedEmployee->department_id,
                'is_active' => (bool) $lockedEmployee->is_active,
                'role_ids' => $existingAssignments->pluck('role_id')->all(),
            ];

            $lockedEmployee->update([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'employee_number' => $data['employee_number'],
                'department_id' => $data['department_id'],
                'is_active' => $data['is_active'],
            ]);
            if ($before['is_active'] && ! $data['is_active']) {
                // A deactivated account loses its open sessions now (EnsureStaffActive also ends any that remain).
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $lockedEmployee->id)->delete();
            }

            $profile = Employee::withTrashed()->where('user_id', $lockedEmployee->id)->lockForUpdate()->first();
            if (Employee::withTrashed()->where('employee_number', $data['employee_number'])
                ->when($profile, fn ($query) => $query->whereKeyNot($profile->id))->exists()) {
                throw ValidationException::withMessages(['employee_number' => 'الرقم الوظيفي مستخدم في ملف موظف آخر.']);
            }
            if ($profile !== null) {
                $departmentChanged = (int) $profile->department_id !== (int) $data['department_id'];
                $profile->update([
                    'employee_number' => $data['employee_number'],
                    'department_id' => $data['department_id'],
                    'updated_by' => $request->user()->id,
                ]);
                if ($departmentChanged) {
                    // The profile form sends department changes of account-linked staff here, so this path writes
                    // «سجل التنقلات» the same way: the previous placement is closed and a new one opens today.
                    app(EmployeePlacements::class)->record($profile->id, $profile->only(['department_id', 'job_title_id', 'job_grade_id', 'department_source', 'job_title_source', 'grade_source']),
                        now()->toDateString(), 'profile', 'نقل القسم من «حسابات الموظفين والأدوار»', (int) $request->user()->id);
                }
            }

            // The main assignment is kept when it does not change (its start date and history stay as they are);
            // a replaced one ends and stays readable in department_user_role_history.
            $unchanged = $mainAssignments->first(fn (DepartmentUserRole $item): bool => (int) $item->role_id === (int) $role->id
                && (int) $item->department_id === (int) $data['department_id']);
            AssignmentHistory::remove($mainAssignments->reject(fn (DepartmentUserRole $item): bool => $item->id === $unchanged?->id)->pluck('id')->all(),
                (int) $request->user()->id, 'تعديل التكليف الرئيسي من «حسابات الموظفين والأدوار»');

            // A board secretary designation removed in this same save goes first, so the separation check sees the result.
            if ($request->user()->isSystemAdmin() && array_key_exists('board_secretary', $data) && ! $data['board_secretary']) {
                $this->setBoardSecretary($request, $lockedEmployee, false, $audit);
            }
            $alreadyHeld = $unchanged !== null || $keptAssignments->contains(fn (DepartmentUserRole $item): bool => (int) $item->role_id === (int) $role->id
                && (int) $item->department_id === (int) $data['department_id']);
            if (! $alreadyHeld) {
                RoleSeparation::assertAllowed((int) $lockedEmployee->id, $role->code);
                DepartmentUserRole::create([
                    'user_id' => $lockedEmployee->id,
                    'department_id' => $data['department_id'],
                    'role_id' => $role->id,
                ]);
            }

            $audit->record(
                $request,
                'employee.updated',
                $lockedEmployee,
                $before,
                [
                    'name' => $lockedEmployee->name,
                    'employee_number' => $lockedEmployee->employee_number,
                    'department_id' => $lockedEmployee->department_id,
                    'is_active' => (bool) $lockedEmployee->is_active,
                    'role_code' => $role->code,
                ]
            );

            if ($role->code === 'department_head' && ($data['make_department_head'] ?? false)) {
                $this->appointHead($request, $lockedEmployee, (int) $data['department_id'], $audit);
            }
            // Only the system administrator designates the board secretary; other editors leave it untouched.
            if ($request->user()->isSystemAdmin() && array_key_exists('board_secretary', $data)) {
                $this->setBoardSecretary($request, $lockedEmployee, (bool) $data['board_secretary'], $audit);
            }
        }, 3);

        return redirect()
            ->route('admin.employees.index')
            ->with('success', 'تم تحديث بيانات الموظف بنجاح.');
    }
}
