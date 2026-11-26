<?php

namespace App\Http\Controllers;

use App\Services\Configuration\MasterDataLookup;
use App\Services\Dashboard\PersonalTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «قائمة مهامي الشخصية»: every action works on the signed-in user's own items only (PersonalTasks scopes each
 * query to its owner; another user's item answers 404).
 */
class PersonalTaskController extends Controller
{
    public function __construct(private readonly PersonalTasks $tasks, private readonly MasterDataLookup $constants) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(array_keys(PersonalTasks::FILTERS))],
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'integer'],
            'priority' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(['due', 'priority', 'created'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = $this->tasks->list($request->user(), $filters, (int) ($filters['page'] ?? 1));

        return Inertia::render('Todo/Index', [
            'tasks' => ['data' => $page->items(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'filters' => ['filter' => $filters['filter'] ?? 'active', 'q' => $filters['q'] ?? '', 'category' => $filters['category'] ?? '', 'priority' => $filters['priority'] ?? '', 'sort' => $filters['sort'] ?? 'due'],
            'filterOptions' => PersonalTasks::FILTERS,
            'statusOptions' => PersonalTasks::STATUS,
            'recurrenceOptions' => PersonalTasks::RECURRENCE,
            'categories' => $this->options('personal_task_categories'),
            'priorities' => $this->options('personal_task_priorities'),
            'summary' => array_diff_key($this->tasks->summary($request->user(), 0), ['items' => true]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->tasks->create($request->user(), $this->validated($request));

        return back()->with('success', 'أُضيفت المهمة إلى قائمتك.');
    }

    public function update(Request $request, int $task): RedirectResponse
    {
        $this->tasks->update($request->user(), $task, $this->validated($request));

        return back()->with('success', 'حُفظت المهمة.');
    }

    public function complete(Request $request, int $task): RedirectResponse
    {
        $this->tasks->complete($request->user(), $task);

        return back()->with('success', 'أُنجزت المهمة.');
    }

    public function reopen(Request $request, int $task): RedirectResponse
    {
        $this->tasks->reopen($request->user(), $task);

        return back()->with('success', 'أُعيد فتح المهمة.');
    }

    public function archive(Request $request, int $task): RedirectResponse
    {
        $archived = $request->boolean('archived', true);
        $this->tasks->archive($request->user(), $task, $archived);

        return back()->with('success', $archived ? 'نُقلت المهمة إلى الأرشيف.' : 'أُعيدت المهمة من الأرشيف.');
    }

    public function destroy(Request $request, int $task): RedirectResponse
    {
        $this->tasks->delete($request->user(), $task);

        return back()->with('success', 'حُذفت المهمة من قائمتك.');
    }

    public function history(Request $request, int $task): JsonResponse
    {
        return response()->json(['data' => $this->tasks->history($request->user(), $task)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $constant = fn (string $type) => Rule::exists('master_data_values', 'id')->where('is_active', true)
            ->where('type_id', (int) DB::table('master_data_types')->where('code', $type)->value('id'));

        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'category_value_id' => ['nullable', 'integer', $constant('personal_task_categories')],
            'priority_value_id' => ['nullable', 'integer', $constant('personal_task_priorities')],
            'status' => ['nullable', Rule::in(array_keys(PersonalTasks::STATUS))],
            'progress' => ['nullable', 'integer', 'between:0,100'],
            'due_at' => ['nullable', 'date'],
            'remind_at' => ['nullable', 'date'],
            'recurrence' => ['nullable', Rule::in(array_keys(PersonalTasks::RECURRENCE))],
        ]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function options(string $type): array
    {
        $typeId = (int) DB::table('master_data_types')->where('code', $type)->value('id');

        return $typeId ? $this->constants->options($typeId)->map(fn ($value): array => ['id' => (int) $value->id, 'name' => $value->name_ar])->values()->all() : [];
    }
}
