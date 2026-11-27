<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side lookups for workflow definitions and their published versions.
 */
final class WorkflowCatalog
{
    public function __construct(
        private readonly AssigneeResolver $assignees,
        private readonly ConditionEvaluator $conditions,
    ) {}

    /**
     * Why this user cannot send a request of this type now, or null when its first stage has someone to receive it.
     * Nobody approves their own request, so e.g. a complaint addressed to the executive director cannot be sent by
     * the executive director. Routes that depend on the request's own fields are assumed open.
     */
    public function unavailableReason(User $user, object $definition): ?string
    {
        $version = $this->publishedVersion((int) $definition->id);
        $start = $version ? DB::table('workflow_steps')->where('workflow_version_id', $version->id)->where('step_type', 'start')->first() : null;
        if (! $start) {
            return 'نوع الطلب غير منشور.';
        }
        $probe = (object) ['id' => 0, 'requested_by' => (int) $user->id, 'department_id' => (int) $user->department_id, 'subject_type' => WorkflowEngine::SUBJECT_TYPE];
        $context = [
            'requester_has_department_head' => $this->assignees->hasSuperiorHead((int) $user->department_id, (int) $user->id),
            'requester_is_executive' => DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.user_id', $user->id)->where('r.code', 'executive_director')->whereRaw(EffectiveAssignments::sql('a'))->exists(),
        ];
        $transitions = DB::table('workflow_transitions')->where('workflow_version_id', $version->id)->where('from_step_id', $start->id)->where('is_active', true)->get();
        $unheld = null;
        foreach ($transitions as $transition) {
            $conditions = is_string($transition->conditions) ? json_decode($transition->conditions, true) : null;
            if (is_array($conditions) && array_diff_key($conditions, WorkflowEngine::REQUESTER_CONTEXT) !== []) {
                return null; // depends on the request's fields: decided when it is sent
            }
            if (! $this->conditions->passes($conditions, $context)) {
                continue;
            }
            $target = DB::table('workflow_steps')->where('id', $transition->to_step_id)->first();
            $holders = $target->assignment_type === 'pick' ? $this->assignees->pickOptions($target, $probe) : $this->assignees->candidates($target, $probe);
            if ($target->step_type === 'end' || $holders !== []) {
                return null;
            }
            $unheld ??= $target;
        }

        // The exact designation missing (TRANSFORM §3.4: alert, never skip), roles and departments only.
        return 'لا يوجد من يستلم طلبك في مرحلته الأولى'.($unheld ? ' «'.$unheld->name.'» (يلزم: '.$this->assignees->holderNeeded($unheld).')' : '')
            .'؛ لا يعتمد أحد طلبه بنفسه، وتحدد الإدارة الجهة البديلة.';
    }

    /**
     * Workflows an employee may start from "طلباتي".
     *
     * @return Collection<int, object{id: int, code: string, name: string, description: ?string}>
     */
    public function startableFor(User $user): Collection
    {
        if (! $user->is_active || ! $user->department_id) {
            return collect();
        }

        return DB::table('workflow_definitions as d')
            ->where('d.is_active', true)
            ->where('d.is_user_startable', true)
            ->where('d.subject_type', WorkflowEngine::SUBJECT_TYPE)
            ->whereExists(fn ($query) => $query->from('workflow_versions as v')
                ->whereColumn('v.workflow_definition_id', 'd.id')
                ->where('v.status', 'published'))
            ->orderBy('d.name')
            ->get(['d.id', 'd.code', 'd.name', 'd.description']);
    }

    public function startableDefinition(string $code): ?object
    {
        return DB::table('workflow_definitions')
            ->where('code', $code)
            ->where('is_active', true)
            ->where('is_user_startable', true)
            ->where('subject_type', WorkflowEngine::SUBJECT_TYPE)
            ->first();
    }

    /**
     * The version new requests start on. Older published versions are retired on publish,
     * so at most one is published at a time.
     */
    public function publishedVersion(int $definitionId): ?object
    {
        return DB::table('workflow_versions')
            ->where('workflow_definition_id', $definitionId)
            ->where('status', 'published')
            ->orderByDesc('version_number')
            ->first();
    }
}
