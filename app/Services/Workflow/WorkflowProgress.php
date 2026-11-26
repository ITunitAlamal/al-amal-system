<?php

namespace App\Services\Workflow;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the global stepper for a workflow request from the real version and its event log:
 * the steps it actually passed (with who, when, attempts and return/reject reasons), the current step
 * (who holds it and its deadline), then the expected remaining path.
 *
 * The remaining path follows the first forward transition of each step (not return or reject).
 * Conditional branches may take another route, so those steps are "upcoming", not promised.
 *
 * Visibility follows WorkflowVisibilityService: the requester ("public_status") sees step names, times,
 * deadlines, the reason of a rejection and of a return to them, but no names or internal notes.
 *
 * Lists use forList(): a fixed number of queries for any number of requests (no per-row lookups).
 */
final class WorkflowProgress
{
    private const BACKWARD_ACTIONS = ['return', 'reject', 'cancel'];

    private const MAX_UPCOMING = 8;

    public function __construct(private readonly AssigneeResolver $assignees) {}

    /**
     * Steps only (kept for callers that do not need the summary).
     *
     * @return list<array<string, mixed>>
     */
    public function for(object $instance, string $level = 'operational'): array
    {
        return $this->view($instance, $level)['steps'];
    }

    /**
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function view(object $instance, string $level = 'operational'): array
    {
        return $this->build($instance, $level, $this->load(collect([$instance])), true);
    }

    /**
     * Progress of many requests for a list screen. Owners are named by role, as the requester sees them.
     *
     * @param  Collection<int, object>  $instances  rows with id, workflow_version_id, current_step_id, status, started_at, due_at, sla_breached_at
     * @return array<int, array{steps: list<array<string, mixed>>, summary: array<string, mixed>}> keyed by instance id
     */
    public function forList(Collection $instances, string $level = 'public_status'): array
    {
        if ($instances->isEmpty()) {
            return [];
        }
        $data = $this->load($instances);
        $result = [];
        foreach ($instances as $instance) {
            $result[(int) $instance->id] = $this->build($instance, $level, $data, false);
        }

        return $result;
    }

    /**
     * The path a request of this workflow will take, from its published version: for a draft not sent yet.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(string $definitionCode, string $firstLabel): array
    {
        $versionId = DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('d.code', $definitionCode)->where('v.status', 'published')->orderByDesc('v.version_number')->value('v.id');
        if (! $versionId) {
            return [];
        }
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $versionId)->get()->keyBy('id');
        $start = $steps->firstWhere('step_type', 'start');
        if (! $start) {
            return [];
        }

        return [
            $this->row($firstLabel, 'current', 'مقدم الطلب'),
            ...array_map(fn (object $step): array => $this->upcoming($step), $this->expectedPath((int) $start->id, $steps, $this->forwardTransitions(collect([(int) $versionId])))),
        ];
    }

    /**
     * @param  Collection<int, object>  $instances
     * @return array{steps: Collection<int, object>, events: Collection<int, Collection<int, object>>, transitions: Collection<int, Collection<int, object>>}
     */
    private function load(Collection $instances): array
    {
        $versionIds = $instances->pluck('workflow_version_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $instanceIds = $instances->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values();

        // Steps of earlier versions stay readable for requests moved to a newer version by a documented migration (SRS CORE08).
        $eventSteps = fn (string $column) => DB::table('workflow_events')->whereIn('workflow_instance_id', $instanceIds)->whereNotNull($column)->select($column);

        return [
            'steps' => DB::table('workflow_steps')->whereIn('workflow_version_id', $versionIds)
                ->orWhereIn('id', $eventSteps('from_step_id'))->orWhereIn('id', $eventSteps('to_step_id'))
                ->get()->keyBy('id'),
            'events' => DB::table('workflow_events as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
                ->whereIn('e.workflow_instance_id', $instanceIds)
                // A reopening moves the request like a transition: from its closing step back to the chosen stage.
                ->where(fn ($query) => $query->whereNotNull('e.workflow_transition_id')->orWhere('e.action', 'reopen'))
                ->orderBy('e.occurred_at')->orderBy('e.id')
                ->get(['e.workflow_instance_id', 'e.from_step_id', 'e.to_step_id', 'e.action', 'e.comment', 'e.occurred_at', 'u.name as actor_name'])
                ->groupBy('workflow_instance_id'),
            'transitions' => $this->forwardTransitions($versionIds),
        ];
    }

    /**
     * @param  Collection<int, int>  $versionIds
     * @return Collection<int, Collection<int, object>>
     */
    private function forwardTransitions(Collection $versionIds): Collection
    {
        return DB::table('workflow_transitions')
            ->whereIn('workflow_version_id', $versionIds)
            ->where('is_active', true)
            ->whereNotIn('action_code', self::BACKWARD_ACTIONS)
            ->orderBy('id')
            ->get()
            ->groupBy('from_step_id');
    }

    /**
     * @param  array{steps: Collection<int, object>, events: Collection<int, Collection<int, object>>, transitions: Collection<int, Collection<int, object>>}  $data
     * @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    private function build(object $instance, string $level, array $data, bool $withOwners): array
    {
        $public = $level === 'public_status';
        $steps = $data['steps'];
        $events = $data['events']->get((int) $instance->id, collect());

        // Each visit of a step: when it started, when and how it ended, by whom.
        $path = [];
        foreach ($events as $event) {
            if ($path === [] && isset($steps[$event->from_step_id])) {
                $path[] = ['id' => (int) $event->from_step_id, 'started' => $instance->started_at ?? null];
            }
            if ($path !== []) {
                $last = array_key_last($path);
                $path[$last] += [
                    'ended' => $event->occurred_at, 'actor' => $event->actor_name, 'action' => $event->action, 'comment' => $event->comment,
                    'toRequester' => ($steps[$event->to_step_id]->assignment_type ?? null) === 'requester',
                ];
            }
            if (isset($steps[$event->to_step_id])) {
                $path[] = ['id' => (int) $event->to_step_id, 'started' => $event->occurred_at];
            }
        }
        $partial = false;
        if ($path === [] && $instance->current_step_id && isset($steps[$instance->current_step_id])) {
            $path[] = ['id' => (int) $instance->current_step_id, 'started' => $instance->started_at ?? null];
            $partial = $events->isEmpty() && $instance->status !== 'in_progress';
        }

        $isOpen = $instance->status === 'in_progress';
        $lastIndex = array_key_last($path);
        $visits = [];
        $result = [];
        $reason = null;
        foreach ($path as $index => $entry) {
            $step = $steps[$entry['id']];
            $visits[$step->id] = ($visits[$step->id] ?? 0) + 1;
            $action = $entry['action'] ?? null;
            $state = match (true) {
                $index === $lastIndex && $isOpen => 'current',
                $index === $lastIndex && ($this->outcome($step) === 'rejected' || $instance->status === 'rejected') => 'rejected',
                $index === $lastIndex && $instance->status === 'cancelled' => 'cancelled',
                $action === 'return' => 'returned',
                $action === 'reject' => 'rejected',
                default => 'done',
            };
            // The requester sees why the request was rejected or sent back to them; returns between reviewers stay internal.
            // Anything sent to the requester's own step (a return, or the answer to a complaint) is addressed to them.
            $visibleReason = match (true) {
                $action === 'reject' => $entry['comment'] ?? null,
                $entry['toRequester'] ?? false => $entry['comment'] ?? null,
                $action === 'return' && ! $public => $entry['comment'] ?? null,
                default => null,
            };
            if ($visibleReason !== null) {
                $reason = $visibleReason;
            }
            $isEnd = $step->step_type === 'end';

            $owner = match (true) {
                $state === 'current' => $withOwners ? $this->currentOwner($instance, $step, $public) : $this->roleLabel($step),
                $public || $isEnd || ! $withOwners => null,
                default => $entry['actor'] ?? null,
            };
            $result[] = [
                'label' => $step->name,
                'state' => $state,
                'owner' => $owner,
                'startedAt' => $entry['started'],
                'completedAt' => $state === 'current' ? null : ($entry['ended'] ?? ($isEnd ? $entry['started'] : null)),
                'dueAt' => $state === 'current' ? $instance->due_at : null,
                'overdue' => $state === 'current' && ($instance->sla_breached_at !== null || ($instance->due_at && $instance->due_at < now()->toDateTimeString())),
                'attempt' => $visits[$step->id],
                'note' => $visibleReason ?? ($public ? null : ($entry['comment'] ?? null)),
            ];
        }

        $upcoming = [];
        if ($isOpen && $lastIndex !== null) {
            foreach ($this->expectedPath($path[$lastIndex]['id'], $steps, $data['transitions']) as $step) {
                $upcoming[] = $this->upcoming($step);
            }
        }
        $current = $isOpen && $lastIndex !== null ? $steps[$path[$lastIndex]['id']] : null;
        $atRequester = $current?->assignment_type === 'requester';

        return [
            'steps' => [...$result, ...$upcoming],
            'summary' => $this->summary($instance, $result, $upcoming, $partial, $atRequester, $public) + [
                'reason' => in_array($instance->status, ['rejected'], true) || $atRequester ? $reason : null,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $passed
     * @param  list<array<string, mixed>>  $upcoming
     * @return array<string, mixed>
     */
    private function summary(object $instance, array $passed, array $upcoming, bool $partial, bool $atRequester, bool $public): array
    {
        $current = collect($passed)->firstWhere('state', 'current');

        return match (true) {
            $instance->status === 'completed' => ['status' => 'مكتمل', 'tone' => 'success', 'where' => 'أُغلقت المعاملة بالإنجاز', 'next' => 'لا إجراءات متبقية', 'dueAt' => null, 'overdue' => false, 'partial' => $partial],
            $instance->status === 'rejected' => ['status' => 'مرفوض', 'tone' => 'danger', 'where' => 'أُغلقت المعاملة بالرفض', 'next' => 'لا إجراءات متبقية', 'dueAt' => null, 'overdue' => false, 'partial' => $partial],
            $instance->status === 'cancelled' => ['status' => 'ملغى', 'tone' => 'muted', 'where' => 'أُلغيت المعاملة', 'next' => 'لا إجراءات متبقية', 'dueAt' => null, 'overdue' => false, 'partial' => $partial],
            ($instance->held_at ?? null) !== null => [
                'status' => 'معلقة مؤقتًا', 'tone' => 'warning',
                'where' => ($current ? $current['label'].($current['owner'] ? ' — '.$current['owner'] : '') : 'قيد المعالجة').' · موعد المراجعة '.$instance->hold_until,
                'next' => 'الاستئناف ثم: '.($upcoming[0]['label'] ?? 'إغلاق المعاملة'), 'dueAt' => null, 'overdue' => false, 'partial' => $partial,
            ],
            $atRequester => [
                'status' => $public ? 'مُعاد إليك للتعديل' : 'مُعاد لمقدم الطلب', 'tone' => 'warning',
                'where' => $public ? 'لديك: عدّل الطلب ثم أعد إرساله' : 'لدى مقدم الطلب للتعديل',
                'next' => 'إعادة الإرسال: '.($upcoming[0]['label'] ?? 'المراجعة'),
                'dueAt' => $current['dueAt'] ?? null, 'overdue' => (bool) ($current['overdue'] ?? false), 'partial' => $partial,
            ],
            default => [
                'status' => 'قيد المعالجة',
                'tone' => ($current['overdue'] ?? false) ? 'danger' : 'info',
                'where' => $current ? $current['label'].($current['owner'] ? ' — '.$current['owner'] : '') : 'قيد المعالجة',
                'next' => $upcoming[0]['label'] ?? 'إغلاق المعاملة',
                'dueAt' => $current['dueAt'] ?? null,
                'overdue' => (bool) ($current['overdue'] ?? false),
                'partial' => $partial,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function upcoming(object $step): array
    {
        return $this->row($step->name, 'upcoming', $step->step_type === 'end' ? null : $this->roleLabel($step));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $label, string $state, ?string $owner): array
    {
        return ['label' => $label, 'state' => $state, 'owner' => $owner, 'startedAt' => null, 'completedAt' => null, 'dueAt' => null, 'overdue' => false, 'attempt' => 1, 'note' => null];
    }

    /** Who a step belongs to, by role (no names), as shown to the requester and in lists. */
    private function roleLabel(object $step): ?string
    {
        if ($step->assignment_type === 'requester') {
            return 'مقدم الطلب';
        }
        if (($step->assigned_role_code ?? null) === 'executive_director') {
            return 'المدير التنفيذي'.(! empty($this->assignees->config($step)['delegation_scope']) ? ' أو من يفوّضه' : '');
        }

        return AssigneeResolver::TYPES[$step->assignment_type] ?? null;
    }

    /** Names of whoever holds the step now; for the requester, only who that is by role. */
    private function currentOwner(object $instance, object $step, bool $public): ?string
    {
        if ($step->assignment_type === 'requester') {
            return 'مقدم الطلب';
        }
        if ($public) {
            return $this->roleLabel($step);
        }
        $names = DB::table('workflow_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.workflow_instance_id', $instance->id)->where('p.participation_type', 'assignee')->where('p.is_active', true)
            ->orderBy('u.name')->limit(4)->pluck('u.name');

        return $names->isEmpty() ? null : $names->implode('، ');
    }

    /**
     * @param  Collection<int, object>  $steps
     * @param  Collection<int, Collection<int, object>>  $transitions  forward transitions grouped by origin step
     * @return list<object>
     */
    private function expectedPath(int $fromStepId, Collection $steps, Collection $transitions): array
    {
        $path = [];
        $seen = [$fromStepId => true];
        $current = $fromStepId;
        while (count($path) < self::MAX_UPCOMING) {
            $next = collect($transitions->get($current, []))
                ->first(fn (object $transition): bool => isset($steps[$transition->to_step_id])
                    && ! isset($seen[$transition->to_step_id])
                    && $this->outcome($steps[$transition->to_step_id]) !== 'rejected');
            if (! $next) {
                break;
            }
            $step = $steps[$next->to_step_id];
            $path[] = $step;
            $seen[$step->id] = true;
            if ($step->step_type === 'end') {
                break;
            }
            $current = (int) $step->id;
        }

        return $path;
    }

    private function outcome(object $step): ?string
    {
        if ($step->step_type !== 'end') {
            return null;
        }

        return $this->assignees->config($step)['outcome'] ?? 'completed';
    }
}
