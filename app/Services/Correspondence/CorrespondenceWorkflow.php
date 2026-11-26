<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

final class CorrespondenceWorkflow implements WorkflowSubject
{
    public function __construct(private readonly CorrespondenceAccess $access, private readonly CorrespondenceRegistry $registry) {}

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
        abort_unless($task && (int) $task->assigned_to === $actorId && $task->status === 'open' && $this->access->actionVisible($actor, (int) $task->action_id), 403);
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
        if ($target->step_type !== 'end') {
            abort_unless($updated->current_assignee_id, 422, 'يجب إسناد المرحلة إلى مسؤول محدد.');
            $recipient = User::findOrFail($updated->current_assignee_id);
            $department = $target->assignment_type === 'requester' ? (int) $letter->department_id : (int) $recipient->department_id;
            if ($target->code === 'register') {
                $department = (int) $letter->recipient_department_id;
            }
            if ($target->code === 'execute' && ! $this->access->head($recipient, $department)) {
                $department = (int) $this->access->departments()->first(fn ($d): bool => $this->access->head($recipient, (int) $d->id))?->id;
            }
            $valid = match ($target->code) {
                'register', 'file' => $this->access->role($recipient, 'secretariat'),
                'allocate', 'review' => $this->access->role($recipient, 'executive_director'),
                'execute' => $this->access->head($recipient, $department),
                'authorized_close' => $this->access->role($recipient, 'board_secretary'),
                default => false,
            };
            abort_unless($valid && $this->access->canRoute($actor, $source, $recipient, $department, (int) $letter->type_value_id, (int) $letter->classification_value_id, false), 403, 'المستلم غير مخول بالمرحلة المؤسسية.');
            $classification = DB::table('master_data_values')->where('id', $letter->classification_value_id)->value('code');
            abort_if($from->step_type !== 'start' && $classification !== 'internal' && $source !== $department && ! $this->access->visible($recipient)->where('c.id', $letter->id)->exists(), 403, 'توسيع تداول القرار السري يحتاج سياسة معتمدة.');
        }
        $event = (int) DB::table('workflow_events')->where('workflow_instance_id', $instance->id)->max('id');
        $this->registry->projectWorkflow($actor, $letter, $updated, $event, $transition->action_code, $comment, $source, $recipient, $department);
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
        return DB::table('official_correspondences')->whereIn('id', $subjectIds)->pluck('reference', 'id')->map(fn ($reference): string => 'مراسلة رسمية '.$reference)->all();
    }
}
