<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Runs requests through published workflow versions (SRS §79).
 *
 * Every request stays on the version it started with. Each move between steps
 * is one transaction: field values, the step change, the new assignees and the
 * timeline event are written together or not at all.
 *
 * A request is either a generic aims_request created from «طلباتي», or a module record (for example a
 * leave request) whose module applies its own effect on each move through a WorkflowSubject handler.
 */
final class WorkflowEngine
{
    public const SUBJECT_TYPE = 'aims_request';

    /** Actions that send a request back to a stage it already left, undoing what was recorded since (clearRewoundPass). */
    private const REWINDING_ACTIONS = ['return', 'reject', 'field_cancel'];

    /**
     * Facts about the requester that every transition condition can test, besides the request's own fields.
     * A start step may have several conditional send actions; the first whose conditions hold is used.
     *
     * @var array<string, string>
     */
    public const REQUESTER_CONTEXT = [
        'requester_has_department_head' => 'لمقدم الطلب رئيس قسم أعلى منه (غير نفسه)',
        'requester_is_executive' => 'مقدم الطلب هو المدير التنفيذي',
    ];

    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly ConditionEvaluator $conditions,
        private readonly CustomFieldService $fields,
        private readonly WorkflowCatalog $catalog,
        private readonly NotificationCenter $notifications,
        private readonly WorkflowSubjects $subjects,
        private readonly ApprovalDelegations $delegations,
        private readonly StepAssignments $assignments,
    ) {}

    /**
     * Create a request and send it from the start step.
     *
     * @param  array{title: string, description?: ?string}  $request
     * @param  array<string, mixed>  $fieldInput
     */
    public function start(User $requester, string $definitionCode, array $request, array $fieldInput, ?int $pickedUserId = null): object
    {
        $definition = $this->catalog->startableDefinition($definitionCode);
        $version = $definition ? $this->catalog->publishedVersion((int) $definition->id) : null;
        if (! $definition || ! $version) {
            throw ValidationException::withMessages(['workflow' => 'نوع الطلب غير متاح حاليًا.']);
        }
        if (! $requester->is_active || ! $requester->department_id) {
            throw ValidationException::withMessages(['workflow' => 'يجب أن يكون حسابك نشطًا ومرتبطًا بقسم لتقديم الطلبات.']);
        }

        $definitions = $this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, $definition->code);
        $values = $this->fields->validate($definitions, $fieldInput);

        return DB::transaction(function () use ($requester, $definition, $version, $request, $definitions, $values, $pickedUserId): object {
            $now = now();
            $requestId = DB::table('aims_requests')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'request_type' => $definition->code,
                'title' => $request['title'],
                'description' => $request['description'] ?? null,
                'requested_by' => $requester->id,
                'department_id' => $requester->department_id,
                'status' => 'submitted',
                'revision' => 1,
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fields->save(CustomFieldEntities::AIMS_REQUEST, $requestId, $definitions, $values, $requester->id);

            return $this->launch($requester, $definition, $version, self::SUBJECT_TYPE, $requestId, (int) $requester->department_id, $pickedUserId);
        });
    }

    /**
     * Start the workflow of a record its module already saved (for example a leave request).
     * The definition must belong to that record type; such definitions are never offered in «طلباتي».
     */
    public function startForSubject(User $requester, string $definitionCode, string $subjectType, int $subjectId, int $departmentId, ?int $pickedUserId = null): object
    {
        $definition = DB::table('workflow_definitions')
            ->where('code', $definitionCode)
            ->where('subject_type', $subjectType)
            ->where('is_active', true)
            ->first();
        $version = $definition ? $this->catalog->publishedVersion((int) $definition->id) : null;
        if (! $definition || ! $version) {
            throw ValidationException::withMessages(['workflow' => 'مسار العمل المحدد لهذه المعاملة غير منشور أو غير نشط.']);
        }
        if (! $requester->is_active || $departmentId <= 0) {
            throw ValidationException::withMessages(['workflow' => 'يجب أن يكون الحساب نشطًا ومرتبطًا بقسم لتقديم المعاملة.']);
        }

        return DB::transaction(fn (): object => $this->launch($requester, $definition, $version, $subjectType, $subjectId, $departmentId, $pickedUserId));
    }

    /**
     * Actions the user can take on the request right now. Empty when it is not their turn.
     *
     * @return list<array<string, mixed>>
     */
    public function availableActions(object $instance, User $user): array
    {
        if ($instance->status !== 'in_progress' || ($instance->held_at ?? null) !== null || ! $user->is_active || ! $this->holdsAssigneeRow((int) $instance->id, $user)) {
            return [];
        }
        $currentStep = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
        if (! $currentStep || ! $this->assignees->stillQualifies($currentStep, $instance, $user)) {
            if ($currentStep && $this->delegations->actsOnLapsedDelegation($instance, $currentStep, (int) $user->id)) {
                // The delegation ended but the scheduled check has not run yet: hand the request back now.
                $this->delegations->closeLapsed();
            }

            return [];
        }
        $requesterContext = $this->requesterContext($instance);

        return DB::table('workflow_transitions as t')
            ->join('workflow_steps as s', 's.id', '=', 't.to_step_id')
            ->where('t.workflow_version_id', $instance->workflow_version_id)
            ->where('t.from_step_id', $instance->current_step_id)
            ->where('t.is_active', true)
            ->orderBy('t.id')
            ->get(['t.id', 't.action_code', 't.name', 't.requires_comment', 't.conditions', 's.id as step_id', 's.code as step_code', 's.name as to_step_name', 's.step_type', 's.assignment_type', 's.assigned_role_code', 's.assignment_config'])
            // Routes that depend only on who the requester is (e.g. "send to the executive director when there is no head") are shown only when they apply.
            ->reject(fn (object $transition): bool => $this->failsOnRequester($this->decode($transition->conditions), $requesterContext))
            ->values()
            ->map(function (object $transition) use ($instance): array {
                $needsPick = $transition->assignment_type === 'pick';
                $step = (object) [
                    'code' => $transition->step_code,
                    'assignment_type' => $transition->assignment_type,
                    'assigned_role_code' => $transition->assigned_role_code,
                    'assignment_config' => $transition->assignment_config,
                ];

                return [
                    'id' => (int) $transition->id,
                    'action_code' => $transition->action_code,
                    'name' => $transition->name,
                    'requires_comment' => (bool) $transition->requires_comment,
                    'has_conditions' => ! empty($this->decode($transition->conditions)),
                    'to_step_name' => $transition->to_step_name,
                    'ends_request' => $transition->step_type === 'end',
                    // The action hands the request to its requester, who then reads what was sent with it.
                    'to_requester' => $transition->assignment_type === 'requester',
                    'needs_pick' => $needsPick,
                    'pick_options' => $needsPick ? $this->assignees->pickOptions($step, $instance) : [],
                    // A step the request already passed goes back to whoever handled it, unless someone else is chosen.
                    'pick_default_id' => $needsPick ? $this->assignees->previousHolder($step, $instance) : null,
                ];
            })
            ->all();
    }

    /**
     * Apply a transition chosen by the current assignee.
     *
     * @param  array<string, mixed>  $fieldInput  values for fields attached to the current step
     */
    public function act(int $instanceId, User $actor, int $transitionId, ?string $comment, array $fieldInput, ?int $pickedUserId, int $expectedRevision): void
    {
        DB::transaction(function () use ($instanceId, $actor, $transitionId, $comment, $fieldInput, $pickedUserId, $expectedRevision): void {
            $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
            abort_unless($instance, 404);

            if ($instance->status !== 'in_progress') {
                throw ValidationException::withMessages(['transition' => 'المعاملة مغلقة ولا تقبل إجراءات جديدة.']);
            }
            if ((int) $instance->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['transition' => 'تم تحديث المعاملة من مستخدم آخر. أعد تحميل الصفحة ثم حاول مجددًا.']);
            }
            if ($instance->held_at !== null) {
                throw ValidationException::withMessages(['transition' => 'المعاملة معلقة حتى '.$instance->hold_until.'؛ استأنفها أولًا ثم نفّذ الإجراء.']);
            }
            abort_unless($actor->is_active && $this->holdsAssigneeRow($instanceId, $actor), 403, 'هذه المرحلة ليست مسندة إليك.');
            $currentStep = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
            $this->assertStillQualifies($instance, $currentStep, $actor);

            $transition = DB::table('workflow_transitions')
                ->where('id', $transitionId)
                ->where('workflow_version_id', $instance->workflow_version_id)
                ->where('from_step_id', $instance->current_step_id)
                ->where('is_active', true)
                ->first();
            if (! $transition) {
                throw ValidationException::withMessages(['transition' => 'هذا الإجراء غير متاح في المرحلة الحالية.']);
            }
            $comment = $comment !== null ? trim($comment) : null;
            if ($transition->requires_comment && ($comment === null || $comment === '')) {
                throw ValidationException::withMessages(['comment' => 'هذا الإجراء يتطلب كتابة السبب أو الملاحظة.']);
            }

            $recorded = [];
            if ($instance->subject_type === self::SUBJECT_TYPE) {
                $definitionCode = $this->definitionCode((int) $instance->workflow_version_id);
                $definitions = $this->fields->definitions(CustomFieldEntities::AIMS_REQUEST, $definitionCode, $currentStep->code);
                $existing = $this->fields->context(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id);
                // Cancelling a field job goes back like a send-back: the step's own fields are not demanded.
                $sendsBack = in_array($transition->action_code, ['return', 'reject', 'field_cancel'], true);
                // A step visited again keeps what it already holds: a field that was not submitted is not wiped.
                // A field of one action ("action_codes") records that action, so it is never carried over from
                // an earlier pass: it must be entered with the action each time.
                $carried = $definitions->filter(fn (object $definition): bool => $this->fields->actionCodes($definition) === [])->pluck('code')->flip()->all();
                $fieldInput += array_filter(array_intersect_key($existing, $carried), fn ($value): bool => $value !== null);
                // Fields tied to other actions ("action_codes") are neither validated nor saved with this one.
                $values = $this->fields->validate($definitions, $fieldInput, $existing, ! $sendsBack, (string) $transition->action_code);
                // Sending back must not wipe what was already entered on this step.
                $values = $sendsBack ? array_filter($values, fn ($value): bool => $value !== null && $value !== false) : $values;
                if (! $sendsBack) {
                    // Document numbers given with a hand-off must match the AIMS records they name, where those exist.
                    app(AimsRequestReferences::class)->assert($instance, $definitionCode, $values);
                }
                // A cancelled or sent-back pass stops being the request's current state (before this action saves its own).
                $this->clearRewoundPass($instance, $transition, $definitionCode, $actor->id);
                $this->fields->save(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id, $definitions, $values, $actor->id);
                // A copy kept with the event, so each pass keeps its own worker, time or document number.
                $recorded = $this->fields->actionRecord($definitions, $values, (string) $transition->action_code);
                $conditionContext = $this->fields->conditionContext(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id);
            } else {
                $conditionContext = $this->subject($instance)->conditionContext($instance);
            }
            $conditionContext += $this->requesterContext($instance);

            if (! $this->conditions->passes($this->decode($transition->conditions), $conditionContext)) {
                throw ValidationException::withMessages(['transition' => 'شروط هذا الإجراء غير متحققة بالبيانات الحالية للطلب.']);
            }

            $this->move($instance, $transition, $actor->id, $comment, $pickedUserId, $recorded);
        });
    }

    /**
     * Sending a request back, or cancelling a field job («إلغاء التكليف الميداني»), returns it to a stage it already
     * left. What actions recorded in their own fields since it last left that stage (metadata.fields of those
     * events: the worker of the field job, its completion time, a document number) is no longer current: the
     * stored values are cleared, so the page, the dashboards, conditions and indicators stop reading them. The
     * events keep every value, and an audit row names the cleared fields; nothing recorded is rewritten.
     */
    private function clearRewoundPass(object $instance, object $transition, string $definitionCode, int $actorId): void
    {
        if (! in_array($transition->action_code, self::REWINDING_ACTIONS, true)) {
            return;
        }
        $lastLeft = DB::table('workflow_events')->where('workflow_instance_id', $instance->id)
            ->where('from_step_id', $transition->to_step_id)->whereNotNull('workflow_transition_id')->max('id');
        if ($lastLeft === null) {
            return; // the target was never left before: nothing is being undone
        }
        $events = DB::table('workflow_events')->where('workflow_instance_id', $instance->id)->where('id', '>=', $lastLeft)
            ->whereNotNull('workflow_transition_id')->whereNotNull('metadata')->orderBy('id')->get(['id', 'metadata']);
        $codes = [];
        $eventIds = [];
        foreach ($events as $event) {
            foreach (CustomFieldService::eventFields($event->metadata) as $field) {
                $codes[$field['code']] = true;
                $eventIds[(int) $event->id] = true;
            }
        }
        $cleared = $this->fields->clearValues(CustomFieldEntities::AIMS_REQUEST, (int) $instance->subject_id, $definitionCode, array_keys($codes), $actorId);
        if ($cleared !== []) {
            // Codes only: the values stay readable in the events named here, under their own visibility.
            app(AuditService::class)->event('workflow.fields.cleared', [
                'subject_type' => 'aims_requests', 'subject_id' => (string) $instance->subject_id, 'subject_ref' => $instance->reference_no,
                'actor_id' => $actorId,
                'metadata' => ['fields' => $cleared, 'action' => $transition->action_code, 'recorded_in_events' => array_keys($eventIds), 'workflow_instance_id' => (int) $instance->id],
            ]);
        }
    }

    /**
     * The requester withdraws a request that is still in progress (the module applies its own cancel policy
     * first). The request closes as cancelled with a timeline event, and leaves every inbox.
     */
    public function cancel(int $instanceId, User $actor, string $reason): void
    {
        DB::transaction(function () use ($instanceId, $actor, $reason): void {
            $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
            abort_unless($instance, 404);
            abort_if(in_array($instance->subject_type, ['official_correspondence', 'correspondence_edition'], true), 403, 'القرارات المسجلة لا تُسحب من مسار الطلبات العام.');
            abort_unless((int) $instance->requested_by === (int) $actor->id, 403, 'إلغاء الطلب متاح لمقدمه فقط.');
            if ($instance->status !== 'in_progress') {
                throw ValidationException::withMessages(['transition' => 'المعاملة مغلقة ولا يمكن إلغاؤها.']);
            }

            $now = now();
            DB::table('workflow_participants')->where('workflow_instance_id', $instanceId)->where('participation_type', 'assignee')->where('is_active', true)
                ->update(['is_active' => false, 'revoked_at' => $now, 'revoked_by' => $actor->id, 'updated_at' => $now]);
            DB::table('workflow_instances')->where('id', $instanceId)->update([
                'status' => 'cancelled', 'current_assignee_id' => null, 'completed_at' => $now, 'due_at' => null,
                'held_at' => null, 'hold_until' => null, 'hold_reason' => null, 'held_by' => null,
                'revision' => (int) $instance->revision + 1, 'updated_at' => $now,
            ]);
            if ($instance->subject_type === self::SUBJECT_TYPE) {
                DB::table('aims_requests')->where('id', $instance->subject_id)->update(['status' => 'cancelled', 'completed_at' => $now, 'updated_at' => $now]);
            }
            DB::table('workflow_events')->insert([
                'workflow_instance_id' => $instanceId,
                'workflow_transition_id' => null,
                'from_step_id' => $instance->current_step_id,
                'to_step_id' => $instance->current_step_id,
                'actor_id' => $actor->id,
                'assigned_to_id' => null,
                'action' => 'cancel',
                'comment' => $reason,
                'metadata' => json_encode(['cancelled_by' => 'requester'], JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
        });
    }

    /**
     * The current holder suspends a request while waiting for information, an approval or a resource (ED
     * requirements doc §5 «تعليق»). A reason and a review date are required; the request keeps its stage and
     * holder, its deadline pauses, and no transition is possible until it is resumed. Generic requests only.
     */
    public function hold(int $instanceId, User $actor, string $reason, string $reviewOn, int $expectedRevision): void
    {
        DB::transaction(function () use ($instanceId, $actor, $reason, $reviewOn, $expectedRevision): void {
            $instance = $this->lockedOpenRequest($instanceId, $actor, $expectedRevision);
            if ($instance->held_at !== null) {
                throw ValidationException::withMessages(['hold' => 'المعاملة معلقة بالفعل.']);
            }
            $now = now();
            DB::table('workflow_instances')->where('id', $instanceId)->update([
                'held_at' => $now, 'hold_until' => $reviewOn, 'hold_reason' => $reason, 'held_by' => $actor->id,
                'due_at' => null, 'revision' => (int) $instance->revision + 1, 'updated_at' => $now,
            ]);
            $this->recordEvent($instance, 'hold', $actor->id, $reason, ['review_on' => $reviewOn, 'paused_due_at' => $instance->due_at]);
            $this->notifyRequester($instance, $actor->id, 'workflow_request_held', 'عُلّق طلبك '.$instance->reference_no.' مؤقتًا حتى '.$reviewOn, 'hold:'.$now->timestamp);
        });
    }

    /**
     * The holder resumes a suspended request; the stage deadline starts again from now.
     */
    public function resume(int $instanceId, User $actor, ?string $comment, int $expectedRevision): void
    {
        DB::transaction(function () use ($instanceId, $actor, $comment, $expectedRevision): void {
            $instance = $this->lockedOpenRequest($instanceId, $actor, $expectedRevision);
            if ($instance->held_at === null) {
                throw ValidationException::withMessages(['hold' => 'المعاملة غير معلقة.']);
            }
            $now = now();
            $slaHours = DB::table('workflow_steps')->where('id', $instance->current_step_id)->value('sla_hours');
            DB::table('workflow_instances')->where('id', $instanceId)->update([
                'held_at' => null, 'hold_until' => null, 'hold_reason' => null, 'held_by' => null,
                'due_at' => $slaHours ? $now->copy()->addHours((int) $slaHours) : null, 'sla_breached_at' => null,
                'revision' => (int) $instance->revision + 1, 'updated_at' => $now,
            ]);
            $this->recordEvent($instance, 'resume', $actor->id, $comment, ['held_since' => (string) $instance->held_at]);
            $this->notifyRequester($instance, $actor->id, 'workflow_request_resumed', 'استؤنفت معالجة طلبك '.$instance->reference_no, 'resume:'.$now->timestamp);
        });
    }

    /**
     * The executive director reopens a completed request when a gap is found after closing (ED requirements
     * doc §5 «إعادة فتح»; SRS §3.1.2.3): the reason, the authority and the stage it returns to are recorded.
     * Module records (e.g. an approved leave) are corrected by their own reversal procedures instead.
     */
    public function reopen(int $instanceId, User $actor, int $stepId, string $reason): void
    {
        abort_unless($this->delegations->isExecutive($actor), 403, 'إعادة فتح المعاملة من صلاحية المدير التنفيذي.');

        DB::transaction(function () use ($instanceId, $actor, $stepId, $reason): void {
            $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
            abort_unless($instance, 404);
            abort_unless($instance->subject_type === self::SUBJECT_TYPE, 403, 'إعادة الفتح متاحة للطلبات العامة؛ للمعاملات الأخرى إجراءات تصحيح خاصة بها.');
            if ($instance->status !== 'completed') {
                throw ValidationException::withMessages(['reopen' => 'تُعاد فتح المعاملات المنجزة فقط.']);
            }
            $target = DB::table('workflow_steps')->where('id', $stepId)->where('workflow_version_id', $instance->workflow_version_id)
                ->whereIn('step_type', ['start', 'task', 'decision'])->first();
            if (! $target) {
                throw ValidationException::withMessages(['step_id' => 'اختر مرحلة من مراحل مسار هذه المعاملة.']);
            }
            if (! in_array((int) $target->id, array_map(fn (object $step): int => $step->id, $this->reopenTargets($instance)), true)) {
                throw ValidationException::withMessages(['step_id' => "لا تُعاد المعاملة إلى «{$target->name}»: سبقها صرف أو سند سُجل في مرحلة لاحقة، فإعادتها تكرر الصرف. اختر مرحلة بعدها أو افتح طلبًا جديدًا."]);
            }
            $candidates = $this->assignees->candidates($target, $instance);
            if ($candidates === []) {
                throw ValidationException::withMessages(['step_id' => "لا يوجد مستخدم مؤهل لتنفيذ مرحلة «{$target->name}»."]);
            }

            $now = now();
            $requesterId = (int) $instance->requested_by;
            foreach ($candidates as $candidateId) {
                $this->participate($instanceId, $candidateId, 'assignee', $candidateId === $requesterId ? 'public_status' : 'operational', $actor->id);
            }
            $this->participate($instanceId, $actor->id, 'handler', 'operational', $actor->id);
            DB::table('workflow_instances')->where('id', $instanceId)->update([
                'status' => 'in_progress', 'current_step_id' => $target->id, 'current_assignee_id' => count($candidates) === 1 ? $candidates[0] : null,
                'completed_at' => null, 'due_at' => $target->sla_hours ? $now->copy()->addHours((int) $target->sla_hours) : null, 'sla_breached_at' => null,
                'revision' => (int) $instance->revision + 1, 'updated_at' => $now,
            ]);
            DB::table('aims_requests')->where('id', $instance->subject_id)->update([
                'status' => $target->assignment_type === 'requester' ? 'returned' : 'in_progress', 'completed_at' => null, 'updated_at' => $now,
            ]);
            $this->recordEvent($instance, 'reopen', $actor->id, $reason, ['reopened_status' => 'completed'], (int) $target->id, count($candidates) === 1 ? $candidates[0] : null);

            $url = $this->subjects->url($instance);
            $this->notifications->notifyMany(array_diff($candidates, [$actor->id, $requesterId]), 'workflow_task_assigned', [
                'title' => 'أُعيد فتح معاملة وأُسندت إليك: '.$target->name, 'body' => (string) $instance->reference_no,
                'url' => $url, 'dedupe' => 'wf:'.$instanceId.':reopen:'.$now->timestamp,
            ]);
            $this->notifyRequester($instance, $actor->id, 'workflow_request_reopened', 'أُعيد فتح طلبك '.$instance->reference_no.': '.$target->name, 'reopen:'.$now->timestamp);
        });
    }

    /**
     * The stages a completed request may be reopened at. A stage marked "reopen_locked" in its step configuration
     * records a stock issue, a payment or a receipt (e.g. «صرف المواد», «صرف الأجرة»): once the request has left it,
     * a reopening may only return to a stage the request reached after it, so nothing is issued or paid twice. Without
     * locked stages every non-final stage is offered.
     *
     * @return list<object{id: int, name: string}>
     */
    public function reopenTargets(object $instance): array
    {
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $instance->workflow_version_id)
            ->whereIn('step_type', ['start', 'task', 'decision'])->orderBy('sort_order')->get(['id', 'name', 'assignment_config']);
        $locked = $steps->filter(fn (object $step): bool => ($this->assignees->config($step)['reopen_locked'] ?? false) === true)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $reachedAfter = null;
        if ($locked !== []) {
            $events = DB::table('workflow_events')->where('workflow_instance_id', $instance->id)
                ->where(fn ($query) => $query->whereNotNull('workflow_transition_id')->orWhere('action', 'reopen'))
                ->orderBy('id')->get(['from_step_id', 'to_step_id', 'workflow_transition_id']);
            foreach ($events as $event) {
                if ($event->workflow_transition_id !== null && in_array((int) $event->from_step_id, $locked, true)) {
                    $reachedAfter = [];
                }
                if ($reachedAfter !== null && $event->to_step_id !== null) {
                    $reachedAfter[(int) $event->to_step_id] = true;
                }
            }
        }

        return $steps->filter(fn (object $step): bool => $reachedAfter === null || isset($reachedAfter[(int) $step->id]))
            ->map(fn (object $step): object => (object) ['id' => (int) $step->id, 'name' => $step->name])->values()->all();
    }

    /**
     * A generic request in progress, locked, at the expected revision, with the actor as its current holder.
     */
    private function lockedOpenRequest(int $instanceId, User $actor, int $expectedRevision): object
    {
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
        abort_unless($instance, 404);
        abort_unless($instance->subject_type === self::SUBJECT_TYPE, 403, 'التعليق متاح للطلبات العامة فقط.');
        if ($instance->status !== 'in_progress') {
            throw ValidationException::withMessages(['hold' => 'المعاملة مغلقة.']);
        }
        if ((int) $instance->revision !== $expectedRevision) {
            throw ValidationException::withMessages(['hold' => 'تم تحديث المعاملة من مستخدم آخر. أعد تحميل الصفحة ثم حاول مجددًا.']);
        }
        abort_unless($actor->is_active && $this->holdsAssigneeRow($instanceId, $actor), 403, 'هذه المرحلة ليست مسندة إليك.');
        $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
        $this->assertStillQualifies($instance, $step, $actor);

        return $instance;
    }

    /**
     * The authority behind the actor's place on the step is checked again now (TRANSFORM §3.4): a holder who lost
     * the role, the headship or the delegation is refused, and the scheduled check reroutes the request.
     */
    private function assertStillQualifies(object $instance, ?object $step, User $actor): void
    {
        if ($step && $this->assignees->stillQualifies($step, $instance, $actor)) {
            return;
        }
        abort(403, $step && $this->delegations->actsOnLapsedDelegation($instance, $step, (int) $actor->id)
            ? 'انتهى التفويض الذي أُسندت به هذه المرحلة إليك؛ تعود المعاملة إلى صاحب الصلاحية الأصلي.'
            : 'لم تعد صاحب الصلاحية في هذه المرحلة (تغيّر دورك أو تعيينك أو رئاسة القسم)؛ ستُحال المعاملة إلى صاحب الصلاحية الحالي.');
    }

    /**
     * A timeline event that is not a transition (suspend, resume, reopen).
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(object $instance, string $action, int $actorId, ?string $comment, array $metadata, ?int $toStepId = null, ?int $assignedTo = null): void
    {
        DB::table('workflow_events')->insert([
            'workflow_instance_id' => $instance->id, 'workflow_transition_id' => null,
            'from_step_id' => $instance->current_step_id, 'to_step_id' => $toStepId ?? $instance->current_step_id,
            'actor_id' => $actorId, 'assigned_to_id' => $assignedTo ?? $instance->current_assignee_id,
            'action' => $action, 'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(), 'created_at' => now(),
        ]);
    }

    /** The requester hears of a change on their request, without its internal reason. */
    private function notifyRequester(object $instance, int $actorId, string $event, string $title, string $dedupe): void
    {
        if ((int) $instance->requested_by === $actorId) {
            return;
        }
        $this->notifications->notify((int) $instance->requested_by, $event, [
            'title' => $title, 'body' => 'التفاصيل في سجل المعاملة.', 'url' => $this->subjects->url($instance),
            'dedupe' => 'wf:'.$instance->id.':'.$dedupe,
        ]);
    }

    /**
     * Whether the user holds the request's current step now: an active assignee row and, checked again today,
     * the authority behind it (AssigneeResolver::stillQualifies).
     */
    public function isCurrentAssignee(int $instanceId, User $user): bool
    {
        if (! $user->is_active || ! $this->holdsAssigneeRow($instanceId, $user)) {
            return false;
        }
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->first();
        $step = $instance ? DB::table('workflow_steps')->where('id', $instance->current_step_id)->first() : null;

        return $step !== null && $this->assignees->stillQualifies($step, $instance, $user);
    }

    private function holdsAssigneeRow(int $instanceId, User $user): bool
    {
        return DB::table('workflow_participants')
            ->where('workflow_instance_id', $instanceId)
            ->where('user_id', $user->id)
            ->where('participation_type', 'assignee')
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Create the instance on the published version and send it from the start step. Runs inside the caller's transaction.
     */
    private function launch(User $requester, object $definition, object $version, string $subjectType, int $subjectId, int $departmentId, ?int $pickedUserId): object
    {
        $startStep = DB::table('workflow_steps')
            ->where('workflow_version_id', $version->id)
            ->where('step_type', 'start')
            ->first();
        $startTransitions = $startStep ? DB::table('workflow_transitions')
            ->where('workflow_version_id', $version->id)
            ->where('from_step_id', $startStep->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get() : collect();
        if ($startTransitions->isEmpty()) {
            throw ValidationException::withMessages(['workflow' => 'مسار العمل المنشور غير مكتمل.']);
        }

        $now = now();
        $instanceId = DB::table('workflow_instances')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'workflow_version_id' => $version->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'department_id' => $departmentId,
            'requested_by' => $requester->id,
            'current_step_id' => $startStep->id,
            'current_assignee_id' => $requester->id,
            'status' => 'in_progress',
            'revision' => 1,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $prefix = $definition->reference_prefix ?: strtoupper($definition->code);
        DB::table('workflow_instances')->where('id', $instanceId)->update([
            'reference_no' => sprintf('%s-%s-%05d', $prefix, $now->format('Y'), $instanceId),
        ]);
        $this->participate($instanceId, $requester->id, 'requester', 'public_status', $requester->id);

        $instance = DB::table('workflow_instances')->where('id', $instanceId)->first();
        $context = ($subjectType === self::SUBJECT_TYPE
            ? $this->fields->conditionContext(CustomFieldEntities::AIMS_REQUEST, $subjectId)
            : $this->subject($instance)->conditionContext($instance)) + $this->requesterContext($instance);
        // Conditional routes first (in order), then the unconditional default.
        $firstTransition = $startTransitions
            ->sortBy(fn (object $transition): int => empty($this->decode($transition->conditions)) ? 1 : 0, SORT_REGULAR)
            ->first(fn (object $transition): bool => $this->conditions->passes($this->decode($transition->conditions), $context));
        if (! $firstTransition) {
            throw ValidationException::withMessages(['workflow' => 'لا يوجد مسار إرسال ينطبق على هذا الطلب؛ راجع شروط مرحلة البداية في «مسارات العمل».']);
        }
        $this->move($instance, $firstTransition, $requester->id, null, $pickedUserId);

        return DB::table('workflow_instances')->where('id', $instanceId)->first();
    }

    /**
     * Built-in facts about the requester for transition conditions (see REQUESTER_CONTEXT).
     *
     * @return array<string, bool>
     */
    public function requesterContext(object $instance): array
    {
        $requesterId = (int) $instance->requested_by;

        return [
            'requester_has_department_head' => $this->assignees->hasSuperiorHead((int) $instance->department_id, $requesterId),
            'requester_is_executive' => DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.user_id', $requesterId)->where('r.code', 'executive_director')->whereRaw(EffectiveAssignments::sql('a'))->exists(),
        ];
    }

    /**
     * Whether the conditions test only built-in requester facts and those facts rule the transition out.
     *
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, bool>  $requesterContext
     */
    private function failsOnRequester(?array $conditions, array $requesterContext): bool
    {
        if (empty($conditions) || array_diff_key($conditions, self::REQUESTER_CONTEXT) !== []) {
            return false;
        }

        return ! $this->conditions->passes($conditions, $requesterContext);
    }

    private function subject(object $instance): WorkflowSubject
    {
        return $this->subjects->handler((string) $instance->subject_type)
            ?? throw new RuntimeException('No workflow handler for subject type '.$instance->subject_type);
    }

    /**
     * @param  array<string, array{label: string, value: string, type: string}>  $recorded  what the action recorded in its own fields
     */
    private function move(object $instance, object $transition, int $actorId, ?string $comment, ?int $pickedUserId, array $recorded = []): void
    {
        $now = now();
        $target = DB::table('workflow_steps')->where('id', $transition->to_step_id)->first();
        $from = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first();
        $requesterId = (int) $instance->requested_by;
        $subject = $instance->subject_type === self::SUBJECT_TYPE ? null : $this->subject($instance);
        $subject?->beforeMove($instance, $from, $target, $actorId);

        DB::table('workflow_participants')
            ->where('workflow_instance_id', $instance->id)
            ->where('participation_type', 'assignee')
            ->where('is_active', true)
            ->update(['is_active' => false, 'revoked_at' => $now, 'revoked_by' => $actorId, 'updated_at' => $now]);

        if ($actorId !== $requesterId) {
            // Whoever handled a step keeps operational visibility of the request.
            $this->participate((int) $instance->id, $actorId, 'handler', 'operational', $actorId);
        }

        $update = ['current_step_id' => $target->id, 'revision' => (int) $instance->revision + 1, 'updated_at' => $now];

        // The event is written before the next holders are resolved, so a step assigned to "whoever acted on the
        // step being left now" (actor_of_step) finds this actor. A failed resolution rolls the whole move back.
        $eventId = DB::table('workflow_events')->insertGetId([
            'workflow_instance_id' => $instance->id,
            'workflow_transition_id' => $transition->id,
            'from_step_id' => $instance->current_step_id,
            'to_step_id' => $target->id,
            'actor_id' => $actorId,
            'assigned_to_id' => null,
            'action' => $transition->action_code,
            'comment' => $comment,
            'metadata' => json_encode(['transition' => $transition->name, 'picked_user_id' => $pickedUserId]
                + ($recorded !== [] ? ['fields' => $recorded] : []), JSON_UNESCAPED_UNICODE),
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        if ($target->step_type === 'end') {
            $outcome = ($this->assignees->config($target)['outcome'] ?? 'completed') === 'rejected' ? 'rejected' : 'completed';
            $update += ['status' => $outcome, 'current_assignee_id' => null, 'completed_at' => $now, 'due_at' => null];
            $requestUpdate = ['status' => $outcome, 'completed_at' => $now];
        } else {
            $candidates = $this->assignees->candidates($target, $instance, $pickedUserId);
            if ($candidates === []) {
                throw ValidationException::withMessages([
                    'transition' => $target->assignment_type === 'pick'
                        ? "اختر المسؤول عن مرحلة «{$target->name}» من القائمة."
                        : "لا يوجد مستخدم مؤهل لتنفيذ مرحلة «{$target->name}»؛ يلزم: ".$this->assignees->holderNeeded($target).'. لم يُنفَّذ الإجراء.',
                ]);
            }
            foreach ($candidates as $candidateId) {
                $this->participate((int) $instance->id, $candidateId, 'assignee', $candidateId === $requesterId ? 'public_status' : 'operational', $actorId);
            }
            $update += [
                'current_assignee_id' => count($candidates) === 1 ? $candidates[0] : null,
                'due_at' => $target->sla_hours ? $now->copy()->addHours((int) $target->sla_hours) : null,
                'sla_breached_at' => null,
            ];
            $requestUpdate = ['status' => $target->assignment_type === 'requester' ? 'returned' : 'in_progress'];
        }

        DB::table('workflow_instances')->where('id', $instance->id)->update($update);
        if ($subject === null) {
            DB::table('aims_requests')->where('id', $instance->subject_id)->update([...$requestUpdate, 'updated_at' => $now]);
            // A step may name an effect (e.g. open the purchase in Accounts); a refused effect undoes the move.
            if ($effect = $this->assignees->config($target)['effect'] ?? null) {
                app(AimsRequestEffects::class)->apply((string) $effect, $instance, $actorId);
            }
        }
        if (($update['current_assignee_id'] ?? null) !== null) {
            DB::table('workflow_events')->where('id', $eventId)->update(['assigned_to_id' => $update['current_assignee_id']]);
        }

        $subject?->afterMove($instance, $from, $target, $transition, $actorId, $comment);

        $this->notifyMove($instance, $target, $actorId, $candidates ?? [], (int) $update['revision'], $update['status'] ?? null);
        $this->informRoles($instance, $target, $actorId, (int) $update['revision']);
        // The head who supervises the step being left hears that the person holding it for them acted on it.
        $this->assignments->stepCompleted($instance, $from, $target, $transition, $actorId, $candidates ?? [], (int) $update['revision']);
    }

    /**
     * A step may name roles to inform when the request reaches it ("inform_role_codes"), e.g. telling the
     * executive director the estimated cost of an external service: information only, never an approval
     * (SRS §3.5.4.4). Only roles that can open the request (the executive director sees every request) are useful.
     */
    private function informRoles(object $instance, object $target, int $actorId, int $revision): void
    {
        $roles = $this->assignees->config($target)['inform_role_codes'] ?? [];
        if ($target->step_type === 'end' || ! is_array($roles) || $roles === []) {
            return;
        }
        $userIds = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('r.code', $roles)->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))->distinct()->pluck('a.user_id')->map(fn ($id): int => (int) $id)->all();
        $workflowName = (string) DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $instance->workflow_version_id)->value('d.name');
        $this->notifications->notifyMany(array_diff($userIds, [$actorId]), 'workflow_step_informed', [
            'title' => 'للعلم: '.($instance->reference_no ?? '').' وصلت مرحلة «'.$target->name.'»',
            'body' => $workflowName, 'url' => $this->subjects->url($instance), 'dedupe' => 'wf:'.$instance->id.':r'.$revision.':inform',
        ]);
    }

    /**
     * In-app (and, per central settings, SMS) notices for the people the move concerns.
     * Written in the same transaction as the move, so a failed move notifies no one.
     *
     * @param  list<int>  $candidates
     */
    private function notifyMove(object $instance, object $target, int $actorId, array $candidates, int $revision, ?string $outcome): void
    {
        $reference = (string) ($instance->reference_no ?? '');
        $workflowName = (string) DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $instance->workflow_version_id)->value('d.name');
        $url = $this->subjects->url($instance);
        $requesterId = (int) $instance->requested_by;
        $dedupe = 'wf:'.$instance->id.':r'.$revision;

        if ($target->step_type === 'end') {
            if ($actorId !== $requesterId) {
                $this->notifications->notify($requesterId, 'workflow_request_closed', [
                    'title' => ($outcome === 'rejected' ? 'رُفض طلبك ' : 'اكتمل طلبك ').$reference,
                    'body' => $workflowName, 'url' => $url, 'dedupe' => $dedupe.':closed',
                ]);
            }

            return;
        }

        if ($target->assignment_type === 'requester') {
            if ($actorId !== $requesterId) {
                $this->notifications->notify($requesterId, 'workflow_request_returned', [
                    'title' => 'طلبك '.$reference.' بانتظار إجرائك: '.$target->name,
                    'body' => $workflowName, 'url' => $url, 'dedupe' => $dedupe.':returned',
                ]);
            }

            return;
        }

        $this->notifications->notifyMany(array_diff($candidates, [$actorId]), $this->isUrgent($instance) ? 'workflow_task_urgent' : 'workflow_task_assigned', [
            'title' => 'مهمة بانتظار إجرائك: '.$target->name,
            'body' => $workflowName.' · '.$reference,
            'url' => $url,
            'dedupe' => $dedupe,
        ]);
    }

    /** A request is urgent when one of its priority fields holds an "urgent" or "emergency" constant. */
    public function isUrgent(object $instance): bool
    {
        if ($instance->subject_type !== self::SUBJECT_TYPE) {
            return false;
        }

        return DB::table('custom_field_values as v')
            ->join('master_data_values as m', 'm.id', '=', 'v.value_master_id')
            ->join('master_data_types as t', 't.id', '=', 'm.type_id')
            ->where('v.entity_type', CustomFieldEntities::AIMS_REQUEST)
            ->where('v.entity_id', $instance->subject_id)
            ->where('t.code', 'like', '%priorit%')
            ->whereIn('m.code', ['urgent', 'emergency'])
            ->exists();
    }

    private function participate(int $instanceId, int $userId, string $type, string $visibility, int $addedBy): void
    {
        $now = now();
        $existing = DB::table('workflow_participants')
            ->where('workflow_instance_id', $instanceId)
            ->where('user_id', $userId)
            ->where('participation_type', $type)
            ->first();

        if ($existing) {
            DB::table('workflow_participants')->where('id', $existing->id)->update([
                'is_active' => true, 'visibility_scope' => $visibility, 'revoked_at' => null, 'revoked_by' => null, 'updated_at' => $now,
            ]);

            return;
        }

        DB::table('workflow_participants')->insert([
            'workflow_instance_id' => $instanceId,
            'user_id' => $userId,
            'participation_type' => $type,
            'visibility_scope' => $visibility,
            'is_active' => true,
            'added_by' => $addedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function definitionCode(int $versionId): string
    {
        return (string) DB::table('workflow_versions as v')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $versionId)
            ->value('d.code');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (is_array($json)) {
            return $json;
        }

        return is_string($json) && $json !== '' ? json_decode($json, true) : null;
    }
}
