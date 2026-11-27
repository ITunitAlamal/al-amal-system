<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes open requests away from holders who no longer have the authority behind them (TRANSFORM §3.4/§3.5: stale
 * approvals after a role change, a new head, an ended appointment, a transfer or a lapsed delegation).
 *
 * Under the instance lock, the active assignees who fail AssigneeResolver::stillQualifies leave the step. When no
 * qualified holder is left, the step's holders are resolved again by its own rules. The step never changes: nothing
 * is approved or skipped. When nobody qualifies, the request waits at its step with no holder, recorded on its
 * timeline; the requester, the system administrators and (non-confidential requests) the executive director are
 * told (reference, step and the designation needed), and non-confidential requests are also listed by «جاهزية
 * النظام» (SystemReadiness: requests without a holder). Either of the two may then ask for «إعادة تحديد المسؤول»
 * (recover), which runs the same rules again at once.
 */
final class OpenStepRerouter
{
    public const ACTION_REROUTED = 'rerouted';

    public const ACTION_HOLDER_MISSING = 'holder_missing';

    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly WorkflowSubjects $subjects,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    /**
     * Open requests (optionally among the given ids) with a holder who no longer qualifies, or with no holder while
     * someone now qualifies. One query for the requests and one for their steps; the rules decide the rest.
     *
     * @param  list<int>|null  $within
     * @return list<int>
     */
    public function staleInstanceIds(?array $within = null): array
    {
        if ($within === []) {
            return [];
        }
        $instances = DB::table('workflow_instances as i')
            ->where('i.status', 'in_progress')
            ->when($within !== null, fn ($query) => $query->whereIn('i.id', $within))
            ->select('i.*')
            ->selectRaw("(SELECT string_agg(p.user_id::text, ',') FROM workflow_participants p WHERE p.workflow_instance_id = i.id AND p.participation_type = 'assignee' AND p.is_active) AS holder_list")
            ->orderBy('i.id')
            ->get();
        $steps = DB::table('workflow_steps')->whereIn('id', $instances->pluck('current_step_id')->unique())->get()->keyBy('id');

        $ids = [];
        foreach ($instances as $instance) {
            $step = $steps->get($instance->current_step_id);
            if (! $step || $step->step_type === 'end') {
                continue;
            }
            $holders = $this->holderIds($instance->holder_list);
            $stale = $holders === []
                ? $this->qualifiedCandidates($step, $instance) !== []
                : collect($holders)->contains(fn (int $userId): bool => ! $this->assignees->stillQualifies($step, $instance, $userId));
            if ($stale) {
                $ids[] = (int) $instance->id;
            }
        }

        return $ids;
    }

    /**
     * Open requests that a change of head in the department may concern: made in the department or its units, at a
     * step that names it (holder, supervisor or alternate), or held by its former head.
     *
     * @return list<int>
     */
    public function forDepartment(int $departmentId, ?int $formerHeadId): array
    {
        $subtree = 'WITH RECURSIVE t(id) AS (SELECT id FROM departments WHERE id = ? UNION SELECT d.id FROM departments d JOIN t ON d.parent_id = t.id) SELECT id FROM t';
        $code = (string) $departmentId;

        return DB::table('workflow_instances as i')
            ->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.status', 'in_progress')
            ->where(fn ($query) => $query->whereRaw("i.department_id IN ({$subtree})", [$departmentId])
                ->orWhereRaw("(s.assignment_config::jsonb ->> 'department_id') = ?", [$code])
                ->orWhereRaw("(s.assignment_config::jsonb ->> 'supervisor_department_id') = ?", [$code])
                ->orWhereRaw("(s.assignment_config::jsonb ->> 'fallback_department_id') = ?", [$code])
                ->when($formerHeadId !== null, fn ($inner) => $inner->orWhereExists(fn ($held) => $held->selectRaw('1')->from('workflow_participants as p')
                    ->whereColumn('p.workflow_instance_id', 'i.id')->where('p.participation_type', 'assignee')->where('p.is_active', true)
                    ->where('p.user_id', $formerHeadId))))
            ->pluck('i.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * The scheduled pass (workflow:check-sla): every open request whose holders no longer qualify.
     */
    public function rerouteStale(string $reason): int
    {
        return $this->reroute($this->staleInstanceIds(), $reason);
    }

    /**
     * Who may ask for «إعادة تحديد المسؤول»: the executive director, and the system administrator as a technical
     * re-resolution. Neither chooses a holder: the step's own rules choose.
     */
    public function mayRecover(User $user): bool
    {
        return $user->is_active && ($user->isSystemAdmin() || app(ApprovalDelegations::class)->isExecutive($user));
    }

    /**
     * Whether the open request waits with no qualified holder, and what its step needs to have one. Null while an
     * active holder still qualifies (stale co-holders are left to the scheduled pass).
     *
     * @return array{step: string, resolvable: bool, needed: string}|null
     */
    public function recoveryState(object $instance): ?array
    {
        if ($instance->status !== 'in_progress') {
            return null;
        }
        $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
        if (! $step || $step->step_type === 'end') {
            return null;
        }
        $holders = DB::table('workflow_participants')->where('workflow_instance_id', $instance->id)->where('participation_type', 'assignee')
            ->where('is_active', true)->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        foreach ($holders as $userId) {
            if ($this->assignees->stillQualifies($step, $instance, $userId)) {
                return null;
            }
        }

        return [
            'step' => (string) $step->name,
            'resolvable' => $this->qualifiedCandidates($step, $instance) !== [],
            'needed' => $this->assignees->holderNeeded($step),
        ];
    }

    /**
     * «إعادة تحديد المسؤول» on one open request (TRANSFORM §6: missing-holder alerts actionable by authorized
     * administrators). The step's rules run again now, as the scheduled pass would; nothing is approved, skipped or
     * handed to a person picked by hand. The reroute is audited and timed with the person who asked for it.
     *
     * @return array{changed: bool, held: bool, needed: ?string}
     */
    public function recover(int $instanceId, User $actor): array
    {
        abort_unless($this->mayRecover($actor), 403, 'إعادة تحديد المسؤول للمدير التنفيذي ومدير النظام.');
        $status = DB::table('workflow_instances')->where('id', $instanceId)->value('status');
        abort_unless($status !== null, 404);
        if ($status !== 'in_progress') {
            throw ValidationException::withMessages(['reroute' => 'المعاملة مغلقة؛ لا يوجد مسؤول يُعاد تحديده.']);
        }
        $by = app(ApprovalDelegations::class)->isExecutive($actor) ? 'المدير التنفيذي' : 'مدير النظام';
        $changed = $this->reroute([$instanceId], 'إعادة تحديد المسؤول بطلب '.$by, (int) $actor->id) > 0;
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->first();
        $state = $instance ? $this->recoveryState($instance) : null;

        return ['changed' => $changed, 'held' => $state === null, 'needed' => $state['needed'] ?? null];
    }

    /**
     * @param  list<int>  $instanceIds
     * @param  ?int  $actorId  who caused the change (null for the scheduled check)
     * @return int number of requests whose holders changed
     */
    public function reroute(array $instanceIds, string $reason, ?int $actorId = null): int
    {
        $changed = 0;
        foreach (array_unique(array_map('intval', $instanceIds)) as $instanceId) {
            if (DB::transaction(fn (): bool => $this->rerouteOne($instanceId, $reason, $actorId))) {
                $changed++;
            }
        }

        return $changed;
    }

    private function rerouteOne(int $instanceId, string $reason, ?int $actorId): bool
    {
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
        if (! $instance || $instance->status !== 'in_progress') {
            return false;
        }
        $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
        if (! $step || $step->step_type === 'end') {
            return false;
        }

        $holders = DB::table('workflow_participants')->where('workflow_instance_id', $instanceId)->where('participation_type', 'assignee')
            ->where('is_active', true)->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $stale = array_values(array_filter($holders, fn (int $userId): bool => ! $this->assignees->stillQualifies($step, $instance, $userId)));
        $kept = array_values(array_diff($holders, $stale));
        if ($stale === [] && $kept !== []) {
            return false;
        }
        // Someone still qualified keeps the step as it is; otherwise the step's own rules choose again.
        $added = $kept === [] ? $this->qualifiedCandidates($step, $instance) : [];
        if ($stale === [] && $added === []) {
            return false; // already waiting with nobody, and still nobody qualifies
        }

        $now = now();
        if ($stale !== []) {
            // Closed by whoever caused it (or the system), never by the holder: that would read as their own action.
            // An actor who is one of the stale holders is recorded as the system for their own row (as handOver does).
            $closeRows = fn (array $userIds, ?int $revokedBy) => $userIds === [] ? 0 : DB::table('workflow_participants')
                ->where('workflow_instance_id', $instanceId)->where('participation_type', 'assignee')->whereIn('user_id', $userIds)
                ->update(['is_active' => false, 'revoked_at' => $now, 'revoked_by' => $revokedBy, 'updated_at' => $now]);
            $closeRows(array_values(array_diff($stale, [$actorId])), $actorId);
            $closeRows(array_values(array_intersect($stale, [$actorId])), null);
        }
        $requesterId = (int) $instance->requested_by;
        foreach ($added as $userId) {
            $this->participate($instanceId, $userId, $userId === $requesterId ? 'public_status' : 'operational', $actorId);
        }

        $active = array_values(array_unique([...$kept, ...$added]));
        $current = $instance->current_assignee_id !== null ? (int) $instance->current_assignee_id : null;
        if ($current === null || in_array($current, $stale, true)) {
            $current = count($active) === 1 ? $active[0] : null;
        }
        $revision = (int) $instance->revision + 1;
        DB::table('workflow_instances')->where('id', $instanceId)->update(['current_assignee_id' => $current, 'revision' => $revision, 'updated_at' => $now]);

        $action = $active === [] ? self::ACTION_HOLDER_MISSING : self::ACTION_REROUTED;
        DB::table('workflow_events')->insert([
            'workflow_instance_id' => $instanceId, 'workflow_transition_id' => null,
            'from_step_id' => $step->id, 'to_step_id' => $step->id,
            'actor_id' => $actorId, 'assigned_to_id' => $current,
            'action' => $action, 'comment' => $reason,
            'metadata' => json_encode(['from_user_ids' => $stale, 'to_user_ids' => $added, 'kept_user_ids' => $kept], JSON_UNESCAPED_UNICODE),
            'occurred_at' => $now, 'created_at' => $now,
        ]);
        $this->audit->recordRow('workflow.instance.'.$action, 'workflow_instances', $instanceId,
            ['holder_ids' => $holders],
            ['holder_ids' => $active, 'step' => $step->code, 'reference' => $instance->reference_no, 'reason' => $reason],
            $actorId);

        $reference = (string) ($instance->reference_no ?? '');
        $this->notifications->notifyMany(array_values(array_diff($added, array_filter([$actorId]))), 'workflow_task_assigned', [
            'title' => 'أُحيلت إليك معاملة: '.$step->name,
            'body' => $reference.' · '.$reason,
            'url' => $this->subjects->url($instance),
            'dedupe' => 'wf:'.$instanceId.':r'.$revision.':rerouted',
        ]);
        // The link opens «مهامي»: the request itself is no longer theirs to act on.
        $this->notifications->notifyMany($stale, StepAssignments::EVENT_WITHDRAWN, [
            'title' => 'سُحبت منك مهمة: '.$reference,
            'body' => 'لم تعد صاحب الصلاحية في مرحلة «'.$step->name.'» · '.$reason,
            'url' => route('tasks.index', [], false),
            'dedupe' => 'wf:'.$instanceId.':r'.$revision.':rerouted-out',
        ]);
        if ($action === self::ACTION_HOLDER_MISSING) {
            $this->alertHolderMissing($instance, $step, $reason, $revision, $actorId);
        }

        return true;
    }

    /**
     * Nobody qualifies: tell the requester, the system administrators and the executive director, reference, step
     * and the designation needed only, so a confidential request (which «جاهزية النظام» does not list) is never left
     * waiting unnoticed. A generic request's page carries «إعادة تحديد المسؤول»; the executive director is told of
     * non-confidential requests, which he can open.
     */
    private function alertHolderMissing(object $instance, object $step, string $reason, int $revision, ?int $actorId): void
    {
        $reference = (string) ($instance->reference_no ?? '');
        $dedupe = 'wf:'.$instance->id.':r'.$revision.':holder-missing';
        $requesterId = (int) $instance->requested_by;
        if ($requesterId > 0 && $requesterId !== $actorId) {
            $this->notifications->notify($requesterId, 'workflow_request_held', [
                'title' => 'طلبك '.$reference.' متوقف عند «'.$step->name.'»',
                'body' => 'لا يوجد صاحب صلاحية مؤهل لهذه المرحلة حاليًا؛ أُبلغت الإدارة.',
                'url' => $this->subjects->url($instance),
                'dedupe' => $dedupe.':requester',
            ]);
        }
        $confidential = (bool) DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $instance->workflow_version_id)->value('d.is_confidential');
        $body = 'متوقفة عند «'.$step->name.'» · '.$reason.' · يلزم: '.$this->assignees->holderNeeded($step).'.';
        $this->notifications->notifyRole('system_admin', 'workflow_task_urgent', [
            'title' => 'معاملة بلا صاحب صلاحية مؤهل: '.$reference,
            'body' => $body,
            'url' => ! $confidential && $instance->subject_type === WorkflowEngine::SUBJECT_TYPE
                ? $this->subjects->url($instance) : route('admin.employees.index', [], false),
            'dedupe' => $dedupe,
        ]);
        if ($confidential) {
            return;
        }
        $executives = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', 'executive_director')->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))
            ->distinct()->pluck('a.user_id')->map(fn ($id): int => (int) $id)->all();
        $this->notifications->notifyMany(array_values(array_diff($executives, array_filter([$actorId, $requesterId]))), 'workflow_task_urgent', [
            'title' => 'معاملة بلا صاحب صلاحية مؤهل: '.$reference,
            'body' => $body,
            'url' => $this->subjects->url($instance),
            'dedupe' => $dedupe.':executive',
        ]);
    }

    /**
     * The step's holders by its own rules today, or else its current supervisor, kept only when they also pass the
     * action-time check.
     *
     * @return list<int>
     */
    private function qualifiedCandidates(object $step, object $instance): array
    {
        $ids = array_values(array_filter($this->assignees->candidates($step, $instance),
            fn (int $userId): bool => $this->assignees->stillQualifies($step, $instance, $userId)));
        if ($ids !== []) {
            return $ids;
        }
        // Nobody by the step's own rules (e.g. a pick step taken back by a former head): it returns to the head who
        // supervises it now, as a withdrawn assignment does.
        $supervisorId = $this->assignees->supervisor($step, $instance);

        return $supervisorId !== null && $this->assignees->stillQualifies($step, $instance, $supervisorId) ? [$supervisorId] : [];
    }

    /**
     * @return list<int>
     */
    private function holderIds(?string $list): array
    {
        return $list === null || $list === '' ? [] : array_values(array_unique(array_map('intval', explode(',', $list))));
    }

    private function participate(int $instanceId, int $userId, string $visibility, ?int $addedBy): void
    {
        $now = now();
        $existing = DB::table('workflow_participants')
            ->where('workflow_instance_id', $instanceId)->where('user_id', $userId)->where('participation_type', 'assignee')->first();
        if ($existing) {
            DB::table('workflow_participants')->where('id', $existing->id)->update([
                'is_active' => true, 'visibility_scope' => $visibility, 'revoked_at' => null, 'revoked_by' => null, 'updated_at' => $now,
            ]);

            return;
        }
        DB::table('workflow_participants')->insert([
            'workflow_instance_id' => $instanceId, 'user_id' => $userId, 'participation_type' => 'assignee', 'visibility_scope' => $visibility,
            'is_active' => true, 'added_by' => $addedBy, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
