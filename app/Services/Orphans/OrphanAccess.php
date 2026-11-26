<?php

namespace App\Services\Orphans;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may do what in «شؤون الأيتام والكفالات». Every route checks here; a hidden button is never the control.
 *
 * - Department staff: users placed in the ORPHANS department or one of its units, through an active role
 *   assignment or their home department. Staff read and maintain the registry; extra powers are explicit
 *   capabilities the head grants (orphan_staff_capabilities).
 * - The head (appointed, or acting within valid dates) holds every capability.
 * - The executive director reads everything a decision needs and is the ONLY approver of campaign lists.
 * - The system admin has full technical access, audited, but never takes an executive decision.
 */
final class OrphanAccess
{
    public const CAPABILITIES = [
        'identity' => 'عرض أرقام الهوية كاملة',
        'health' => 'عرض التفاصيل الصحية السريرية',
        'portal' => 'مراجعة طلبات البوابة واعتماد الوصاية',
        'sponsorships' => 'الكفلاء والكفالات والمساعدات',
        'campaigns' => 'إعداد الحملات والكشوف',
        'delivery' => 'تسجيل التسليم الميداني',
        'import' => 'الاستيراد الذكي',
        'export' => 'التقارير والتصدير',
    ];

    /** @var array<int, array<string, bool>> */
    private array $cache = [];

    public function departmentId(): ?int
    {
        return once(fn () => DB::table('departments')->where('code', 'ORPHANS')->where('is_active', true)->whereNull('deleted_at')->value('id'));
    }

    /** The department and all its active descendant units. @return list<int> */
    public function departmentTree(): array
    {
        return once(function (): array {
            $root = $this->departmentId();
            if (! $root) {
                return [];
            }
            $ids = [$root];
            $frontier = [$root];
            while ($frontier !== []) {
                $frontier = DB::table('departments')->whereIn('parent_id', $frontier)->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
                $ids = [...$ids, ...$frontier];
            }

            return $ids;
        });
    }

    public function isExecutive(?User $user): bool
    {
        return $this->remember($user, 'executive', fn (User $u): bool => DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $u->id)->where('r.code', 'executive_director')
            ->where(fn ($q) => $q->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', today()->toDateString()))->exists());
    }

    public function isHead(?User $user): bool
    {
        return $this->remember($user, 'head', function (User $u): bool {
            $department = $this->departmentId();
            if (! $department) {
                return false;
            }
            $today = today()->toDateString();
            $roles = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.user_id', $u->id)->where('a.department_id', $department)->get(['r.code', 'a.starts_on', 'a.ends_on']);
            $appointed = (int) DB::table('departments')->where('id', $department)->value('head_user_id') === (int) $u->id
                && $roles->contains(fn ($row): bool => $row->code === 'department_head');
            $acting = $roles->contains(fn ($row): bool => $row->code === 'acting_department_head' && $row->starts_on && $row->ends_on
                && $row->starts_on <= $today && $row->ends_on >= $today);

            return $appointed || $acting;
        });
    }

    public function isStaff(?User $user): bool
    {
        return $this->remember($user, 'staff', function (User $u): bool {
            $tree = $this->departmentTree();
            if ($tree === []) {
                return false;
            }

            return in_array((int) $u->department_id, $tree, true) || DB::table('department_user_roles')->where('user_id', $u->id)->whereIn('department_id', $tree)
                ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', today()->toDateString()))
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()))->exists();
        });
    }

    /** May open the department's registry and work queues. */
    public function canView(?User $user): bool
    {
        return $user !== null && $user->is_active && ($user->isSystemAdmin() || $this->isExecutive($user) || $this->isHead($user) || $this->isStaff($user));
    }

    /** May create and update registry records (the executive director reads, the department edits). */
    public function canEdit(?User $user): bool
    {
        return $user !== null && $user->is_active && ($user->isSystemAdmin() || $this->isHead($user) || $this->isStaff($user));
    }

    public function allows(?User $user, string $capability): bool
    {
        if (! $user || ! $user->is_active || ! array_key_exists($capability, self::CAPABILITIES)) {
            return false;
        }
        if ($user->isSystemAdmin() || $this->isHead($user)) {
            return true;
        }
        if ($this->isExecutive($user)) {
            return in_array($capability, ['identity', 'export'], true);
        }
        if (! $this->isStaff($user)) {
            return false;
        }

        return $this->remember($user, 'cap:'.$capability, fn (User $u): bool => DB::table('orphan_staff_capabilities')->where('user_id', $u->id)
            ->where('capability', $capability)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()))->exists());
    }

    /** Campaign pages: campaign or delivery staff, the head, the executive director, or a valid per-campaign assignment. */
    public function canOpenCampaigns(?User $user): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }
        if ($user->isSystemAdmin() || $this->isHead($user) || $this->isExecutive($user) || $this->allows($user, 'campaigns') || $this->allows($user, 'delivery')) {
            return true;
        }

        return RegistryQueries::hasTable('campaign_members') && DB::table('campaign_members')->where('user_id', $user->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()->toDateString()))->exists();
    }

    public function canManageSettings(?User $user): bool
    {
        return $user !== null && $user->is_active && ($user->isSystemAdmin() || $this->isHead($user) || $this->isExecutive($user));
    }

    public function canAssign(?User $user): bool
    {
        return $user !== null && $user->is_active && ($user->isSystemAdmin() || $this->isHead($user));
    }

    public function assertView(?User $user): void
    {
        abort_unless($this->canView($user), 403, 'هذه الصفحة لموظفي قسم شؤون الأيتام والكفالات ومن يملك صلاحية عليه.');
    }

    public function assertEdit(?User $user): void
    {
        abort_unless($this->canEdit($user), 403, 'تعديل السجل لموظفي القسم ورئيسه فقط.');
    }

    public function assert(?User $user, string $capability): void
    {
        abort_unless($this->allows($user, $capability), 403, 'تحتاج إلى قدرة «'.(self::CAPABILITIES[$capability] ?? $capability).'» يمنحها رئيس القسم.');
    }

    /** Active staff of the department (for assignment pickers), heads included. @return list<array{id: int, name: string}> */
    public function staff(): array
    {
        $tree = $this->departmentTree();
        if ($tree === []) {
            return [];
        }
        $ids = DB::table('department_user_roles')->whereIn('department_id', $tree)->pluck('user_id')
            ->merge(DB::table('users')->whereIn('department_id', $tree)->pluck('id'))
            ->push(DB::table('departments')->where('id', $this->departmentId())->value('head_user_id'))->filter()->unique();

        return DB::table('users')->whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u): array => ['id' => (int) $u->id, 'name' => $u->name])->all();
    }

    /** @return list<int> */
    public function headUserIds(): array
    {
        $department = $this->departmentId();
        if (! $department) {
            return [];
        }
        $candidates = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.department_id', $department)
            ->whereIn('r.code', ['department_head', 'acting_department_head'])->pluck('a.user_id')
            ->push(DB::table('departments')->where('id', $department)->value('head_user_id'))->filter()->unique();

        return User::query()->whereIn('id', $candidates)->get()->filter(fn (User $u): bool => $this->isHead($u))->map(fn (User $u): int => (int) $u->id)->values()->all();
    }

    /** @return list<int> */
    public function executiveUserIds(): array
    {
        return DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', 'executive_director')->where('u.is_active', true)->pluck('a.user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Logs a read of a protected record (who, what, when), never its content. */
    public function logRead(User $user, string $subjectType, int $subjectId, string $action = 'viewed'): void
    {
        DB::table('orphan_access_log')->insert(['user_id' => $user->id, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'ip_address' => request()?->ip(), 'created_at' => now()]);
    }

    /** The capability map the pages use to show or hide actions (the server checks again on every action). */
    public function abilities(User $user): array
    {
        return [
            'view' => $this->canView($user), 'edit' => $this->canEdit($user), 'assign' => $this->canAssign($user),
            'settings' => $this->canManageSettings($user), 'head' => $this->isHead($user), 'executive' => $this->isExecutive($user),
            ...array_combine(array_keys(self::CAPABILITIES), array_map(fn (string $c): bool => $this->allows($user, $c), array_keys(self::CAPABILITIES))),
        ];
    }

    private function remember(?User $user, string $key, callable $resolve): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }

        return $this->cache[$user->id][$key] ??= (bool) $resolve($user);
    }
}
