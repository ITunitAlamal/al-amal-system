<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\Organization\Authority;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who sees and handles official correspondence. A record is seen through the access rows its actions gave
 * (correspondence_access), by the Secretariat for its external archive, and by the system administrator for
 * technical oversight of ordinary («داخلي») records only: confidential and restricted records stay closed to the
 * technical role (owner decision 3, 2026-11-27) unless the executive director issued an exceptional, time-bound
 * grant for that one record (ConfidentialGrants). A grant opens the record for reading, limited to the actions its
 * issuer sees himself; it never counts when the
 * system decides who may hold, receive or be routed the record ($exceptional = false).
 */
final class CorrespondenceAccess
{
    private ?Collection $assignments = null;

    /** @var list<int>|null */
    private ?array $ordinary = null;

    private ?bool $grantsInstalled = null;

    private ?Collection $departments = null;

    private array $codes = [];

    private array $policyCache = [];

    private function code(int $id): ?string
    {
        if (! array_key_exists($id, $this->codes)) {
            $this->codes[$id] = DB::table('master_data_values')->where('id', $id)->value('code');
        }

        return $this->codes[$id];
    }

    public function assignments(): Collection
    {
        // Effective assignments only: within their dates and in an active, non-archived department (EffectiveAssignments).
        return $this->assignments ??= DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')->where('u.is_active', true)
            ->whereRaw(EffectiveAssignments::sql('a'))
            ->get(['a.*', 'r.code', 'u.department_id as home_department']);
    }

    public function departments(): Collection
    {
        return $this->departments ??= DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->get()->keyBy('id');
    }

    public function role(User $user, string $role, ?int $department = null): bool
    {
        if (! $user->is_active) {
            return false;
        }
        // The executive director's role counts only for the executive director in office (Organization\Authority).
        if ($role === Authority::EXECUTIVE_ROLE && ! app(Authority::class)->isExecutive($user)) {
            return false;
        }

        return $this->assignments()->contains(fn ($row): bool => (int) $row->user_id === (int) $user->id && $row->code === $role
            && ($department === null || (int) $row->department_id === $department));
    }

    public function canCreate(User $user): bool
    {
        return $this->departments()->contains(fn (object $department): bool => $this->member($user, (int) $department->id));
    }

    public function externalArchiveDepartmentId(): ?int
    {
        $department = $this->departments()->first(fn (object $department): bool => $department->code === 'SECRETARIAT'
            && $department->node_type === 'department' && $department->parent_id === null);

        return $department ? (int) $department->id : null;
    }

    public function canCreateExternalArchive(User $user): bool
    {
        $department = $this->externalArchiveDepartmentId();

        return $department !== null && $this->member($user, $department);
    }

    public function canReopen(User $user, object $letter): bool
    {
        return $letter->status === 'closed' && ! $letter->workflow_instance_id
            && (int) $letter->author_id !== (int) $user->id
            && $this->role($user, 'executive_director')
            && $this->visible($user, false)->where('c.id', $letter->id)->exists();
    }

    public function canReassign(User $user, object $letter, ?object $task): bool
    {
        if (! $task || $task->status !== 'open' || $letter->workflow_instance_id
            || ! in_array($letter->status, ['issued', 'processing'], true)
            || ! $this->actionVisible($user, (int) $task->action_id, false)) {
            return false;
        }
        $owner = User::find($task->assigned_to);
        $handoffActor = User::find(DB::table('correspondence_actions')->where('id', $task->action_id)->value('actor_id'));

        return $owner && $this->actionVisible($owner, (int) $task->action_id, false)
            && ($this->role($user, 'executive_director')
                || ($this->head($user, (int) $task->department_id)
                    && $handoffActor && ! $this->role($handoffActor, 'executive_director')
                    && ! $this->role(User::findOrFail($letter->author_id), 'executive_director')));
    }

    /**
     * The department a referral («إحالة») of this record is sent from, when the user may show the record to someone
     * who cannot see it yet (CorrespondenceRegistry::refer): a Secretariat member for incoming external mail the
     * Secretariat archived, or the head now executing a Board decision (stage «execute»), who hands work to people in
     * the department. Null otherwise.
     */
    public function referralSource(User $user, object $letter): ?int
    {
        if ($letter->channel === 'external') {
            $department = $this->externalArchiveDepartmentId();

            return $letter->direction === 'incoming' && $department !== null && (int) $letter->department_id === $department
                && $this->member($user, $department) ? $department : null;
        }
        if (! $letter->workflow_instance_id || in_array($letter->status, ['draft', 'closed', 'archived', 'withdrawn'], true)) {
            return null;
        }
        $step = DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.id', $letter->workflow_instance_id)->where('i.subject_type', 'official_correspondence')
            ->where('i.subject_id', $letter->id)->where('i.status', 'in_progress')->value('s.code');
        $task = $step === 'execute' ? DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first() : null;

        return $task && $task->status === 'open' && (int) $task->assigned_to === (int) $user->id
            && $this->actionVisible($user, (int) $task->action_id, false) && $this->head($user, (int) $task->department_id)
            ? (int) $task->department_id : null;
    }

    public function member(User $user, int $department): bool
    {
        return $user->is_active && $this->departments()->has($department)
            && $this->assignments()->contains(fn ($row): bool => (int) $row->user_id === (int) $user->id && $row->code !== 'system_admin'
                && ((int) $row->department_id === $department || ($row->department_id === null && (int) $row->home_department === $department)));
    }

    public function head(User $user, int $department): bool
    {
        // The appointed head (Organization\Authority), or a dated acting head until the owner decides that rule (RBAC-02).
        return app(Authority::class)->isHeadOf($user, $department)
            || $this->assignments()->contains(fn ($row): bool => (int) $row->user_id === (int) $user->id && (int) $row->department_id === $department
                && $row->code === 'acting_department_head' && $row->starts_on !== null && $row->ends_on !== null);
    }

    public function within(int $department, int $ancestor): bool
    {
        $seen = [];
        while ($this->departments()->has($department) && ! isset($seen[$department])) {
            if ($department === $ancestor) {
                return true;
            }
            $seen[$department] = true;
            $department = (int) $this->departments()->get($department)->parent_id;
        }

        return false;
    }

    public function directHead(User $recipient, int $department): bool
    {
        $seen = [];
        while ($this->departments()->has($department) && ! isset($seen[$department])) {
            $seen[$department] = true;
            if ($this->head($recipient, $department)) {
                return true;
            }
            if ($this->departments()->get($department)->head_user_id !== null) {
                return false;
            }
            $department = (int) $this->departments()->get($department)->parent_id;
        }

        return false;
    }

    public function policies(string $kind): Collection
    {
        return $this->policyCache[$kind] ??= DB::table('correspondence_policies')->where('kind', $kind)->where('starts_on', '<=', today()->toDateString())
            ->orderByDesc('version')->get()->unique('policy_key')->filter(fn ($row): bool => $row->is_active && ($row->ends_on === null || $row->ends_on >= today()->toDateString()));
    }

    public function settings(): array
    {
        $row = $this->policies('settings')->first();
        abort_unless($row, 503, 'سياسة المراسلات غير مهيأة.');

        return [...json_decode($row->configuration, true), 'policy_version' => $row->version];
    }

    public function canRoute(User $sender, int $source, User $recipient, int $destination, int $type, int $classification, bool $issuing = true): bool
    {
        if ($sender->id === $recipient->id || ! $this->member($sender, $source) || ! $this->member($recipient, $destination)) {
            return false;
        }
        $typeCode = $this->code($type);
        $executive = $this->role($sender, 'executive_director');
        $targetExecutive = $this->role($recipient, 'executive_director');
        $secretariat = $this->role($sender, 'secretariat');
        $targetSecretariat = $this->role($recipient, 'secretariat');
        $head = $this->head($sender, $source);
        $targetHead = $this->head($recipient, $destination);
        if ($issuing && in_array($typeCode, ['direction', 'circular', 'report_request', 'task_assignment'], true) && ! $executive) {
            return false;
        }
        if ($typeCode === 'board_transmission') {
            // The board secretary issues a decision to the Secretariat only; at closure they may return it to the
            // executive director for follow-up (BOARD_CORRESPONDENCE «return_followup»).
            return ($this->role($sender, 'board_secretary') && ($targetSecretariat || (! $issuing && $targetExecutive)))
                || ($secretariat && $targetExecutive)
                || ($executive && ($targetHead || $targetSecretariat)) || ($head && $targetExecutive)
                || ($secretariat && $this->role($recipient, 'board_secretary'));
        }
        if ($executive) {
            return $targetHead || $targetSecretariat || $source === $destination;
        }
        if ($secretariat) {
            return $targetExecutive || $targetHead || $source === $destination;
        }
        if ($head && ($targetExecutive || $targetSecretariat)) {
            return true;
        }
        if ($head && $targetHead && $source !== $destination) {
            $classCode = $this->code($classification);
            if ($classCode !== 'internal') {
                return false;
            }

            return $this->policies('cross_head')->contains(function ($row) use ($source, $destination, $type, $classification): bool {
                $policy = json_decode($row->configuration, true);

                return filled($row->approval_reference) && (int) $policy['from_department_id'] === $source && (int) $policy['to_department_id'] === $destination
                    && (int) $policy['type_value_id'] === $type && (int) $policy['classification_value_id'] === $classification;
            });
        }
        if ($targetExecutive) {
            return false;
        }
        if ($source === $destination) {
            return true;
        }

        return ($head && ! $targetHead && $this->within($destination, $source)) || (! $head && $this->directHead($recipient, $source));
    }

    public function authority(User $user, int $department): array
    {
        $role = $this->role($user, 'executive_director') ? 'executive_director'
            : ($this->head($user, $department) ? 'head' : ($this->role($user, 'secretariat') ? 'secretariat' : ($this->role($user, 'board_secretary') ? 'board_secretary' : 'staff')));
        $until = $role === 'head' ? $this->assignments()->first(fn ($row): bool => (int) $row->user_id === (int) $user->id && (int) $row->department_id === $department && $row->code === 'acting_department_head')?->ends_on : null;

        return ['authority_role' => $role, 'valid_until' => $until];
    }

    public function accessQuery(User $user): Builder
    {
        $query = DB::table('correspondence_access as access')->where('access.user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('access.valid_until')->orWhere('access.valid_until', '>=', today()->toDateString()));

        return $query->where(function (Builder $query) use ($user): void {
            $query->whereRaw('1 = 0');
            foreach ($this->departments() as $department) {
                if (! $this->member($user, (int) $department->id)) {
                    continue;
                }
                $roles = ['staff'];
                if ($this->head($user, (int) $department->id)) {
                    $roles[] = 'head';
                }
                foreach (['executive_director', 'secretariat', 'board_secretary'] as $role) {
                    if ($this->role($user, $role)) {
                        $roles[] = $role;
                    }
                }
                $query->orWhere(fn ($q) => $q->where('access.department_id', $department->id)->whereIn('access.authority_role', $roles));
            }
        });
    }

    /**
     * Classification values open to the system administrator's technical oversight: «داخلي» only. Every other
     * level («سري», «مقيد», or one added later) is confidential.
     *
     * @return list<int>
     */
    public function ordinaryClassifications(): array
    {
        return $this->ordinary ??= DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'correspondence_classifications')->where('v.code', 'internal')
            ->pluck('v.id')->map(fn ($id): int => (int) $id)->all();
    }

    public function isConfidential(object $letter): bool
    {
        return ! in_array((int) $letter->classification_value_id, $this->ordinaryClassifications(), true);
    }

    /**
     * The user's exceptional grants in force (ConfidentialGrants): not revoked, not past their end, and issued by
     * someone who still holds the executive director's office. Null until the grants table is installed.
     */
    public function grants(User $user): ?Builder
    {
        $this->grantsInstalled ??= Schema::hasTable('correspondence_confidential_grants');
        if (! $this->grantsInstalled || ! $user->is_active) {
            return null;
        }

        return DB::table('correspondence_confidential_grants as g')->where('g.user_id', $user->id)
            ->whereNull('g.revoked_at')->where('g.ends_at', '>', now())
            ->whereIn('g.issued_by', app(Authority::class)->executiveIds());
    }

    /** The grant in force that gives this user the record, or null. */
    public function grantFor(User $user, int $letter): ?int
    {
        $id = $this->grants($user)?->where('g.correspondence_id', $letter)->orderByDesc('g.id')->value('g.id');

        return $id ? (int) $id : null;
    }

    /**
     * The action ids a grant opens: what its issuer sees of the record himself, never more (a delegate cannot
     * receive more than the delegator holds). Null when no grant in force covers the record.
     */
    private function grantedActions(User $user, object $letter): ?Builder
    {
        $issuerId = $this->grants($user)?->where('g.correspondence_id', $letter->id)->orderByDesc('g.id')->value('g.issued_by');
        $issuer = $issuerId ? User::query()->find((int) $issuerId) : null;

        return $issuer ? $this->visibleActions($issuer, $letter, false)->select('a.id') : null;
    }

    /**
     * The grant the user reads this record through: a confidential record they would not see otherwise. The
     * caller audits each use.
     */
    public function grantInUse(User $user, object $letter): ?int
    {
        if (! $this->isConfidential($letter)) {
            return null;
        }
        $grant = $this->grantFor($user, (int) $letter->id);

        return $grant !== null && ! $this->visible($user, false)->where('c.id', $letter->id)->exists() ? $grant : null;
    }

    /**
     * The records the user may open. $exceptional = false leaves out the executive director's exceptional grants,
     * for every check that decides holding, routing or widening the circulation of a record.
     */
    public function visible(User $user, bool $exceptional = true): Builder
    {
        $query = DB::table('official_correspondences as c');
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $department = $this->externalArchiveDepartmentId();
        $technical = $user->isSystemAdmin();
        $granted = $exceptional ? $this->grants($user) : null;

        return $query->where(function (Builder $visible) use ($user, $department, $technical, $granted): void {
            $visible->whereExists($this->accessQuery($user)->join('correspondence_actions as permitted', 'permitted.id', '=', 'access.action_id')
                ->whereColumn('permitted.correspondence_id', 'c.id')->selectRaw('1'));
            if ($department !== null && $this->member($user, $department)) {
                $visible->orWhere(fn (Builder $external) => $external->where('c.channel', 'external')->where('c.department_id', $department));
            }
            if ($technical) {
                $visible->orWhereIn('c.classification_value_id', $this->ordinaryClassifications());
            }
            if ($granted !== null) {
                $visible->orWhereIn('c.id', $granted->select('g.correspondence_id'));
            }
        });
    }

    public function actionVisible(User $user, int $action, bool $exceptional = true): bool
    {
        if (! $user->is_active) {
            return false;
        }
        if ($this->accessQuery($user)->where('access.action_id', $action)->exists()) {
            return true;
        }
        $letter = DB::table('correspondence_actions as a')->join('official_correspondences as c', 'c.id', '=', 'a.correspondence_id')
            ->where('a.id', $action)->first(['c.id', 'c.channel', 'c.department_id', 'c.classification_value_id']);
        if (! $letter) {
            return false;
        }
        $department = $this->externalArchiveDepartmentId();
        if (($department !== null && $letter->channel === 'external' && (int) $letter->department_id === $department && $this->member($user, $department))
            || ($user->isSystemAdmin() && ! $this->isConfidential($letter))) {
            return true;
        }
        $granted = $exceptional ? $this->grantedActions($user, $letter) : null;

        return $granted !== null && $granted->where('a.id', $action)->exists();
    }

    /** The record's actions the user may read; $exceptional = false leaves out the exceptional grants. */
    public function visibleActions(User $user, object $letter, bool $exceptional = true): Builder
    {
        $query = DB::table('correspondence_actions as a')->where('a.correspondence_id', $letter->id);
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isSystemAdmin() && ! $this->isConfidential($letter)) {
            return $query;
        }
        $granted = $exceptional ? $this->grantedActions($user, $letter) : null;

        return $query->where(function (Builder $visible) use ($user, $letter, $granted): void {
            $visible->whereIn('a.id', $this->accessQuery($user)->select('access.action_id'));
            $department = $letter->channel === 'external' ? $this->externalArchiveDepartmentId() : null;
            if ($department !== null && (int) $letter->department_id === $department && $this->member($user, $department)) {
                $visible->orWhere('a.correspondence_id', $letter->id);
            }
            if ($granted !== null) {
                $visible->orWhereIn('a.id', $granted);
            }
        });
    }

    /** Mailbox scopes always start with current record authorization, including technical users. */
    public function mailbox(User $user, string $box): Builder
    {
        $query = $this->visible($user);
        if ($box === 'inbox') {
            $query->whereExists(DB::table('correspondence_actions as incoming')->whereColumn('incoming.correspondence_id', 'c.id')
                ->where('incoming.recipient_id', $user->id)->whereIn('incoming.id', $this->accessQuery($user)->select('access.action_id'))->selectRaw('1'));
        } elseif (in_array($box, ['needs_action', 'overdue', 'returned'], true)) {
            $query->whereExists(DB::table('correspondence_tasks as pending')->whereColumn('pending.correspondence_id', 'c.id')
                ->where('pending.assigned_to', $user->id)->where('pending.status', 'open')
                ->when($box === 'overdue', fn (Builder $pending) => $pending->where('pending.due_at', '<', now()))
                ->when($box === 'returned', fn (Builder $pending) => $pending->whereIn('pending.action_id', DB::table('correspondence_actions')->whereIn('action', ['return', 'refuse'])->select('id')))
                ->whereIn('pending.action_id', $this->accessQuery($user)->select('access.action_id'))->selectRaw('1'));
        } elseif ($box === 'forwarded') {
            $query->whereExists(DB::table('correspondence_actions as forwarded')->whereColumn('forwarded.correspondence_id', 'c.id')
                ->where('forwarded.actor_id', $user->id)->whereIn('forwarded.action', ['forward', 'reassign', 'refer'])
                ->whereIn('forwarded.id', $this->accessQuery($user)->select('access.action_id'))->selectRaw('1'));
        } elseif ($box === 'sent') {
            $query->where('c.status', '<>', 'draft')->where(function (Builder $sent) use ($user): void {
                $sent->where('c.author_id', $user->id)->orWhereExists(DB::table('correspondence_actions as outgoing')
                    ->whereColumn('outgoing.correspondence_id', 'c.id')->where('outgoing.actor_id', $user->id)
                    ->whereNotNull('outgoing.recipient_id')->whereIn('outgoing.id', $this->accessQuery($user)->select('access.action_id'))->selectRaw('1'));
            });
        } elseif ($box === 'drafts') {
            $query->where('c.author_id', $user->id)->where('c.status', 'draft');
        } elseif ($box === 'in_progress') {
            $query->whereIn('c.status', ['issued', 'processing', 'processed']);
        } elseif ($box === 'archive') {
            $query->whereIn('c.status', ['closed', 'archived', 'withdrawn']);
        }

        return $query;
    }
}
