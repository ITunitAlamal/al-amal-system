<?php

namespace App\Services\Maintenance;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance addenda (SRS 3.5.3.3, MT03, T07; HR requirements §8 «معاملة ملحقة»). Extra work found during execution
 * becomes its own «طلب صيانة», linked to the original (aims_requests.parent_request_id), with its own needs, cost and
 * approvals on the published MAINTENANCE workflow; the original request is never changed by it.
 *
 * - Who opens one: the technician who examined the request, or whoever holds the HR head's stage of its workflow,
 *   while the original is at an execution or verification stage.
 * - A required addendum (blocks_parent_closure) keeps the original from closing until it is completed, rejected or
 *   cancelled, unless the holder of the closing stage records a separation decision with its reason and the person
 *   who follows the addendum up (aims_request_separations). The check is the end-step effect CLOSURE_EFFECT
 *   (App\Services\Workflow\AimsRequestEffects), so it runs inside the closing move and a refusal undoes it.
 */
final class MaintenanceAddenda
{
    public const DEFINITION = 'MAINTENANCE';

    public const CLOSURE_EFFECT = 'maintenance_addenda_closure';

    /** Stages «أثناء التنفيذ» at which an addendum may be opened. */
    private const OPEN_STAGES = ['execution', 'verification', 'ext_execution', 'ext_verification'];

    /** Stages whose holder is the technician or HR doing the work, who may open an addendum while holding them. */
    private const WORK_STAGES = ['execution', 'ext_execution'];

    private const CLOSED = ['completed', 'rejected', 'cancelled'];

    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly WorkflowEngine $engine,
        private readonly AuditService $audit,
        private readonly NotificationCenter $notifications,
    ) {}

    /**
     * Whether the user may open an addendum to this request now.
     */
    public function canOpen(object $instance, User $user): bool
    {
        if (! $user->is_active || $instance->status !== 'in_progress' || ! $this->isMaintenance($instance) || ! $this->versionGuards((int) $instance->workflow_version_id)) {
            return false;
        }
        $step = DB::table('workflow_steps')->where('id', $instance->current_step_id)->first(['code']);
        if (! $step || ! in_array($step->code, self::OPEN_STAGES, true)) {
            return false;
        }
        if (in_array($step->code, self::WORK_STAGES, true) && $this->engine->isCurrentAssignee((int) $instance->id, $user)) {
            return true;
        }
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $instance->workflow_version_id)->whereIn('code', ['technician', 'hr_head'])->get()->keyBy('code');
        // The technician who examined the request, while still holding the technician's role.
        if (isset($steps['technician']) && $this->lastActorOf($instance, 'technician') === (int) $user->id
            && $this->assignees->stillQualifies($steps['technician'], $instance, $user)) {
            return true;
        }

        return isset($steps['hr_head']) && $this->assignees->stillQualifies($steps['hr_head'], $instance, $user);
    }

    /**
     * The original request shown on the «طلب صيانة» form when an addendum is opened from it.
     *
     * @return array{publicId: string, referenceNo: string, title: string}
     */
    public function formParent(string $publicId, User $user): array
    {
        $instance = DB::table('workflow_instances')->where('public_id', $publicId)->first();
        abort_unless($instance && $this->canOpen($instance, $user), 403, 'لا يمكنك فتح طلب ملحق لهذا الطلب الآن.');

        return [
            'publicId' => (string) $instance->public_id,
            'referenceNo' => (string) $instance->reference_no,
            'title' => (string) DB::table('aims_requests')->where('id', $instance->subject_id)->value('title'),
        ];
    }

    /**
     * Links a request just started on MAINTENANCE to its original. Runs inside the caller's transaction, right after
     * WorkflowEngine::start(): a refusal leaves no request behind. The original's row lock serialises this with the
     * closing move.
     */
    public function attach(object $child, string $parentPublicId, User $user, bool $required): void
    {
        $parent = DB::table('workflow_instances')->where('public_id', $parentPublicId)->lockForUpdate()->first();
        abort_unless($parent && $this->canOpen($parent, $user), 403, 'لا يمكنك فتح طلب ملحق لهذا الطلب الآن.');
        abort_unless($child->subject_type === WorkflowEngine::SUBJECT_TYPE && $this->isMaintenance($child), 422, 'الطلب الملحق يكون «طلب صيانة».');

        DB::table('aims_requests')->where('id', $child->subject_id)->update([
            'parent_request_id' => $parent->subject_id, 'blocks_parent_closure' => $required, 'updated_at' => now(),
        ]);
        $this->audit->event('maintenance.addendum.opened', [
            'subject_type' => 'aims_requests', 'subject_id' => (string) $child->subject_id, 'subject_ref' => $child->reference_no,
            'actor_id' => $user->id, 'ip' => request()?->ip(),
            'after' => ['parent' => $parent->reference_no, 'parent_request_id' => (int) $parent->subject_id, 'required' => $required],
        ]);

        $holders = DB::table('workflow_participants')->where('workflow_instance_id', $parent->id)->where('participation_type', 'assignee')
            ->where('is_active', true)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $this->notifications->notifyMany(array_diff($holders, [(int) $user->id]), 'maintenance_addendum_opened', [
            'title' => 'طلب ملحق '.$child->reference_no.' مرتبط بالطلب '.$parent->reference_no,
            'body' => $required ? 'ملحق لازم: لا يُغلق الطلب الأصلي قبل معالجته.' : 'ملحق غير مانع للإغلاق.',
            'url' => route('workflows.show', ['publicId' => $parent->public_id], false), 'dedupe' => 'addendum:'.$child->id,
        ]);
    }

    /**
     * The addenda panel of a maintenance request page: its original (when it is an addendum), its addenda, and what
     * the viewer may do. Null for other request types.
     *
     * @return array<string, mixed>|null
     */
    public function panel(object $instance, User $user, string $level): ?array
    {
        // Until migration 2026_11_30_000038 has run, the request page shows no addenda panel.
        if (! $this->isMaintenance($instance) || ! Schema::hasTable('aims_request_separations')) {
            return null;
        }
        $public = $level === 'public_status';
        $request = DB::table('aims_requests')->where('id', $instance->subject_id)->first(['id', 'parent_request_id', 'blocks_parent_closure']);
        $parent = $request?->parent_request_id ? DB::table('workflow_instances as i')->join('aims_requests as r', 'r.id', '=', 'i.subject_id')
            ->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE)->where('i.subject_id', $request->parent_request_id)
            ->first(['i.public_id', 'i.reference_no', 'r.title']) : null;

        $children = DB::table('aims_requests as r')
            ->join('workflow_instances as i', fn ($join) => $join->on('i.subject_id', '=', 'r.id')->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE))
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->leftJoin('aims_request_separations as x', 'x.child_request_id', '=', 'r.id')
            ->leftJoin('users as f', 'f.id', '=', 'x.follow_up_user_id')
            ->leftJoin('users as d', 'd.id', '=', 'x.decided_by')
            ->where('r.parent_request_id', $instance->subject_id)->orderBy('r.id')
            ->get(['i.public_id', 'i.reference_no', 'r.title', 'r.status', 'r.blocks_parent_closure', 's.name as step_name',
                'x.id as separation_id', 'x.reason', 'x.decided_at', 'f.name as follow_up_name', 'd.name as decided_by_name']);

        $canSeparate = ! $public && $this->closesHere($instance, $user);
        $rows = $children->map(fn (object $row): array => [
            'publicId' => $row->public_id,
            'referenceNo' => $row->reference_no,
            'title' => $row->title,
            'status' => $row->status,
            'open' => ! in_array($row->status, self::CLOSED, true),
            'stage' => in_array($row->status, self::CLOSED, true) ? null : $row->step_name,
            'required' => (bool) $row->blocks_parent_closure,
            'separation' => $row->separation_id === null ? null : [
                'reason' => $public ? null : $row->reason, 'at' => $row->decided_at,
                'followUp' => $public ? null : $row->follow_up_name, 'by' => $public ? null : $row->decided_by_name,
            ],
            'canSeparate' => $canSeparate && $row->separation_id === null && (bool) $row->blocks_parent_closure && ! in_array($row->status, self::CLOSED, true),
        ])->all();

        $canOpen = $instance->status === 'in_progress' && $this->canOpen($instance, $user);
        $needsPeople = collect($rows)->contains(fn (array $row): bool => $row['canSeparate']);
        if (! $parent && $rows === [] && ! $canOpen) {
            return null;
        }

        return [
            'parent' => $parent ? ['publicId' => $parent->public_id, 'referenceNo' => $parent->reference_no, 'title' => $parent->title,
                'required' => (bool) $request->blocks_parent_closure] : null,
            'children' => $rows,
            'openUrl' => $canOpen ? route('requests.create', ['code' => self::DEFINITION, 'parent' => $instance->public_id], false) : null,
            'followUpOptions' => $needsPeople ? DB::table('users')->where('is_active', true)->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn (object $u): array => ['id' => (int) $u->id, 'name' => $u->name])->all() : [],
        ];
    }

    /**
     * The separation decision («قرار فصل موثق»): the holder of the closing stage lets the original close while a
     * required addendum is still open, naming the reason and the person who follows the addendum up.
     */
    public function separate(string $parentPublicId, string $childPublicId, User $user, string $reason, int $followUpUserId): void
    {
        DB::transaction(function () use ($parentPublicId, $childPublicId, $user, $reason, $followUpUserId): void {
            $parent = DB::table('workflow_instances')->where('public_id', $parentPublicId)->lockForUpdate()->first();
            abort_unless($parent, 404);
            abort_unless($this->closesHere($parent, $user), 403, 'قرار الفصل لصاحب مرحلة إغلاق الطلب الأصلي عندما تكون المعاملة لديه.');
            $child = DB::table('workflow_instances as i')->join('aims_requests as r', 'r.id', '=', 'i.subject_id')
                ->where('i.public_id', $childPublicId)->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE)
                ->where('r.parent_request_id', $parent->subject_id)->lockForUpdate()
                ->first(['r.id', 'r.status', 'r.blocks_parent_closure', 'i.reference_no', 'i.public_id']);
            abort_unless($child, 404, 'الطلب الملحق غير مرتبط بهذا الطلب.');
            if (in_array($child->status, self::CLOSED, true) || ! $child->blocks_parent_closure) {
                throw ValidationException::withMessages(['separation' => 'هذا الملحق لا يمنع إغلاق الطلب الأصلي؛ لا يلزم قرار فصل.']);
            }
            if (DB::table('aims_request_separations')->where('child_request_id', $child->id)->exists()) {
                throw ValidationException::withMessages(['separation' => 'سُجّل قرار فصل لهذا الملحق من قبل.']);
            }
            $followUp = DB::table('users')->where('id', $followUpUserId)->where('is_active', true)->first(['id', 'name']);
            if (! $followUp) {
                throw ValidationException::withMessages(['follow_up_user_id' => 'اختر مسؤول متابعة نشطًا.']);
            }

            $now = now();
            $id = DB::table('aims_request_separations')->insertGetId([
                'parent_request_id' => $parent->subject_id, 'child_request_id' => $child->id, 'reason' => $reason,
                'follow_up_user_id' => $followUp->id, 'decided_by' => $user->id, 'decided_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->event('maintenance.addendum.separated', [
                'subject_type' => 'aims_request_separations', 'subject_id' => (string) $id, 'subject_ref' => $child->reference_no,
                'actor_id' => $user->id, 'ip' => request()?->ip(),
                'after' => ['parent' => $parent->reference_no, 'child' => $child->reference_no, 'reason' => $reason, 'follow_up_user_id' => (int) $followUp->id],
            ]);
            if ((int) $followUp->id !== (int) $user->id) {
                $this->notifications->notify((int) $followUp->id, 'maintenance_addendum_followup', [
                    'title' => 'كُلّفت بمتابعة الطلب الملحق '.$child->reference_no,
                    'body' => 'فُصل عن إغلاق الطلب '.$parent->reference_no.': '.mb_substr($reason, 0, 200),
                    'url' => route('workflows.show', ['publicId' => $child->public_id], false), 'dedupe' => 'addendum-separation:'.$id,
                ]);
            }
        });
    }

    /**
     * The end-step check: a request does not close while a required addendum is open without a separation decision.
     */
    public function assertClosable(object $instance): void
    {
        $open = DB::table('aims_requests as r')
            ->join('workflow_instances as i', fn ($join) => $join->on('i.subject_id', '=', 'r.id')->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE))
            ->where('r.parent_request_id', $instance->subject_id)->where('r.blocks_parent_closure', true)
            ->whereNotIn('r.status', self::CLOSED)
            ->whereNotExists(fn ($q) => $q->from('aims_request_separations as x')->whereColumn('x.child_request_id', 'r.id'))
            ->orderBy('r.id')->pluck('i.reference_no');
        if ($open->isNotEmpty()) {
            throw ValidationException::withMessages(['transition' => 'لا يُغلق الطلب وفيه ملحق لازم مفتوح: '.$open->implode('، ')
                .'. انتظر معالجته، أو سجّل قرار فصل يحدد سبب الإغلاق ومسؤول متابعة الملحق.']);
        }
    }

    /** The user holds the request's current stage and one of its actions closes it through the addenda check. */
    private function closesHere(object $instance, User $user): bool
    {
        if ($instance->status !== 'in_progress' || ! $this->isMaintenance($instance) || ! $this->engine->isCurrentAssignee((int) $instance->id, $user)) {
            return false;
        }

        return DB::table('workflow_transitions as t')->join('workflow_steps as s', 's.id', '=', 't.to_step_id')
            ->where('t.workflow_version_id', $instance->workflow_version_id)->where('t.from_step_id', $instance->current_step_id)
            ->where('t.is_active', true)->where('s.step_type', 'end')->get(['s.assignment_type', 's.assignment_config'])
            ->contains(fn (object $step): bool => ($this->assignees->config($step)['effect'] ?? null) === self::CLOSURE_EFFECT);
    }

    /** Only a version whose closing step runs the addenda check takes addenda, so none can be closed over. */
    private function versionGuards(int $versionId): bool
    {
        return DB::table('workflow_steps')->where('workflow_version_id', $versionId)->where('step_type', 'end')
            ->get(['assignment_type', 'assignment_config'])
            ->contains(fn (object $step): bool => ($this->assignees->config($step)['effect'] ?? null) === self::CLOSURE_EFFECT);
    }

    private function isMaintenance(object $instance): bool
    {
        return ($instance->subject_type ?? null) === WorkflowEngine::SUBJECT_TYPE
            && DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('v.id', $instance->workflow_version_id)->value('d.code') === self::DEFINITION;
    }

    /** Who last moved the request on from the given stage (e.g. the technician who examined it). */
    private function lastActorOf(object $instance, string $stepCode): ?int
    {
        $id = DB::table('workflow_events as e')->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')
            ->where('e.workflow_instance_id', $instance->id)->whereNotNull('e.workflow_transition_id')->where('s.code', $stepCode)
            ->orderByDesc('e.id')->value('e.actor_id');

        return $id === null ? null : (int) $id;
    }
}
