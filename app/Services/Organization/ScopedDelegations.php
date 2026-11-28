<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Kpi\KpiScope;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Time-limited delegation of one permission inside one department or unit. Only permissions the policy marks
 * delegable (delegable_permissions, all disabled until the institution enables them) can be delegated, only by
 * someone who holds the permission themselves in that scope through a role or headship — never through another
 * delegation — and never for longer than the policy allows. Permissions the policy marks executive-only are
 * delegated by the executive director alone. A delegation is checked on every use: revoked, expired, or no longer
 * held by its delegator means it grants nothing.
 */
final class ScopedDelegations
{
    /** @var array<int, list<object>> active delegations per delegate, for this request */
    private static array $active = [];

    /** @var array<string, object>|null */
    private static ?array $policy = null;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    /** @return array<string, object> enabled policy rows by permission code */
    public function enabledPolicy(): array
    {
        return self::$policy ??= DB::table('delegable_permissions as d')->join('permissions as p', 'p.code', '=', 'd.permission_code')
            ->where('d.is_active', true)->get(['d.*', 'p.name as permission_name'])->keyBy('permission_code')->all();
    }

    /** Forget per-request caches (after a change in the same request). */
    public static function flush(): void
    {
        self::$active = [];
        self::$policy = null;
    }

    /**
     * Permissions whose file is not divided by department: a delegation opens such a file only when it is scoped to
     * the whole department that owns it, never through one of its units («الإيجارات» belongs to the assets department).
     *
     * @var array<string, string> permission code => owning department code
     */
    public const WHOLE_FILE_OWNERS = ['rentals.view' => 'ASSETS', 'rentals.manage' => 'ASSETS'];

    /**
     * Whether an active delegation gives the user this permission in this department (or one of its units). A null
     * department means organization-wide, which a delegation never grants: it is always scoped to one department or
     * unit. Callers that only need "somewhere" use grantsInAnyScope() and then filter by delegatedDepartments().
     */
    public function grants(User $user, string $code, ?int $departmentId = null): bool
    {
        if ($departmentId === null || ! isset($this->enabledPolicy()[$code]) || ! $user->is_active) {
            return false;
        }
        foreach ($this->activeFor($user) as $row) {
            if ($row->permission_code !== $code) {
                continue;
            }
            $scope = (int) $row->scope_department_id;
            if ($departmentId === $scope || (int) DB::table('departments')->where('id', $departmentId)->value('parent_id') === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a delegation gives the user this permission in some scope. For a file not divided by department
     * (WHOLE_FILE_OWNERS) only a delegation scoped to the owning department itself counts.
     */
    public function grantsInAnyScope(User $user, string $code): bool
    {
        if (! $user->is_active) {
            return false;
        }
        $scopes = $this->delegatedDepartments($user, $code);
        if ($scopes === [] || ! isset(self::WHOLE_FILE_OWNERS[$code])) {
            return $scopes !== [];
        }
        $owner = DB::table('departments')->where('code', self::WHOLE_FILE_OWNERS[$code])->where('is_active', true)->whereNull('deleted_at')->value('id');

        return $owner !== null && in_array((int) $owner, $scopes, true);
    }

    /** @return list<int> scopes (with their units) where the user holds this permission by delegation */
    public function delegatedDepartments(User $user, string $code): array
    {
        if (! isset($this->enabledPolicy()[$code])) {
            return [];
        }
        $ids = [];
        foreach ($this->activeFor($user) as $row) {
            if ($row->permission_code === $code) {
                $ids[] = (int) $row->scope_department_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether the user holds the permission in this scope by their own role or headship. A unit's scope is held
     * through its department.
     */
    public function holdsDirectly(User $user, string $code, int $departmentId): bool
    {
        $node = DB::table('departments')->where('id', $departmentId)->first();
        if (! $node || $node->deleted_at !== null || $user->isSystemAdmin() && ! $this->isHeadOrExecutive($user)) {
            return false;
        }
        $owner = $node->node_type === 'unit' ? (int) $node->parent_id : (int) $node->id;
        if ($code === KpiScope::PERMISSION) {
            $scope = KpiScope::resolve($user, false);

            return $scope !== null && $scope->covers($owner);
        }

        return $user->hasPermissionDirectly($code, $owner) || $user->hasPermissionDirectly($code, (int) $node->id);
    }

    /**
     * Checks a delegation and describes exactly what it would allow, before anything is saved.
     *
     * @param  array<string, mixed>  $data
     * @return array{errors: list<string>, lines: list<string>}
     */
    public function preview(User $delegator, array $data): array
    {
        $errors = [];
        $policy = $this->enabledPolicy()[$data['permission_code'] ?? ''] ?? null;
        $scope = DB::table('departments')->where('id', (int) ($data['scope_department_id'] ?? 0))->whereNull('deleted_at')->first();
        $delegate = User::where('id', (int) ($data['delegate_user_id'] ?? 0))->where('is_active', true)->first();
        $starts = Carbon::parse($data['starts_at'] ?? now());
        $ends = isset($data['ends_at']) ? Carbon::parse($data['ends_at'])->endOfDay() : null;
        if (! $policy) {
            $errors[] = 'هذه الصلاحية غير مفعّلة للتفويض في سياسة المؤسسة.';
        }
        if (! $scope || ! $scope->is_active) {
            $errors[] = 'النطاق المختار غير موجود أو معطّل.';
        }
        if (! $delegate) {
            $errors[] = 'اختر موظفًا نشطًا.';
        } elseif ($delegate->id === $delegator->id) {
            $errors[] = 'لا يفوَّض الشخص لنفسه.';
        }
        if (! $ends || $ends->lessThanOrEqualTo($starts) || $ends->isPast()) {
            $errors[] = 'حدد تاريخ انتهاء بعد تاريخ البدء وفي المستقبل.';
        } elseif ($policy && $starts->diffInDays($ends) > $policy->max_days) {
            $errors[] = 'أقصى مدة لهذا التفويض '.$policy->max_days.' يومًا.';
        }
        if ($policy && $scope) {
            if ($policy->requires_executive && ! $this->isExecutive($delegator)) {
                $errors[] = 'هذه الصلاحية لا يفوّضها إلا المدير التنفيذي.';
            } elseif (! $policy->requires_executive && ! $this->isExecutive($delegator) && ! in_array($scope->node_type === 'unit' ? (int) $scope->parent_id : (int) $scope->id, app(StructureChanges::class)->headedDepartments($delegator), true)) {
                $errors[] = 'يفوّض رئيس القسم داخل قسمه ووحداته فقط.';
            }
            if (! $this->holdsDirectly($delegator, $policy->permission_code, (int) $scope->id)) {
                $errors[] = 'لا تملك هذه الصلاحية بنفسك في هذا النطاق، فلا يمكنك تفويضها.';
            }
            if ($delegate && ! $this->belongsTo($delegate, $scope)) {
                $errors[] = 'المفوَّض ليس من موظفي هذا القسم أو الوحدة.';
            }
            if ($delegate && DB::table('scoped_delegations')->where('delegate_user_id', $delegate->id)->where('permission_code', $policy->permission_code)
                ->where('scope_department_id', $scope->id)->where('status', 'active')->where('ends_at', '>', now())->exists()) {
                $errors[] = 'يوجد تفويض ساري بالصلاحية نفسها لهذا الموظف في النطاق نفسه.';
            }
        }
        $lines = $policy && $scope && $delegate && $ends ? [
            'المفوَّض: '.$delegate->name,
            'الصلاحية: '.$policy->permission_name.($policy->note ? ' — '.$policy->note : ''),
            'النطاق: '.(OrganizationStructure::TYPES[$scope->node_type] ?? '').' «'.$scope->name.'» فقط',
            'المدة: من '.$starts->toDateString().' حتى نهاية '.$ends->toDateString().' ('.max(1, (int) ceil($starts->diffInDays($ends))).' يومًا)، وينتهي تلقائيًا',
            'لا يمنح أي صلاحية أخرى، ولا يستطيع المفوَّض إعادة تفويضها، ويسقط إذا فقدتَ أنت الصلاحية.',
        ] : [];

        return ['errors' => $errors, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $delegator, array $data, ?string $ip): int
    {
        return DB::transaction(function () use ($delegator, $data, $ip): int {
            DB::table('users')->where('id', $delegator->id)->lockForUpdate()->first();
            $preview = $this->preview($delegator, $data);
            if ($preview['errors'] !== []) {
                throw ValidationException::withMessages(['delegation' => implode(' ', $preview['errors'])]);
            }
            $starts = Carbon::parse($data['starts_at'] ?? now());
            $ends = Carbon::parse($data['ends_at'])->endOfDay();
            $id = (int) DB::table('scoped_delegations')->insertGetId(['delegator_user_id' => $delegator->id, 'delegate_user_id' => (int) $data['delegate_user_id'],
                'permission_code' => $data['permission_code'], 'scope_department_id' => (int) $data['scope_department_id'], 'starts_at' => $starts, 'ends_at' => $ends,
                'status' => 'active', 'reason' => trim((string) $data['reason']), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('organization.delegation.created', 'scoped_delegations', $id, null, ['delegate_user_id' => (int) $data['delegate_user_id'],
                'permission' => $data['permission_code'], 'scope_department_id' => (int) $data['scope_department_id'], 'starts_at' => (string) $starts, 'ends_at' => (string) $ends,
                'reason' => $data['reason'], 'preview' => $preview['lines']], $delegator->id, $ip);
            $this->notifications->notify((int) $data['delegate_user_id'], 'scoped_delegation_changed', ['title' => 'فُوّضت إليك صلاحية مؤقتة',
                'body' => implode(' · ', array_slice($preview['lines'], 1, 3)), 'url' => '/my-department', 'dedupe' => 'delegation:'.$id.':created']);
            self::flush();

            return $id;
        });
    }

    public function revoke(User $actor, int $id, string $reason, ?string $ip): void
    {
        DB::transaction(function () use ($actor, $id, $reason, $ip): void {
            $row = DB::table('scoped_delegations')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'active', 404);
            abort_unless((int) $row->delegator_user_id === (int) $actor->id || $this->isExecutive($actor) || $actor->isSystemAdmin(), 403, 'يلغي التفويض صاحبه أو المدير التنفيذي أو مدير النظام.');
            DB::table('scoped_delegations')->where('id', $id)->update(['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'revoke_reason' => $reason, 'updated_at' => now()]);
            $this->audit->recordRow('organization.delegation.revoked', 'scoped_delegations', $id, ['status' => 'active'], ['status' => 'revoked', 'reason' => $reason], $actor->id, $ip);
            $this->notifications->notify((int) $row->delegate_user_id, 'scoped_delegation_changed', ['title' => 'أُلغي تفويض صلاحية', 'url' => '/my-department', 'dedupe' => 'delegation:'.$id.':revoked']);
            self::flush();
        });
    }

    /**
     * Delegations the user granted or received, or every one for the executive director and administrator.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user): array
    {
        $everyone = $this->isExecutive($user) || $user->isSystemAdmin();

        return DB::table('scoped_delegations as s')->join('users as a', 'a.id', '=', 's.delegator_user_id')->join('users as b', 'b.id', '=', 's.delegate_user_id')
            ->join('departments as d', 'd.id', '=', 's.scope_department_id')->join('permissions as p', 'p.code', '=', 's.permission_code')
            ->when(! $everyone, fn ($q) => $q->where(fn ($w) => $w->where('s.delegator_user_id', $user->id)->orWhere('s.delegate_user_id', $user->id)))
            ->orderByDesc('s.id')->limit(100)->get(['s.id', 's.permission_code', 'p.name as permission', 'd.name as scope', 'a.name as delegator', 'b.name as delegate',
                's.delegator_user_id', 's.starts_at', 's.ends_at', 's.status', 's.reason', 's.revoke_reason'])
            ->map(fn (object $row): array => [...(array) $row, 'state' => $row->status === 'revoked' ? 'ملغى' : (Carbon::parse($row->ends_at)->isPast() ? 'منتهٍ' : (Carbon::parse($row->starts_at)->isFuture() ? 'لم يبدأ' : 'ساري')),
                'can_revoke' => $row->status === 'active' && ! Carbon::parse($row->ends_at)->isPast() && ((int) $row->delegator_user_id === (int) $user->id || $everyone)])->all();
    }

    /**
     * What the user may delegate right now: enabled permissions they could grant, and the scopes they lead with the
     * people in each. Empty while the institution has enabled nothing.
     *
     * @return array{permissions: list<array<string, mixed>>, scopes: list<array<string, mixed>>}
     */
    public function options(User $user): array
    {
        $executive = $this->isExecutive($user);
        $permissions = array_values(array_filter(array_map(fn (object $row): ?array => $row->requires_executive && ! $executive ? null
            : ['code' => $row->permission_code, 'name' => $row->permission_name, 'note' => $row->note, 'max_days' => (int) $row->max_days], $this->enabledPolicy())));
        if ($permissions === []) {
            return ['permissions' => [], 'scopes' => []];
        }
        $roots = $executive
            ? DB::table('departments')->whereIn('node_type', ['office', 'department'])->where('is_active', true)->whereNull('deleted_at')->pluck('id')->map(fn ($id): int => (int) $id)->all()
            : app(StructureChanges::class)->headedDepartments($user);
        $scopes = [];
        foreach (DB::table('departments')->whereIn('id', $roots)->orderBy('name')->get(['id', 'name', 'node_type']) as $department) {
            $people = DB::table('users as u')->where('u.is_active', true)->where('u.id', '<>', $user->id)
                ->where(fn ($q) => $q->whereExists(fn ($e) => $e->from('employees')->whereColumn('employees.user_id', 'u.id')->where('employees.department_id', $department->id)->whereNull('employees.deleted_at'))
                    ->orWhereExists(fn ($r) => $r->from('department_user_roles')->whereColumn('department_user_roles.user_id', 'u.id')->where('department_user_roles.department_id', $department->id)->whereRaw(EffectiveAssignments::sql())))
                ->orderBy('u.name')->get(['u.id', 'u.name']);
            $scopes[] = ['id' => (int) $department->id, 'name' => $department->name, 'type' => OrganizationStructure::TYPES[$department->node_type] ?? '', 'people' => $people];
            foreach (DB::table('departments')->where('parent_id', $department->id)->where('node_type', 'unit')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) as $unit) {
                $members = DB::table('department_unit_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->join('users as u', 'u.id', '=', 'e.user_id')
                    ->where('a.unit_id', $unit->id)->where('a.status', 'active')->where('u.is_active', true)->where('u.id', '<>', $user->id)->orderBy('u.name')->get(['u.id', 'u.name']);
                $scopes[] = ['id' => (int) $unit->id, 'name' => $department->name.' ← '.$unit->name, 'type' => OrganizationStructure::TYPES['unit'], 'people' => $members];
            }
        }

        return ['permissions' => $permissions, 'scopes' => $scopes];
    }

    /** The executive director in office (Authority). */
    public function isExecutive(User $user): bool
    {
        return app(Authority::class)->isExecutive($user);
    }

    /** @return list<object> */
    private function activeFor(User $user): array
    {
        if (isset(self::$active[$user->id])) {
            return self::$active[$user->id];
        }
        $rows = DB::table('scoped_delegations as s')->join('users as d', 'd.id', '=', 's.delegator_user_id')->where('s.delegate_user_id', $user->id)
            ->where('s.status', 'active')->where('s.starts_at', '<=', now())->where('s.ends_at', '>', now())->where('d.is_active', true)->get(['s.*'])->all();
        self::$active[$user->id] = [];
        $kept = [];
        foreach ($rows as $row) {
            $delegator = User::find($row->delegator_user_id);
            if ($delegator && isset($this->enabledPolicy()[$row->permission_code]) && $this->holdsDirectly($delegator, $row->permission_code, (int) $row->scope_department_id)) {
                $kept[] = $row;
            }
        }

        return self::$active[$user->id] = $kept;
    }

    private function belongsTo(User $user, object $scope): bool
    {
        $department = $scope->node_type === 'unit' ? (int) $scope->parent_id : (int) $scope->id;
        $employee = DB::table('employees')->where('user_id', $user->id)->whereNull('deleted_at')->first(['id', 'department_id']);
        if ($scope->node_type === 'unit') {
            return $employee !== null && DB::table('department_unit_assignments')->where('unit_id', $scope->id)->where('employee_id', $employee->id)->where('status', 'active')->exists();
        }

        return ($employee && (int) $employee->department_id === $department)
            || EffectiveAssignments::apply(DB::table('department_user_roles')->where('user_id', $user->id)->where('department_id', $department))->exists();
    }

    private function isHeadOrExecutive(User $user): bool
    {
        return $this->isExecutive($user) || app(StructureChanges::class)->headedDepartments($user) !== [];
    }
}
