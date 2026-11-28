<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;

/**
 * The two structural authorities, defined once. Every check of "is this the executive director" or "is this the
 * head of that department" reads here, so a handover, an end-dated appointment or a stopped department changes
 * every module at the same moment.
 *
 * - Executive director: the user in departments.head_user_id of «الإدارة التنفيذية» (code EXEC, active, not
 *   archived), on an active account, holding an effective executive_director assignment in EXEC itself. The role
 *   held in another department, kept after a new appointment, or given to an acting holder without the seat, is not
 *   the executive director (the seat rule Accounts and the campaign lists already applied; whether an acting holder
 *   may decide anything is the owner's open decision).
 * - Department head: the user in departments.head_user_id of an active, non-archived department, on an active
 *   account, holding an effective department_head assignment in that same department. head_user_id alone is not
 *   enough. The dated acting-head rules of the orphans and correspondence modules stay theirs until the owner
 *   decides (RBAC-02).
 *
 * Assignment validity is EffectiveAssignments. Answers are kept for the request (scoped instance) and dropped
 * whenever a database transaction commits.
 */
#[Scoped]
final class Authority
{
    public const EXECUTIVE_OFFICE = 'EXEC';

    public const EXECUTIVE_ROLE = 'executive_director';

    public const HEAD_ROLE = 'department_head';

    /** @var list<int>|null */
    private ?array $executives = null;

    /** @var array<int, int>|null department id => head user id */
    private ?array $heads = null;

    public function __construct(Dispatcher $events)
    {
        // An appointment, role or structure change committed during this request (then a reroute) is read fresh.
        $events->listen(TransactionCommitted::class, fn () => $this->forget());
    }

    /** Drops the answers kept for this request. */
    public function forget(): void
    {
        $this->executives = null;
        $this->heads = null;
    }

    /** @return list<int> the executive director(s) in office; more than one only by a data error */
    public function executiveIds(): array
    {
        return $this->executives ??= DB::table('departments as d')->join('users as u', 'u.id', '=', 'd.head_user_id')
            ->where('d.code', self::EXECUTIVE_OFFICE)->where('d.is_active', true)->whereNull('d.deleted_at')->where('u.is_active', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('a.department_id', 'd.id')->whereColumn('a.user_id', 'd.head_user_id')->where('r.code', self::EXECUTIVE_ROLE)
                ->whereRaw(EffectiveAssignments::sql('a')))
            ->distinct()->orderBy('u.id')->pluck('u.id')->map(fn ($id): int => (int) $id)->all();
    }

    /** The one executive director in office, or null when there is none (or, by a data error, more than one). */
    public function executiveId(): ?int
    {
        $ids = $this->executiveIds();

        return count($ids) === 1 ? $ids[0] : null;
    }

    public function isExecutive(User|int|null $user): bool
    {
        $id = $this->id($user);

        return $id !== null && in_array($id, $this->executiveIds(), true);
    }

    /** The valid head of the department, or null (no seat, no head role there, inactive account or department). */
    public function headId(?int $departmentId): ?int
    {
        return $departmentId === null ? null : ($this->heads()[$departmentId] ?? null);
    }

    public function isHeadOf(User|int|null $user, ?int $departmentId): bool
    {
        $id = $this->id($user);

        return $id !== null && $departmentId !== null && $this->headId($departmentId) === $id;
    }

    /** @return list<int> the departments (and units) the user validly heads; one person may head several */
    public function headedDepartmentIds(User|int|null $user): array
    {
        $id = $this->id($user);

        return $id === null ? [] : array_values(array_map('intval', array_keys($this->heads(), $id, true)));
    }

    public function isHead(User|int|null $user): bool
    {
        return $this->headedDepartmentIds($user) !== [];
    }

    /** @return array<int, int> */
    private function heads(): array
    {
        return $this->heads ??= DB::table('departments as d')->join('users as u', 'u.id', '=', 'd.head_user_id')
            ->where('d.is_active', true)->whereNull('d.deleted_at')->where('u.is_active', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('a.department_id', 'd.id')->whereColumn('a.user_id', 'd.head_user_id')->where('r.code', self::HEAD_ROLE)
                ->whereRaw(EffectiveAssignments::sql('a')))
            ->orderBy('d.id')->pluck('d.head_user_id', 'd.id')
            ->mapWithKeys(fn ($userId, $departmentId): array => [(int) $departmentId => (int) $userId])->all();
    }

    private function id(User|int|null $user): ?int
    {
        if ($user instanceof User) {
            return $user->is_active ? (int) $user->id : null;
        }

        return $user === null || $user <= 0 ? null : $user;
    }
}
