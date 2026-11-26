<?php

namespace App\Services\Correspondence;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

final class CorrespondenceEditionWorkflow implements WorkflowSubject
{
    public function __construct(private readonly CorrespondenceAccess $access, private readonly CorrespondenceEditions $editions, private readonly AuditService $audit) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $edition = DB::table('correspondence_editions')->where('id', $instance->subject_id)->lockForUpdate()->first();
        $letter = DB::table('official_correspondences')->where('id', $edition->correspondence_id)->lockForUpdate()->first();
        $actor = User::findOrFail($actorId);
        abort_unless($edition->status === 'pending' && $actor->is_active && $this->editions->canView($actor, $edition), 403);
        abort_if(in_array($letter->status, ['withdrawn', 'archived'], true), 422);
        if ($from->code === 'submit') {
            abort_unless($actorId === (int) $edition->proposed_by && $target->code === match ($edition->origin_role) {
                'staff' => 'review', 'board' => 'pending_board', default => 'register'
            }, 403);

            return;
        }
        abort_if($edition->origin_role === 'board', 403, 'سلطة تعديل نص المجلس غير موثقة؛ تبقى النسخة معلقة.');
        $valid = match ($from->code) {
            'review' => $edition->origin_role === 'staff' && $this->access->head($actor, (int) $letter->department_id) && $actorId !== (int) $edition->proposed_by,
            'register' => in_array($edition->origin_role, ['head', 'executive'], true) && $this->access->role($actor, 'secretariat') && $actorId !== (int) $edition->proposed_by,
            'approve' => $this->access->role($actor, 'executive_director') && ($edition->origin_role === 'executive' ? $actorId === (int) $letter->author_id : $actorId !== (int) $letter->author_id),
            default => false,
        };
        abort_unless($valid, 403);
        abort_unless(in_array($target->code, $from->code === 'approve' ? ['published', 'rejected'] : ['approve', 'rejected'], true), 403);
        if ($from->code === 'approve') {
            $required = $edition->origin_role === 'staff' ? 'review' : 'register';
            abort_unless(DB::table('workflow_events as e')->join('workflow_steps as s', 's.id', '=', 'e.from_step_id')->where('e.workflow_instance_id', $instance->id)->where('s.code', $required)->where('e.action', 'reviewed')->exists(), 403);
        }
        abort_unless((int) ($this->editions->effective((int) $letter->id)?->id ?? 0) === (int) ($edition->predecessor_id ?? 0), 409);
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $edition = DB::table('correspondence_editions')->find($instance->subject_id);
        $letter = DB::table('official_correspondences')->find($edition->correspondence_id);
        if ($target->code === 'published' || $target->code === 'rejected') {
            $published = $target->code === 'published';
            abort_unless(! $published || ($from->code === 'approve' && $transition->action_code === 'approve' && filled($comment)), 403);
            DB::table('correspondence_editions')->where('id', $edition->id)->update(['status' => $published ? 'approved' : 'rejected', 'approved_by' => $published ? $actorId : null, 'approved_at' => $published ? now() : null, 'updated_at' => now()]);
            if ($published) {
                $originals = DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->where('is_original', true)->pluck('id');
                foreach (DB::table('correspondence_access')->whereIn('action_id', $originals)->get() as $grant) {
                    DB::table('correspondence_access')->insertOrIgnore(['action_id' => $edition->action_id, 'user_id' => $grant->user_id, 'department_id' => $grant->department_id, 'authority_role' => $grant->authority_role, 'valid_until' => $grant->valid_until, 'created_at' => now()]);
                }
            }
        } else {
            $holders = DB::table('workflow_participants')->where('workflow_instance_id', $instance->id)->where('participation_type', 'assignee')->where('is_active', true)->pluck('user_id');
            if ($target->code === 'approve' && $edition->origin_role === 'executive') {
                abort_unless($holders->contains((int) $letter->author_id), 422, 'المصدر التنفيذي ليس مكلفًا بالاعتماد حاليًا.');
                DB::table('workflow_participants')->where('workflow_instance_id', $instance->id)->where('participation_type', 'assignee')->where('user_id', '<>', $letter->author_id)->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $actorId]);
                DB::table('workflow_instances')->where('id', $instance->id)->update(['current_assignee_id' => $letter->author_id]);
                $holders = collect([$letter->author_id]);
            }
            foreach (User::whereIn('id', $holders)->get() as $holder) {
                $department = $target->code === 'review' ? (int) $letter->department_id : (int) $holder->department_id;
                $eligible = match ($target->code) {
                    'review' => $this->access->head($holder, $department),
                    'register' => $this->access->role($holder, 'secretariat'),
                    'approve' => $this->access->role($holder, 'executive_director'),
                    'pending_board' => (int) $holder->id === (int) $edition->proposed_by,
                    default => false,
                };
                abort_unless($eligible && $this->access->member($holder, $department), 403);
                $this->editions->grant((int) $edition->action_id, $holder, $department);
                foreach (DB::table('correspondence_actions')->where('correspondence_id', $letter->id)->where('is_original', true)->pluck('id') as $action) {
                    $this->editions->grant((int) $action, $holder, $department);
                }
            }
        }
        $this->audit->recordRow('correspondence.edition.'.$target->code, 'correspondence_editions', $edition->id, null, ['workflow_instance_id' => $instance->id, 'from' => $from->code, 'to' => $target->code], $actorId);
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return route('correspondence.editions.show', DB::table('correspondence_editions')->where('id', $subjectId)->value('public_id'), false);
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('correspondence_editions')->whereIn('id', $subjectIds)->pluck('version', 'id')->map(fn ($version): string => 'اعتماد نسخة مراسلة '.$version)->all();
    }
}
