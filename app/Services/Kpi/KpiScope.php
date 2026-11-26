<?php

namespace App\Services\Kpi;

use App\Models\User;
use App\Services\Organization\ScopedDelegations;
use Illuminate\Support\Facades\DB;

/**
 * Who the viewer answers for, resolved from the live organization on every request (never cached):
 * - institution-wide: the system administrator, the executive director, or a role holding kpi.view
 *   assigned without a department;
 * - otherwise the departments they head (departments.head_user_id) or hold a kpi.view role in,
 *   with their active sub-units.
 * Approval delegations grant decisions, not data access, so they add nothing here.
 */
final class KpiScope
{
    public const PERMISSION = 'kpi.view';

    /**
     * @param  list<int>  $departments  departments the viewer answers for, with sub-units (every active one when institution-wide)
     * @param  list<string>  $roles
     * @param  array<int, int|null>  $tree  active department id => parent id
     */
    private function __construct(
        public readonly int $userId,
        public readonly bool $institutional,
        public readonly bool $administrator,
        public readonly array $departments,
        public readonly array $roles,
        private readonly array $tree,
    ) {}

    /**
     * @param  bool  $includeDelegations  also the departments delegated to the user for a limited time (ScopedDelegations);
     *                                    false when checking what the user holds in their own right
     */
    public static function resolve(User $user, bool $includeDelegations = true): ?self
    {
        if (! $user->is_active) {
            return null;
        }
        $assignments = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $user->id)->get(['a.department_id', 'r.code']);
        $kpiRoles = DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->join('roles as r', 'r.id', '=', 'pr.role_id')->where('p.code', self::PERMISSION)->pluck('r.code')->all();

        $administrator = $assignments->contains(fn (object $a): bool => $a->code === 'system_admin' && $a->department_id === null);
        $institutional = $administrator
            || $assignments->contains(fn (object $a): bool => $a->code === 'executive_director')
            || $assignments->contains(fn (object $a): bool => $a->department_id === null && in_array($a->code, $kpiRoles, true));

        $tree = DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->pluck('parent_id', 'id')
            ->mapWithKeys(fn ($parent, $id): array => [(int) $id => $parent === null ? null : (int) $parent])->all();

        if ($institutional) {
            $departments = array_keys($tree);
        } else {
            $direct = DB::table('departments')->where('head_user_id', $user->id)->where('is_active', true)->whereNull('deleted_at')
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            foreach ($assignments as $assignment) {
                if ($assignment->department_id !== null && in_array($assignment->code, $kpiRoles, true)) {
                    $direct[] = (int) $assignment->department_id;
                }
            }
            if ($includeDelegations) {
                array_push($direct, ...app(ScopedDelegations::class)->delegatedDepartments($user, self::PERMISSION));
            }
            $departments = self::withDescendants(array_values(array_intersect(array_unique($direct), array_keys($tree))), $tree);
        }
        sort($departments);
        if (! $institutional && $departments === []) {
            return null;
        }

        return new self((int) $user->id, $institutional, $administrator, array_values(array_unique($departments)),
            $assignments->pluck('code')->unique()->values()->all(), $tree);
    }

    public static function forUser(User $user): self
    {
        return self::resolve($user) ?? abort(403, 'لا يوجد نطاق مؤشرات أداء مخوّل لهذا الحساب.');
    }

    /** The institution-wide scope used by scheduled readings (no user, nothing shown on screen). */
    public static function system(): self
    {
        $tree = DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->pluck('parent_id', 'id')
            ->mapWithKeys(fn ($parent, $id): array => [(int) $id => $parent === null ? null : (int) $parent])->all();
        $departments = array_keys($tree);
        sort($departments);

        return new self(0, true, true, $departments, [], $tree);
    }

    public function covers(int $departmentId): bool
    {
        return in_array($departmentId, $this->departments, true);
    }

    /**
     * A department the viewer answers for and its active sub-units.
     *
     * @return list<int>
     */
    public function subtree(int $departmentId): array
    {
        return $this->covers($departmentId) ? self::withDescendants([$departmentId], $this->tree) : [];
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->institutional, $this->administrator, $this->departments, $this->roles]));
    }

    /**
     * @param  list<int>  $roots
     * @param  array<int, int|null>  $tree
     * @return list<int>
     */
    private static function withDescendants(array $roots, array $tree): array
    {
        $found = $roots;
        do {
            $added = false;
            foreach ($tree as $id => $parent) {
                if ($parent !== null && in_array($parent, $found, true) && ! in_array($id, $found, true)) {
                    $found[] = $id;
                    $added = true;
                }
            }
        } while ($added);
        sort($found);

        return $found;
    }
}
