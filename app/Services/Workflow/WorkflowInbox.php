<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists for "مهامي" (requests waiting for me) and "طلباتي" (requests I made).
 * Module records (e.g. leave requests) get their title and link from their module, one query per module.
 */
final class WorkflowInbox
{
    public function __construct(
        private readonly WorkflowSubjects $subjects,
        private readonly ApprovalDelegations $delegations,
    ) {}

    /**
     * @return Collection<int, object>
     */
    public function tasksFor(User $user): Collection
    {
        // A delegation that ended returns its open requests before the list is read, even when the scheduler is not running.
        $this->delegations->closeLapsed();

        return $this->present($this->baseQuery($user)
            ->join('workflow_participants as p', 'p.workflow_instance_id', '=', 'i.id')
            ->where('p.user_id', $user->id)
            ->where('p.participation_type', 'assignee')
            ->where('p.is_active', true)
            ->where('i.status', 'in_progress')
            ->orderByRaw('CASE WHEN i.due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('i.due_at')
            ->orderBy('i.updated_at')
            ->get($this->columns()));
    }

    /**
     * @return Collection<int, object>
     */
    public function requestsBy(User $user): Collection
    {
        return $this->present($this->baseQuery($user)
            ->where('i.requested_by', $user->id)
            ->orderByDesc('i.created_at')
            ->limit(200)
            ->get($this->columns()));
    }

    private function baseQuery(User $user): Builder
    {
        $query = DB::table('workflow_instances as i')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->leftJoin('aims_requests as r', fn ($join) => $join->on('r.id', '=', 'i.subject_id')->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE))
            ->join('users as u', 'u.id', '=', 'i.requested_by');
        if (Schema::hasTable('official_correspondences')) {
            $visible = app(CorrespondenceAccess::class)->visible($user)->select('c.id');
            $query->where(fn ($q) => $q->where('i.subject_type', '<>', 'official_correspondence')->orWhereIn('i.subject_id', $visible));
        }
        if (Schema::hasTable('correspondence_editions')) {
            $editions = DB::table('correspondence_editions');
            if (! $user->isSystemAdmin()) {
                $editions->whereIn('action_id', app(CorrespondenceAccess::class)->accessQuery($user)->select('access.action_id'));
            }
            $query->where(fn ($query) => $query->where('i.subject_type', '<>', 'correspondence_edition')->orWhereIn('i.subject_id', $editions->select('id')));
        }

        return $query;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function present(Collection $rows): Collection
    {
        $titles = $this->subjects->titles($rows->where('subject_type', '!=', WorkflowEngine::SUBJECT_TYPE));

        return $rows->map(function (object $row) use ($titles): object {
            $row->title ??= $titles[$row->subject_type][(int) $row->subject_id] ?? null;
            $row->url = $this->subjects->url($row);

            return $row;
        });
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'i.id', 'i.public_id', 'i.reference_no', 'i.status', 'i.due_at', 'i.sla_breached_at', 'i.created_at', 'i.updated_at',
            'i.started_at', 'i.workflow_version_id', 'i.current_step_id', 'i.subject_type', 'i.subject_id', 'i.requested_by', 'i.held_at', 'i.hold_until',
            'd.code as workflow_code', 'd.name as workflow_name', 's.name as step_name', 's.assignment_type as step_assignment_type',
            'r.title', 'u.name as requester_name',
        ];
    }
}
