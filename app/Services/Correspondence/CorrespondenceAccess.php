<?php

namespace App\Services\Correspondence;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CorrespondenceAccess
{
    private ?Collection $assignments = null;

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
        return $this->assignments ??= DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')->where('u.is_active', true)
            ->where(fn ($q) => $q->whereNull('a.starts_on')->orWhere('a.starts_on', '<=', today()->toDateString()))
            ->where(fn ($q) => $q->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', today()->toDateString()))
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
            && $this->visible($user)->where('c.id', $letter->id)->exists();
    }

    public function canReassign(User $user, object $letter, ?object $task): bool
    {
        if (! $task || $task->status !== 'open' || $letter->workflow_instance_id
            || ! in_array($letter->status, ['issued', 'processing'], true)
            || ! $this->actionVisible($user, (int) $task->action_id)) {
            return false;
        }
        $owner = User::find($task->assigned_to);
        $handoffActor = User::find(DB::table('correspondence_actions')->where('id', $task->action_id)->value('actor_id'));

        return $owner && $this->actionVisible($owner, (int) $task->action_id)
            && ($this->role($user, 'executive_director')
                || ($this->head($user, (int) $task->department_id)
                    && $handoffActor && ! $this->role($handoffActor, 'executive_director')
                    && ! $this->role(User::findOrFail($letter->author_id), 'executive_director')));
    }

    public function member(User $user, int $department): bool
    {
        return $user->is_active && $this->departments()->has($department)
            && $this->assignments()->contains(fn ($row): bool => (int) $row->user_id === (int) $user->id && $row->code !== 'system_admin'
                && ((int) $row->department_id === $department || ($row->department_id === null && (int) $row->home_department === $department)));
    }

    public function head(User $user, int $department): bool
    {
        return ((int) $this->departments()->get($department)?->head_user_id === (int) $user->id && $this->role($user, 'department_head', $department))
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
            return ($this->role($sender, 'board_secretary') && $targetSecretariat) || ($secretariat && $targetExecutive)
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

    public function visible(User $user): Builder
    {
        $query = DB::table('official_correspondences as c');
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isSystemAdmin()) {
            return $query;
        }

        $department = $this->externalArchiveDepartmentId();

        return $query->where(function (Builder $visible) use ($user, $department): void {
            $visible->whereExists($this->accessQuery($user)->join('correspondence_actions as permitted', 'permitted.id', '=', 'access.action_id')
                ->whereColumn('permitted.correspondence_id', 'c.id')->selectRaw('1'));
            if ($department !== null && $this->member($user, $department)) {
                $visible->orWhere(fn (Builder $external) => $external->where('c.channel', 'external')->where('c.department_id', $department));
            }
        });
    }

    public function actionVisible(User $user, int $action): bool
    {
        if (! $user->is_active) {
            return false;
        }
        if ($user->isSystemAdmin() || $this->accessQuery($user)->where('access.action_id', $action)->exists()) {
            return true;
        }
        $department = $this->externalArchiveDepartmentId();

        return $department !== null && $this->member($user, $department)
            && DB::table('correspondence_actions as a')->join('official_correspondences as c', 'c.id', '=', 'a.correspondence_id')
                ->where('a.id', $action)->where('c.channel', 'external')->where('c.department_id', $department)->exists();
    }

    public function visibleActions(User $user, object $letter): Builder
    {
        $query = DB::table('correspondence_actions as a')->where('a.correspondence_id', $letter->id);
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isSystemAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user, $letter): void {
            $visible->whereIn('a.id', $this->accessQuery($user)->select('access.action_id'));
            $department = $letter->channel === 'external' ? $this->externalArchiveDepartmentId() : null;
            if ($department !== null && (int) $letter->department_id === $department && $this->member($user, $department)) {
                $visible->orWhere('a.correspondence_id', $letter->id);
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
                ->where('forwarded.actor_id', $user->id)->whereIn('forwarded.action', ['forward', 'reassign'])
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
