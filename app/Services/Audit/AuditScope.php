<?php

namespace App\Services\Audit;

use App\Models\User;
use App\Services\Organization\EffectiveAssignments;
use App\Services\Workflow\ApprovalDelegations;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who may read «سجل النظام», and which rows. Decided on the server for every list, detail, export and history call;
 * filters sent by the browser only narrow this scope, never widen it.
 *
 * - all: system administrator and executive director (technical access never grants business approvals).
 * - department: an appointed department head sees events of staff in his department and its units, without
 *   restricted events (health, identity documents, payroll) and without guardian/system rows.
 * - none: everyone else (beneficiaries never reach this log; they see their own request history in the portal).
 */
final class AuditScope
{
    /** @var array<int, array{level: string, departments: list<int>}> */
    private array $resolved = [];

    /** @return array{level: 'all'|'department'|'none', departments: list<int>} */
    public function for(?User $user): array
    {
        if (! $user?->is_active) {
            return ['level' => 'none', 'departments' => []];
        }

        return $this->resolved[(int) $user->id] ??= $this->resolve($user);
    }

    public function allows(?User $user): bool
    {
        return $this->for($user)['level'] !== 'none';
    }

    public function isFull(?User $user): bool
    {
        return $this->for($user)['level'] === 'all';
    }

    /** Applies the scope to a query on audit_logs aliased as `a`. */
    public function apply(Builder $query, User $user): Builder
    {
        $scope = $this->for($user);
        if ($scope['level'] === 'all') {
            return $query;
        }
        if ($scope['level'] === 'none') {
            return $query->whereRaw('false');
        }

        return $query->whereIn('a.department_id', $scope['departments'] ?: [0])->where('a.sensitivity', '<>', 'restricted')->where('a.actor_type', 'user');
    }

    private function resolve(User $user): array
    {
        if ($user->isSystemAdmin() || app(ApprovalDelegations::class)->isExecutive($user)) {
            return ['level' => 'all', 'departments' => []];
        }
        // Appointed head with an effective «رئيس قسم» assignment there; an ended appointment no longer opens the log.
        $heads = DB::table('departments as d')->join('department_user_roles as r', fn ($join) => $join->on('r.department_id', '=', 'd.id')->where('r.user_id', $user->id))
            ->join('roles as role', 'role.id', '=', 'r.role_id')->where('role.code', 'department_head')->whereRaw(EffectiveAssignments::sql('r'))
            ->where('d.head_user_id', $user->id)->where('d.is_active', true)->whereNull('d.deleted_at')
            ->distinct()->pluck('d.id')->map(fn ($id): int => (int) $id)->all();
        if ($heads === []) {
            return ['level' => 'none', 'departments' => []];
        }
        $ids = $heads;
        for ($frontier = $heads; $frontier !== [];) {
            $frontier = DB::table('departments')->whereIn('parent_id', $frontier)->whereNull('deleted_at')->pluck('id')->map(fn ($id): int => (int) $id)->diff($ids)->values()->all();
            $ids = [...$ids, ...$frontier];
        }

        return ['level' => 'department', 'departments' => $ids];
    }
}
