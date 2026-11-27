<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

final class CorrespondenceWorkflow implements WorkflowSubject
{
    /**
     * The department the executive director chose for the «execute» stage, set by CorrespondenceRegistry::act for the
     * one engine call it makes (the engine passes only the picked person). Null: BoardDecisions::executingDepartment.
     */
    public static ?int $executingDepartment = null;

    public function __construct(private readonly CorrespondenceAccess $access, private readonly CorrespondenceRegistry $registry, private readonly BoardDecisions $decisions) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $letter = DB::table('official_correspondences')->where('id', $instance->subject_id)->lockForUpdate()->first();
        $actor = User::findOrFail($actorId);
        abort_unless($letter && $actor->is_active && ! in_array($letter->status, ['closed', 'archived', 'withdrawn'], true), 403);
        if ($from->step_type === 'start') {
            abort_unless((int) $letter->author_id === $actorId && $this->access->role($actor, 'board_secretary'), 403);

            return;
        }
        $task = DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first();
        abort_unless($task && (int) $task->assigned_to === $actorId && $task->status === 'open' && $this->access->actionVisible($actor, (int) $task->action_id, false), 403);
        $valid = match ($from->code) {
            'register', 'file' => $this->access->role($actor, 'secretariat'),
            'allocate', 'review' => $this->access->role($actor, 'executive_director'),
            'execute' => $this->access->head($actor, (int) $task->department_id),
            'authorized_close' => (int) $letter->author_id === $actorId && $this->access->role($actor, 'board_secretary'),
            default => false,
        };
        abort_unless($valid, 403);
        if ($from->code === 'review') {
            $executor = DB::table('workflow_events as e')->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')->where('e.workflow_instance_id', $instance->id)->where('s.code', 'execute')->orderByDesc('e.id')->value('e.actor_id');
            abort_if((int) $executor === $actorId, 403, 'لا يجوز مراجعة تنفيذك بنفسك.');
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $updated = DB::table('workflow_instances')->find($instance->id);
        $letter = DB::table('official_correspondences')->find($instance->subject_id);
        $actor = User::findOrFail($actorId);
        $task = DB::table('correspondence_tasks')->where('correspondence_id', $letter->id)->first();
        $source = $from->step_type === 'start' ? (int) $letter->department_id : (int) $task->department_id;
        $recipient = null;
        $department = null;
        $stageGrant = false;
        if ($target->step_type !== 'end') {
            abort_unless($updated->current_assignee_id, 422, 'يجب إسناد المرحلة إلى مسؤول محدد.');
            $recipient = User::findOrFail($updated->current_assignee_id);
            $department = $target->assignment_type === 'requester' ? (int) $letter->department_id : (int) $recipient->department_id;
            if ($target->code === 'register') {
                $department = (int) $letter->recipient_department_id;
            }
            if ($target->code === 'execute') {
                // A head of several departments executes for the one chosen (or the one that already executed it).
                $department = $this->decisions->executingDepartment($recipient, (int) $letter->id, self::$executingDepartment) ?? $department;
            }
            $valid = match ($target->code) {
                // Returned for incomplete data: back to the board secretary who recorded the decision.
                'record' => (int) $recipient->id === (int) $letter->author_id && $this->access->role($recipient, 'board_secretary'),
                'register', 'file' => $this->access->role($recipient, 'secretariat'),
                'allocate', 'review' => $this->access->role($recipient, 'executive_director'),
                'execute' => $this->access->head($recipient, $department),
                'authorized_close' => $this->access->role($recipient, 'board_secretary'),
                default => false,
            };
            abort_unless($valid && $this->access->canRoute($actor, $source, $recipient, $department, (int) $letter->type_value_id, (int) $letter->classification_value_id, false), 403, 'المستلم غير مخول بالمرحلة المؤسسية.');
            // The holder of the next stage, checked above against the role the stage requires, is the approved
            // circulation of the decision (SRS 3.6.1/3.6.3): a confidential decision reaches the Secretariat, the
            // executive director and the executing head through its own stages. Ad-hoc forwards and reassignments
            // keep their own refusal (CorrespondenceRegistry::act); the grant records its basis.
            $stageGrant = ! $this->access->visible($recipient, false)->where('c.id', $letter->id)->exists();
        }
        $event = (int) DB::table('workflow_events')->where('workflow_instance_id', $instance->id)->max('id');
        $this->registry->projectWorkflow($actor, $letter, $updated, $event, $transition->action_code, $comment, $source, $recipient, $department, $stageGrant);
        $this->decisions->afterMove($letter, $from, $target, $actorId, $recipient, $department, $event, $updated->due_at ?? $letter->due_at);
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return route('correspondence.record', $subjectId, false);
    }

    public function titles(array $subjectIds): array
    {
        $numbers = $this->decisions->installed() ? DB::table('board_decisions')->whereIn('correspondence_id', $subjectIds)->whereNotNull('recorded_at')->pluck('decision_number', 'correspondence_id') : collect();

        return DB::table('official_correspondences')->whereIn('id', $subjectIds)->pluck('reference', 'id')
            ->map(fn ($reference, $id): string => isset($numbers[$id]) ? 'قرار مجلس الإدارة رقم '.$numbers[$id] : 'مراسلة رسمية '.$reference)->all();
    }
}
