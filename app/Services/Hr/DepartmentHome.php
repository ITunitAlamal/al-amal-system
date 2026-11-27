<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Workflow\StepAssignments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The home page of the people of the unified department. Everyone gets «بانتظار إجرائي» first: the same tasks, in the
 * same order and with the same count, as «مهامي» (TransactionTracker::tasksOf). The head also gets «جاهز للإسناد», a
 * marked part of those tasks and never a second total, and «متابعة أعمال القسم», the department's requests that do
 * not wait for the head (DepartmentFollowUp). The head's page is HrHomeDashboard; the others' is «مساحتي».
 */
final class DepartmentHome
{
    private const FOLLOW_UP_ROWS = 6;

    public function __construct(
        private readonly DepartmentFollowUp $followUp,
        private readonly StepAssignments $assignments,
    ) {}

    /**
     * What waits for the user. "total" is the number of «مهامي»; "ready" and "field" are parts of it (the head
     * only, null for anyone else) and "overdue" counts real deadlines that have passed.
     *
     * @param  Collection<int, array<string, mixed>>  $tasks  TransactionTracker::tasksOf($user)
     * @return array{total: int, overdue: int, ready: ?int, field: ?int, items: list<array<string, mixed>>, readyItems: list<array<string, mixed>>, candidates: list<array{name: string, open_tasks: int}>}
     */
    public function waiting(User $user, Collection $tasks, int $limit = 6, int $readyLimit = 5): array
    {
        $head = $user->isHrDepartmentHead();
        $mine = $tasks->keyBy('key');
        // Steps of the department that the head holds as their supervisor, among the head's own tasks
        // (StepAssignments::openSupervised, which also names the worker of a field job).
        $held = $head ? collect($this->followUp->supervised())
            ->filter(fn (array $row): bool => $row['mode'] === 'supervisor' && $row['holder_id'] === (int) $user->id && $mine->has('wf-'.$row['public_id']))
            ->keyBy(fn (array $row): string => 'wf-'.$row['public_id']) : collect();
        $ready = $held->filter(fn (array $row): bool => $row['assignable']);
        $field = $held->filter(fn (array $row): bool => $row['field_work']);

        $shown = $tasks->take($limit)->values();
        $next = $this->nextActions($shown);

        return [
            'total' => $tasks->count(),
            'overdue' => $tasks->where('overdue', true)->count(),
            'ready' => $head ? $ready->count() : null,
            'field' => $head ? $field->count() : null,
            'items' => $shown->map(function (array $task) use ($next, $ready, $field): array {
                $fieldJob = $field->get($task['key']);

                return [...$task,
                    'next' => $next[$task['key']] ?? null,
                    'fieldWork' => $fieldJob !== null,
                    'worker' => $fieldJob['field_worker'] ?? null,
                    'readyToAssign' => $ready->has($task['key']),
                    'actionLabel' => $fieldJob !== null ? 'توثيق الإنجاز' : 'اتخاذ إجراء',
                ];
            })->all(),
            'readyItems' => $ready->take($readyLimit)->map(fn (array $row, string $key): array => [
                'key' => $key,
                'reference' => $row['reference_no'],
                'title' => $row['title'] ?: $row['workflow_name'],
                'kind' => $row['workflow_name'],
                'step' => $row['step_name'],
                'requester' => $row['requester_name'],
                'department' => $row['department_name'],
                'urgent' => (bool) $row['urgent'],
                'since' => $mine[$key]['since'] ?? $row['held_since'],
                'dueAt' => $row['due_at'],
                'assignUrl' => $row['url'].'#assignment',
                // A general-service step may be done by a worker who has no account: the head records the field
                // assignment with the request's own action («تسجيل تكليف ميداني») instead of handing the step to a user.
                'fieldUrl' => $row['workflow_code'] === 'SERVICE' ? $row['url'] : null,
            ])->values()->all(),
            'candidates' => $head && $ready->isNotEmpty() ? $this->candidates($user) : [],
        ];
    }

    /**
     * «متابعة أعمال القسم» for the head: how the department's open requests are held, and the first rows of the
     * three kinds that do not wait for the head's own decision. The field rows are steps the head holds: they are
     * the same records as in «بانتظار إجرائي», shown here as a view.
     *
     * @return array{summary: array<string, int>, lists: array<string, list<array<string, mixed>>>, url: string}|null
     */
    public function followUp(User $user): ?array
    {
        if (! $user->isHrDepartmentHead()) {
            return null;
        }

        return [
            'summary' => $this->followUp->summary($user),
            'lists' => [
                'assigned' => $this->followUp->sample($user, 'assigned', self::FOLLOW_UP_ROWS),
                'field' => $this->followUp->sample($user, 'field', self::FOLLOW_UP_ROWS),
                'other' => $this->followUp->sample($user, 'other', self::FOLLOW_UP_ROWS),
            ],
            'url' => route('hr.dashboard', [], false),
        ];
    }

    /**
     * Who the head may assign a department step to now, each with the open tasks they hold.
     *
     * @return list<array{name: string, open_tasks: int}>
     */
    private function candidates(User $head): array
    {
        $departmentId = HrServicesDashboard::departmentId();

        return $departmentId === null ? [] : array_map(
            fn (array $candidate): array => ['name' => $candidate['name'], 'open_tasks' => $candidate['open_tasks']],
            $this->assignments->candidatesIn($departmentId, [(int) $head->id]));
    }

    /**
     * The forward actions the current step of each workflow task shown offers (the names set in the workflow
     * designer), as «الإجراء التالي». Two queries for the tasks shown.
     *
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @return array<string, string> task key => action names
     */
    private function nextActions(Collection $tasks): array
    {
        $publicIds = $tasks->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'wf-'))->map(fn (string $key): string => substr($key, 3))->values();
        if ($publicIds->isEmpty()) {
            return [];
        }
        $rows = DB::table('workflow_instances')->whereIn('public_id', $publicIds)->where('status', 'in_progress')->get(['public_id', 'current_step_id']);
        $actions = DB::table('workflow_transitions')->whereIn('from_step_id', $rows->pluck('current_step_id')->filter()->unique())
            ->where('is_active', true)->whereNotIn('action_code', ['return', 'reject'])->orderBy('id')
            ->get(['from_step_id', 'name'])->groupBy('from_step_id');

        return $rows->mapWithKeys(function (object $row) use ($actions): array {
            $names = $actions->get($row->current_step_id)?->pluck('name')->implode(' أو ');

            return $names ? ['wf-'.$row->public_id => Str::limit($names, 90)] : [];
        })->all();
    }
}
