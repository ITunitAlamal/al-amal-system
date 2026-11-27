<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Digital assignment of a workflow step («إسناد إلى موظف») and the one place for its rules.
 *
 * A step is supervised by a department when it belongs to that department's head ("department_head_of"), or when
 * its configuration names "supervisor_department_id" (a step held by someone else, such as the picked technician).
 * The supervisor is that department's head, resolved exactly as the engine resolves a "department_head_of" holder.
 *
 * While the request waits at a supervised step that accepts assignment (a head's stage flagged "assignable", or a
 * step held by someone else under the department's supervision), the supervisor may hand the step to an active
 * user of the department or its units, take it back, or move it to someone else. The head's other decisions stay
 * with the head. The person holding it is the step's active
 * assignee, so «مهامي», the allowed actions and the engine's checks need nothing special; the next move ends the
 * assignment like any other holder. Nobody gains a step this way that the step's own rules refuse them: the
 * requester is a candidate only where the step lets the requester act.
 *
 * A suspended request («تعليق») can change hands too, and stays suspended: the supervisor must be able to take a
 * step back from a holder who suspended it and is away. Whoever receives it resumes it as its holder.
 *
 * Confidential workflows and correspondence records (which have their own referral rules) are never supervised.
 */
final class StepAssignments
{
    public const ACTION_ASSIGNED = 'assigned';

    public const ACTION_WITHDRAWN = 'assignment_withdrawn';

    public const EVENT_WITHDRAWN = 'workflow_assignment_withdrawn';

    public const EVENT_COMPLETED = 'workflow_assignment_completed';

    /** The request field that names the worker of a field job (a step flagged "field_work"); he has no account. */
    public const FIELD_WORKER_CODE = 'field_worker_name';

    private const EXCLUDED_SUBJECTS = ['official_correspondence', 'correspondence_edition'];

    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly WorkflowSubjects $subjects,
        private readonly NotificationCenter $notifications,
        private readonly AuditService $audit,
    ) {}

    /**
     * The head who supervises the request's current step, or null when the step is not supervised or the
     * request is closed.
     */
    public function supervisorId(object $instance): ?int
    {
        return $instance->status === 'in_progress' ? ($this->supervision($instance)['supervisor_id'] ?? null) : null;
    }

    /**
     * The department that supervises the request's current step (the one candidates() draws from), or null.
     */
    public function supervisorDepartmentId(object $instance): ?int
    {
        return $instance->status === 'in_progress' ? ($this->supervision($instance)['department_id'] ?? null) : null;
    }

    /**
     * Whether the user may assign, reassign or withdraw the request's current step now. A suspended request is
     * included: only its holder can resume it, so the supervisor reaches it by taking the step back.
     */
    public function canSupervise(User $user, object $instance): bool
    {
        return $user->is_active && $instance->status === 'in_progress' && $this->supervisorId($instance) === (int) $user->id;
    }

    /**
     * Where the current step stands, for pages and dashboards. "mode" is "supervisor" while the supervisor holds
     * the step and "assigned" while someone else does (by assignment, or picked by the previous actor).
     *
     * @return array{supervised: bool, assignable: bool, field_work: bool, mode: ?string, holder: ?array{id: int, name: string}, assigned_by: ?array{id: int, name: string}, assigned_at: ?string, note: ?string, on_hold: bool}
     */
    public function state(object $instance): array
    {
        $supervision = $instance->status === 'in_progress' ? $this->supervision($instance) : null;
        if ($supervision === null) {
            return ['supervised' => false, 'assignable' => false, 'field_work' => false, 'mode' => null, 'holder' => null, 'assigned_by' => null, 'assigned_at' => null, 'note' => null, 'on_hold' => false];
        }

        $step = $supervision['step'];
        $config = $this->assignees->config($step);
        $holderId = $instance->current_assignee_id !== null ? (int) $instance->current_assignee_id : null;
        $assigned = $holderId !== $supervision['supervisor_id'];
        $handover = $assigned && $holderId !== null ? DB::table('workflow_events')
            ->where('workflow_instance_id', $instance->id)->where('to_step_id', $step->id)->where('assigned_to_id', $holderId)
            ->where(fn ($query) => $query->whereNotNull('workflow_transition_id')->orWhereIn('action', [self::ACTION_ASSIGNED, 'reopen']))
            ->orderByDesc('id')->first(['workflow_transition_id', 'actor_id', 'action', 'comment', 'occurred_at']) : null;
        $assignerId = $handover ? $this->assigner($handover, (string) $step->assignment_type) : null;
        $names = DB::table('users')->whereIn('id', array_filter([$holderId, $assignerId]))->pluck('name', 'id');

        return [
            'supervised' => true,
            'assignable' => ! empty($config['assignable']),
            'field_work' => ! empty($config['field_work']),
            'mode' => $assigned ? 'assigned' : 'supervisor',
            'holder' => $holderId !== null ? ['id' => $holderId, 'name' => (string) ($names[$holderId] ?? '')] : null,
            'assigned_by' => $assignerId !== null && isset($names[$assignerId]) ? ['id' => $assignerId, 'name' => (string) $names[$assignerId]] : null,
            'assigned_at' => $handover?->occurred_at,
            'note' => $handover && $this->isAssignment($handover) ? $handover->comment : null,
            'on_hold' => ($instance->held_at ?? null) !== null,
        ];
    }

    /**
     * What the request page shows about the step's assignment: the holder line for people who see the request's
     * internal data, and the candidates for the supervisor. Null when there is nothing to show this viewer.
     *
     * @param  ?string  $level  the viewer's visibility on the request (WorkflowVisibilityService)
     * @return array<string, mixed>|null
     */
    public function panel(object $instance, User $viewer, ?string $level): ?array
    {
        $state = $this->state($instance);
        if (! $state['supervised']) {
            return null;
        }
        $canSupervise = $this->canSupervise($viewer, $instance);
        $canAssign = $canSupervise && $this->acceptsAssignment($this->supervision($instance)['step']);
        // The requester's public view never names the people handling the request.
        if (! $canSupervise && ($level === null || $level === 'public_status' || $state['mode'] !== 'assigned')) {
            return null;
        }
        // The head's own decision on a stage not opened for assignment: nothing to hand over or take back.
        if ($canSupervise && ! $canAssign && $state['mode'] !== 'assigned') {
            return null;
        }

        return [...$state, 'can_supervise' => $canSupervise, 'can_assign' => $canAssign, 'candidates' => $canAssign ? $this->candidates($instance) : []];
    }

    /**
     * Who the supervisor may hand the current step to: active users of the supervisor department or its units,
     * without the supervisor, and without the requester unless the step lets the requester act. A step that names
     * a role (the picked technician, or the step that takes its holder from the technician's) only goes to holders
     * of that role. Empty for a stage that does not accept assignment.
     *
     * @return list<array{id: int, name: string, open_tasks: int}>
     */
    public function candidates(object $instance): array
    {
        $supervision = $this->supervision($instance);
        if ($supervision === null || ! $this->acceptsAssignment($supervision['step'])) {
            return [];
        }
        // Never the requester (unless the step lets them act) nor a person the record concerns.
        $people = $this->candidatesIn($supervision['department_id'], [$supervision['supervisor_id'], ...$this->assignees->excludedIds($supervision['step'], $instance)]);
        $qualified = $this->assignees->qualifiedHolders($supervision['step'], $instance);

        return $qualified === null ? $people
            : array_values(array_filter($people, fn (array $person): bool => in_array($person['id'], $qualified, true)));
    }

    /**
     * Whether the supervisor may hand the step to an employee: a stage of the head's own that is opened for it
     * ("assignable"), or a step held by someone else under the department's supervision ("supervisor_department_id",
     * such as the technician's), which the head may move between qualified holders. The head's other decisions
     * stay with the head.
     */
    private function acceptsAssignment(object $step): bool
    {
        return ! empty($this->assignees->config($step)['assignable']) || $step->assignment_type !== 'department_head_of';
    }

    /**
     * Active users who belong to the department or one of its units (their own department, or a role held
     * there), each with the number of open workflow tasks they hold now. The system administrator's access is
     * technical, so they are never offered a department's work.
     *
     * @param  list<int>  $exceptUserIds
     * @return list<array{id: int, name: string, open_tasks: int}>
     */
    public function candidatesIn(int $departmentId, array $exceptUserIds = []): array
    {
        $departments = $this->withUnits($departmentId);
        $users = DB::table('users as u')
            ->where('u.is_active', true)
            ->whereNotIn('u.id', array_values(array_filter($exceptUserIds)))
            ->where(fn ($query) => $query->whereIn('u.department_id', $departments)
                ->orWhereExists(fn ($role) => $role->selectRaw('1')->from('department_user_roles as a')
                    ->whereColumn('a.user_id', 'u.id')->whereIn('a.department_id', $departments)->whereRaw(EffectiveAssignments::sql('a'))))
            ->whereNotExists(fn ($admin) => $admin->selectRaw('1')->from('department_user_roles as a')
                ->join('roles as r', 'r.id', '=', 'a.role_id')
                ->whereColumn('a.user_id', 'u.id')->whereNull('a.department_id')->where('r.code', 'system_admin')->whereRaw(EffectiveAssignments::sql('a')))
            ->orderBy('u.name')
            ->get(['u.id', 'u.name']);
        $open = DB::table('workflow_participants as p')
            ->join('workflow_instances as i', 'i.id', '=', 'p.workflow_instance_id')
            ->whereIn('p.user_id', $users->pluck('id'))->where('p.participation_type', 'assignee')->where('p.is_active', true)
            ->where('i.status', 'in_progress')
            ->groupBy('p.user_id')->selectRaw('p.user_id, count(*) as n')->pluck('n', 'user_id');

        return $users->map(fn (object $user): array => ['id' => (int) $user->id, 'name' => (string) $user->name, 'open_tasks' => (int) ($open[$user->id] ?? 0)])->all();
    }

    /**
     * «إسناد إلى موظف», or «إعادة الإسناد» when someone else already holds the step.
     */
    public function assign(object $instance, User $supervisor, int $toUserId, ?string $note, int $expectedRevision, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($instance, $supervisor, $toUserId, $note, $expectedRevision, $ipAddress): void {
            [$locked, $step] = $this->lockedSupervised((int) $instance->id, $supervisor, $expectedRevision);
            if (! $this->acceptsAssignment($step)) {
                throw ValidationException::withMessages(['assignment' => 'هذه المرحلة من قرارات رئيس القسم ولا تُسند إلى موظف.']);
            }
            if ($locked->current_assignee_id !== null && (int) $locked->current_assignee_id === $toUserId) {
                throw ValidationException::withMessages(['to_user_id' => 'المهمة لدى هذا الموظف بالفعل.']);
            }
            if (! in_array($toUserId, array_column($this->candidates($locked), 'id'), true)) {
                throw ValidationException::withMessages(['to_user_id' => 'اختر موظفًا نشطًا من القسم المشرف على المرحلة أو من وحداته.']);
            }
            $this->handOver($locked, $step, $supervisor, $toUserId, self::ACTION_ASSIGNED, $note, $ipAddress);
        });
    }

    /**
     * «سحب الإسناد»: the step returns to the supervisor, who then holds it like any assignee (also for a step
     * that was held by a picked user).
     */
    public function withdraw(object $instance, User $supervisor, ?string $note, int $expectedRevision, ?string $ipAddress = null): void
    {
        DB::transaction(function () use ($instance, $supervisor, $note, $expectedRevision, $ipAddress): void {
            [$locked, $step] = $this->lockedSupervised((int) $instance->id, $supervisor, $expectedRevision);
            if ($locked->current_assignee_id !== null && (int) $locked->current_assignee_id === (int) $supervisor->id) {
                throw ValidationException::withMessages(['assignment' => 'المهمة لديك بالفعل؛ لا يوجد إسناد لسحبه.']);
            }
            $this->handOver($locked, $step, $supervisor, (int) $supervisor->id, self::ACTION_WITHDRAWN, $note, $ipAddress);
        });
    }

    /**
     * Called by the engine inside a move: the supervisor of the step being left hears that the person holding it
     * (assigned by them, picked, or the same person as an earlier step) acted on it. Nothing is sent for the
     * supervisor's own action, nor when the request comes to the supervisor as its next holder: the task notice
     * of that same move already tells them. Runs in the move's transaction.
     *
     * @param  object  $instance  the request as it was before the move
     * @param  list<int>  $nextHolders
     */
    public function stepCompleted(object $instance, object $from, object $target, object $transition, int $actorId, array $nextHolders, int $revision): void
    {
        $supervisorId = $this->supervision($instance, $from)['supervisor_id'] ?? null;
        if ($supervisorId === null || $supervisorId === $actorId || in_array($supervisorId, $nextHolders, true)) {
            return;
        }
        if ($supervisorId !== (int) $instance->requested_by) {
            // The supervisor keeps operational sight of a request whose step was done under their supervision.
            $this->participate((int) $instance->id, $supervisorId, 'handler', 'operational', $actorId);
        }
        $name = (string) DB::table('users')->where('id', $actorId)->value('name');
        $reference = (string) ($instance->reference_no ?? '');
        $this->notifications->notify($supervisorId, self::EVENT_COMPLETED, [
            // Sending the request back, or refusing it, is not an accomplishment: the notice says what happened.
            'title' => match ($transition->action_code) {
                'return' => 'أعاد '.$name.' '.$reference.' من مرحلة «'.$from->name.'»',
                'reject' => 'رفض '.$name.' '.$reference.' في مرحلة «'.$from->name.'»',
                default => 'أنجز '.$name.' مرحلة «'.$from->name.'» في '.$reference,
            },
            'body' => 'الإجراء: '.$transition->name.' · '.($target->step_type === 'end' ? 'أُغلقت المعاملة: ' : 'انتقلت إلى: ').$target->name,
            'url' => $this->subjects->url($instance),
            'dedupe' => 'wf:'.$instance->id.':r'.$revision.':step-done',
        ]);
    }

    /**
     * Open requests whose current step is supervised by the department, oldest activity first: what the head
     * holds, what an employee holds for the department, and what waits for field work to be documented.
     * A fixed number of queries for any number of requests (one more for a request made by the head themselves).
     *
     * Each row: id, public_id, reference_no, subject_type, workflow_code, workflow_name, title, requester_name,
     * department_name, step_code, step_name, assignable, field_work, field_worker (the worker's name on a
     * "field_work" step of a generic request, else null), mode ("supervisor" | "assigned"), holder_id,
     * holder_name, assigned_by_name, held_since (when the step reached its present holder), due_at, on_hold,
     * urgent, url. A step nobody supervises today (the department has no head) is left out, as in state().
     *
     * @return list<array<string, mixed>>
     */
    public function openSupervised(int $departmentId): array
    {
        $rows = DB::table('workflow_instances as i')
            ->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->join('users as requester', 'requester.id', '=', 'i.requested_by')
            ->leftJoin('departments as origin', 'origin.id', '=', 'i.department_id')
            ->leftJoin('users as holder', 'holder.id', '=', 'i.current_assignee_id')
            ->leftJoin('aims_requests as r', fn ($join) => $join->on('r.id', '=', 'i.subject_id')->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE))
            ->where('i.status', 'in_progress')
            ->where('d.is_confidential', false)
            ->whereNotIn('i.subject_type', self::EXCLUDED_SUBJECTS)
            ->where(fn ($query) => $query
                ->whereRaw("(s.assignment_config::jsonb ->> 'department_id') = ?", [(string) $departmentId])
                ->orWhereRaw("(s.assignment_config::jsonb ->> 'supervisor_department_id') = ?", [(string) $departmentId]))
            ->orderBy('i.updated_at')->orderBy('i.id')
            ->get([
                'i.id', 'i.public_id', 'i.reference_no', 'i.subject_type', 'i.subject_id', 'i.requested_by', 'i.department_id', 'i.current_step_id',
                'i.current_assignee_id', 'i.due_at', 'i.held_at', 'i.started_at', 'i.updated_at',
                'd.code as workflow_code', 'd.name as workflow_name', 'r.title',
                's.code as step_code', 's.name as step_name', 's.step_type', 's.assignment_type', 's.assignment_config',
                'requester.name as requester_name', 'origin.name as department_name', 'holder.name as holder_name',
            ])
            // The query narrows by configuration text; the resolver decides what "supervised" means.
            ->filter(fn (object $row): bool => $this->assignees->supervisorDepartmentId($row) === $departmentId)
            ->values();
        if ($rows->isEmpty()) {
            return [];
        }

        // Moves between steps and changes of holder only: a deadline flag or a suspension does not restart the wait.
        $events = DB::table('workflow_events')->whereIn('workflow_instance_id', $rows->pluck('id'))
            ->where(fn ($query) => $query->whereNotNull('workflow_transition_id')->orWhereIn('action', [self::ACTION_ASSIGNED, self::ACTION_WITHDRAWN, 'reopen']))
            ->orderBy('id')
            ->get(['workflow_instance_id', 'workflow_transition_id', 'to_step_id', 'actor_id', 'assigned_to_id', 'action', 'occurred_at'])
            ->groupBy('workflow_instance_id');
        $titles = $this->subjects->titles($rows->where('subject_type', '!=', WorkflowEngine::SUBJECT_TYPE));
        $urgent = DB::table('custom_field_values as f')
            ->join('master_data_values as m', 'm.id', '=', 'f.value_master_id')
            ->join('master_data_types as t', 't.id', '=', 'm.type_id')
            ->where('f.entity_type', CustomFieldEntities::AIMS_REQUEST)
            ->whereIn('f.entity_id', $rows->where('subject_type', WorkflowEngine::SUBJECT_TYPE)->pluck('subject_id'))
            ->where('t.code', 'like', '%priorit%')->whereIn('m.code', ['urgent', 'emergency'])
            ->pluck('f.entity_id')->flip();
        // Who was sent to do a field job that waits to be documented: the worker recorded with the move that opened
        // this pass of the step («تسجيل تكليف ميداني», metadata.fields). Read only when such a row exists.
        $fieldJobs = $rows->filter(fn (object $row): bool => $row->subject_type === WorkflowEngine::SUBJECT_TYPE && ! empty($this->assignees->config($row)['field_work']));
        $workers = [];
        if ($fieldJobs->isNotEmpty()) {
            $openings = DB::table('workflow_events')->whereIn('workflow_instance_id', $fieldJobs->pluck('id'))
                ->whereIn('to_step_id', $fieldJobs->pluck('current_step_id')->unique())->whereNotNull('workflow_transition_id')
                ->orderBy('id')->get(['workflow_instance_id', 'to_step_id', 'metadata'])->groupBy('workflow_instance_id');
            foreach ($fieldJobs as $row) {
                $opening = $openings->get($row->id, collect())->last(fn (object $event): bool => (int) $event->to_step_id === (int) $row->current_step_id);
                $named = collect($opening ? CustomFieldService::eventFields($opening->metadata) : [])->firstWhere('code', self::FIELD_WORKER_CODE);
                if ($named !== null) {
                    $workers[(int) $row->subject_id] = $named['value'];
                }
            }
            // A job registered before events carried the worker: the request's stored value.
            $missing = $fieldJobs->reject(fn (object $row): bool => isset($workers[(int) $row->subject_id]))->pluck('subject_id');
            if ($missing->isNotEmpty()) {
                $workers += DB::table('custom_field_values as f')
                    ->join('custom_field_definitions as fd', 'fd.id', '=', 'f.definition_id')
                    ->where('f.entity_type', CustomFieldEntities::AIMS_REQUEST)->whereIn('f.entity_id', $missing)
                    ->where('fd.code', self::FIELD_WORKER_CODE)->where('fd.is_sensitive', false)->whereNotNull('f.value_text')
                    ->pluck('f.value_text', 'f.entity_id')->all();
            }
        }
        // The department's head is the same for every row, except a request made by that head.
        $head = $this->assignees->supervisor($rows->first(), (object) ['requested_by' => 0]);

        $result = [];
        $handovers = [];
        foreach ($rows as $row) {
            $ownRequest = $head !== null && $head === (int) $row->requested_by && ! $this->assignees->requesterMayAct($row);
            $supervisorId = $ownRequest ? $this->assignees->supervisor($row, $row) : $head;
            if ($supervisorId === null) {
                continue; // nobody supervises it today (it waits with the step's alternates)
            }
            $config = $this->assignees->config($row);
            $holderId = $row->current_assignee_id !== null ? (int) $row->current_assignee_id : null;
            $assigned = $holderId !== $supervisorId;
            $history = $events->get($row->id, collect());
            $handover = $assigned && $holderId !== null ? $history->last(fn (object $event): bool => (int) $event->to_step_id === (int) $row->current_step_id
                && (int) $event->assigned_to_id === $holderId
                && ($event->workflow_transition_id !== null || in_array($event->action, [self::ACTION_ASSIGNED, 'reopen'], true))) : null;
            $handovers[(int) $row->id] = $handover ? $this->assigner($handover, (string) $row->assignment_type) : null;

            $result[] = [
                'id' => (int) $row->id,
                'public_id' => $row->public_id,
                'reference_no' => $row->reference_no,
                'subject_type' => $row->subject_type,
                'workflow_code' => $row->workflow_code,
                'workflow_name' => $row->workflow_name,
                'title' => $row->title ?? ($titles[$row->subject_type][(int) $row->subject_id] ?? null),
                'requester_name' => $row->requester_name,
                'department_name' => $row->department_name,
                'step_code' => $row->step_code,
                'step_name' => $row->step_name,
                'assignable' => ! empty($config['assignable']),
                'field_work' => ! empty($config['field_work']),
                'field_worker' => ! empty($config['field_work']) && $row->subject_type === WorkflowEngine::SUBJECT_TYPE ? ($workers[$row->subject_id] ?? null) : null,
                'mode' => $assigned ? 'assigned' : 'supervisor',
                'holder_id' => $holderId,
                'holder_name' => $row->holder_name,
                'assigned_by_name' => null,
                'held_since' => $history->last()?->occurred_at ?? $row->started_at,
                'due_at' => $row->due_at,
                'on_hold' => $row->held_at !== null,
                'urgent' => isset($urgent[$row->subject_id]) && $row->subject_type === WorkflowEngine::SUBJECT_TYPE,
                'url' => $this->subjects->url($row),
            ];
        }

        $names = DB::table('users')->whereIn('id', array_filter($handovers))->pluck('name', 'id');

        return array_map(fn (array $item): array => [...$item, 'assigned_by_name' => $names[$handovers[$item['id']] ?? 0] ?? null], $result);
    }

    /**
     * The current step and who supervises it; null when the step is not supervised for this request.
     *
     * @return array{step: object, department_id: int, supervisor_id: int}|null
     */
    private function supervision(object $instance, ?object $step = null): ?array
    {
        if (in_array($instance->subject_type, self::EXCLUDED_SUBJECTS, true)) {
            return null;
        }
        $step ??= DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
        $departmentId = $step ? $this->assignees->supervisorDepartmentId($step) : null;
        if ($departmentId === null) {
            return null;
        }
        $confidential = DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $instance->workflow_version_id)->value('d.is_confidential');
        $supervisorId = $confidential ? null : $this->assignees->supervisor($step, $instance);

        return $supervisorId === null ? null : ['step' => $step, 'department_id' => $departmentId, 'supervisor_id' => $supervisorId];
    }

    /**
     * The request, locked at the revision the supervisor saw, with its current step. Same lock and checks as the
     * engine's act(), except that a suspended request is accepted: it changes hands and stays suspended.
     *
     * @return array{0: object, 1: object}
     */
    private function lockedSupervised(int $instanceId, User $supervisor, int $expectedRevision): array
    {
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
        abort_unless($instance, 404);
        if ($instance->status !== 'in_progress') {
            throw ValidationException::withMessages(['assignment' => 'المعاملة مغلقة ولا تقبل الإسناد.']);
        }
        if ((int) $instance->revision !== $expectedRevision) {
            throw ValidationException::withMessages(['assignment' => 'تم تحديث المعاملة من مستخدم آخر. أعد تحميل الصفحة ثم حاول مجددًا.']);
        }
        // The supervisor is resolved again now (current head, effective appointment, not a person the record
        // concerns), so a former head is refused here; AssigneeResolver::stillQualifies gives the same answer.
        $supervision = $this->supervision($instance);
        abort_unless($supervisor->is_active && $supervision !== null && $supervision['supervisor_id'] === (int) $supervisor->id, 403,
            'إسناد هذه المرحلة من صلاحية رئيس القسم المشرف عليها حاليًا.');

        return [$instance, $supervision['step']];
    }

    /**
     * Moves the step to one holder: the previous holders leave it, the event and the audit row are written, and
     * the people concerned are told. Runs inside the caller's transaction, with the instance locked.
     */
    private function handOver(object $instance, object $step, User $supervisor, int $toUserId, string $action, ?string $note, ?string $ipAddress): void
    {
        $now = now();
        $instanceId = (int) $instance->id;
        $supervisorId = (int) $supervisor->id;
        $requesterId = (int) $instance->requested_by;
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $revision = (int) $instance->revision + 1;

        $previous = DB::table('workflow_participants')->where('workflow_instance_id', $instanceId)->where('participation_type', 'assignee')->where('is_active', true)
            ->whereNotIn('user_id', $this->escalated($instance, $step))
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        // A holder's row closed by that same holder is how a step they acted on is told apart (SelfEvaluation).
        // Handing a task over is not acting on it, so the supervisor's own row is closed without a name.
        foreach ([[array_diff($previous, [$supervisorId]), $supervisorId], [array_intersect($previous, [$supervisorId]), null]] as [$userIds, $revokedBy]) {
            DB::table('workflow_participants')->where('workflow_instance_id', $instanceId)->where('participation_type', 'assignee')->whereIn('user_id', $userIds)
                ->update(['is_active' => false, 'revoked_at' => $now, 'revoked_by' => $revokedBy, 'updated_at' => $now]);
        }
        $this->participate($instanceId, $toUserId, 'assignee', $toUserId === $requesterId ? 'public_status' : 'operational', $supervisorId);
        if ($supervisorId !== $requesterId) {
            // Like anyone who handled a step, the supervisor keeps operational sight of what they assigned.
            $this->participate($instanceId, $supervisorId, 'handler', 'operational', $supervisorId);
        }

        DB::table('workflow_instances')->where('id', $instanceId)->update(['current_assignee_id' => $toUserId, 'revision' => $revision, 'updated_at' => $now]);
        DB::table('workflow_events')->insert([
            'workflow_instance_id' => $instanceId, 'workflow_transition_id' => null,
            'from_step_id' => $step->id, 'to_step_id' => $step->id,
            'actor_id' => $supervisorId, 'assigned_to_id' => $toUserId,
            'action' => $action, 'comment' => $note,
            'metadata' => json_encode(['from_user_ids' => $previous, 'to_user_id' => $toUserId], JSON_UNESCAPED_UNICODE),
            'occurred_at' => $now, 'created_at' => $now,
        ]);
        $this->audit->recordRow('workflow.instance.'.$action, 'workflow_instances', $instanceId,
            ['holder_ids' => $previous],
            ['holder_id' => $toUserId, 'step' => $step->code, 'reference' => $instance->reference_no, 'note' => $note],
            $supervisorId, $ipAddress);

        $title = $this->title($instance);
        $where = $step->name.' · '.($instance->reference_no ?? '');
        if ($toUserId !== $supervisorId) {
            // A suspended request arrives suspended: its new holder resumes it before acting.
            $held = $instance->held_at !== null ? ' · معلقة حتى '.$instance->hold_until.'، تُستأنف قبل الإجراء' : '';
            $this->notifications->notify($toUserId, 'workflow_task_assigned', [
                'title' => 'مهمة مسندة إليك: '.$title,
                'body' => 'أسندها: '.$supervisor->name.' · '.$where.$held,
                'url' => $this->subjects->url($instance),
                'dedupe' => 'wf:'.$instanceId.':r'.$revision.':assigned',
            ]);
        }
        // The link opens «مهامي»: the request itself is no longer theirs to open.
        $this->notifications->notifyMany(array_diff($previous, [$supervisorId, $toUserId]), self::EVENT_WITHDRAWN, [
            'title' => 'سُحبت منك مهمة: '.$title,
            'body' => $where.' · '.($action === self::ACTION_WITHDRAWN ? 'عادت إلى '.$supervisor->name : 'أُسندت إلى موظف آخر'),
            'url' => route('tasks.index', [], false),
            'dedupe' => 'wf:'.$instanceId.':r'.$revision.':withdrawn',
        ]);
    }

    /**
     * Holders added when the step's deadline passed ("escalate_to_role_code") stay on the step whoever it is handed to.
     *
     * @return list<int>
     */
    private function escalated(object $instance, object $step): array
    {
        $role = $this->assignees->config($step)['escalate_to_role_code'] ?? null;
        if (! $role || $instance->sla_breached_at === null) {
            return [];
        }

        return $this->assignees->candidates((object) [
            'assignment_type' => 'role', 'assigned_role_code' => $role, 'assignment_config' => ['department_scope' => 'any'],
        ], $instance);
    }

    /** A digital assignment event, as opposed to a move between steps. */
    private function isAssignment(object $event): bool
    {
        return $event->workflow_transition_id === null && $event->action === self::ACTION_ASSIGNED;
    }

    /**
     * Who gave the step to its holder: the supervisor who assigned it, or the actor who picked the holder.
     * Any other way of reaching the step (the same person as an earlier step, a reopening) names nobody.
     */
    private function assigner(object $event, string $assignmentType): ?int
    {
        $named = $this->isAssignment($event) || ($event->workflow_transition_id !== null && $assignmentType === 'pick');

        return $named && $event->actor_id !== null ? (int) $event->actor_id : null;
    }

    private function title(object $instance): string
    {
        $title = $instance->subject_type === WorkflowEngine::SUBJECT_TYPE
            ? DB::table('aims_requests')->where('id', $instance->subject_id)->value('title')
            : ($this->subjects->titles(collect([$instance]))[$instance->subject_type][(int) $instance->subject_id] ?? null);

        return (string) ($title ?: $instance->reference_no);
    }

    /**
     * The department with its active units, at any depth.
     *
     * @return list<int>
     */
    private function withUnits(int $departmentId): array
    {
        $children = DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [];
        $queue = [$departmentId];
        while ($queue !== []) {
            $id = (int) array_shift($queue);
            if (isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            foreach ($children->get($id, []) as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return array_keys($ids);
    }

    private function participate(int $instanceId, int $userId, string $type, string $visibility, int $addedBy): void
    {
        $now = now();
        $existing = DB::table('workflow_participants')
            ->where('workflow_instance_id', $instanceId)->where('user_id', $userId)->where('participation_type', $type)->first();
        if ($existing) {
            DB::table('workflow_participants')->where('id', $existing->id)->update([
                'is_active' => true, 'visibility_scope' => $visibility, 'revoked_at' => null, 'revoked_by' => null, 'updated_at' => $now,
            ]);

            return;
        }
        DB::table('workflow_participants')->insert([
            'workflow_instance_id' => $instanceId, 'user_id' => $userId, 'participation_type' => $type, 'visibility_scope' => $visibility,
            'is_active' => true, 'added_by' => $addedBy, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
