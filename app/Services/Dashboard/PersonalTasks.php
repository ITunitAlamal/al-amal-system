<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «قائمة مهامي الشخصية»: a private To-Do list. Every read and write is scoped to the owner: another user's item
 * is "not found", whatever their role. Personal items never approve, close or escalate official work, and their
 * reminders reach their owner only.
 */
final class PersonalTasks
{
    public const STATUS = ['open' => 'مفتوحة', 'in_progress' => 'قيد التنفيذ', 'done' => 'منجزة'];

    public const RECURRENCE = ['none' => 'لا تتكرر', 'daily' => 'يوميًا', 'weekly' => 'أسبوعيًا', 'monthly' => 'شهريًا'];

    public const FILTERS = ['active' => 'الحالية', 'today' => 'تستحق اليوم', 'overdue' => 'المتأخرة', 'done' => 'المنجزة', 'archived' => 'المؤرشفة'];

    public function __construct(private readonly NotificationCenter $notifications) {}

    /**
     * The owner's items, filtered, searched and sorted.
     *
     * @param  array{filter?: ?string, q?: ?string, category?: ?int, priority?: ?int, sort?: ?string}  $filters
     */
    public function list(User $owner, array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $now = now();
        $query = $this->presented($owner);
        match ($filters['filter'] ?? 'active') {
            'today' => $query->whereNull('t.archived_at')->where('t.status', '<>', 'done')->whereBetween('t.due_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]),
            'overdue' => $query->whereNull('t.archived_at')->where('t.status', '<>', 'done')->where('t.due_at', '<', $now),
            'done' => $query->whereNull('t.archived_at')->where('t.status', 'done'),
            'archived' => $query->whereNotNull('t.archived_at'),
            default => $query->whereNull('t.archived_at')->where('t.status', '<>', 'done'),
        };
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn (Builder $q) => $q->where('t.title', 'ilike', $term)->orWhere('t.notes', 'ilike', $term));
        }
        $query->when(! empty($filters['category']), fn (Builder $q) => $q->where('t.category_value_id', (int) $filters['category']))
            ->when(! empty($filters['priority']), fn (Builder $q) => $q->where('t.priority_value_id', (int) $filters['priority']));
        match ($filters['sort'] ?? 'due') {
            'priority' => $query->orderByRaw('p.sort_order DESC NULLS LAST')->orderByRaw('t.due_at NULLS LAST'),
            'created' => $query->orderByDesc('t.created_at'),
            default => $query->orderByRaw('t.due_at NULLS LAST')->orderByDesc('t.id'),
        };

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Counts and the next items for the dashboard card.
     *
     * @return array{open: int, today: int, overdue: int, items: list<object>}
     */
    public function summary(User $owner, int $limit = 5): array
    {
        $now = now();
        $active = DB::table('personal_tasks')->where('user_id', $owner->id)->whereNull('deleted_at')->whereNull('archived_at')->where('status', '<>', 'done');

        return [
            'open' => (clone $active)->count(),
            'today' => (clone $active)->whereBetween('due_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
            'overdue' => (clone $active)->where('due_at', '<', $now)->count(),
            'items' => $this->presented($owner)->whereNull('t.archived_at')->where('t.status', '<>', 'done')
                ->orderByRaw('t.due_at NULLS LAST')->orderByDesc('t.id')->limit($limit)->get()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $owner, array $data): int
    {
        return DB::transaction(function () use ($owner, $data): int {
            $row = $this->row($data);
            $id = (int) DB::table('personal_tasks')->insertGetId([...$row, 'user_id' => $owner->id,
                'series_id' => $row['recurrence'] !== 'none' ? (string) Str::uuid() : null, 'created_at' => now(), 'updated_at' => now()]);
            $this->event($id, $owner, 'created', ['title' => $row['title']]);

            return $id;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $owner, int $id, array $data): void
    {
        DB::transaction(function () use ($owner, $id, $data): void {
            $task = $this->owned($owner, $id, true);
            $row = $this->row($data);
            if ($row['recurrence'] !== 'none' && ! $task->series_id) {
                $row['series_id'] = (string) Str::uuid();
            }
            // A new reminder time is a new reminder.
            if (($row['remind_at'] ?? null) !== $task->remind_at) {
                $row['reminded_at'] = null;
            }
            DB::table('personal_tasks')->where('id', $id)->update([...$row, 'updated_at' => now()]);
            $changes = array_filter(array_keys($row), fn (string $key): bool => (string) ($task->{$key} ?? '') !== (string) ($row[$key] ?? ''));
            $this->event($id, $owner, 'updated', ['fields' => array_values($changes)]);
        });
    }

    public function complete(User $owner, int $id): void
    {
        DB::transaction(function () use ($owner, $id): void {
            $task = $this->owned($owner, $id, true);
            if ($task->status === 'done') {
                return;
            }
            DB::table('personal_tasks')->where('id', $id)->update(['status' => 'done', 'progress' => 100, 'completed_at' => now(), 'updated_at' => now()]);
            $this->event($id, $owner, 'completed', null);
            $this->scheduleNext($owner, $task);
        });
    }

    public function reopen(User $owner, int $id): void
    {
        DB::transaction(function () use ($owner, $id): void {
            $task = $this->owned($owner, $id, true);
            DB::table('personal_tasks')->where('id', $id)->update(['status' => 'open', 'completed_at' => null, 'progress' => min((int) $task->progress, 90), 'updated_at' => now()]);
            $this->event($id, $owner, 'reopened', null);
        });
    }

    public function archive(User $owner, int $id, bool $archived): void
    {
        DB::transaction(function () use ($owner, $id, $archived): void {
            $this->owned($owner, $id, true);
            DB::table('personal_tasks')->where('id', $id)->update(['archived_at' => $archived ? now() : null, 'updated_at' => now()]);
            $this->event($id, $owner, $archived ? 'archived' : 'restored', null);
        });
    }

    /** Soft deletion: the item disappears from every list; its history stays. */
    public function delete(User $owner, int $id): void
    {
        DB::transaction(function () use ($owner, $id): void {
            $this->owned($owner, $id, true);
            DB::table('personal_tasks')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
            $this->event($id, $owner, 'deleted', null);
        });
    }

    /**
     * @return list<object>
     */
    public function history(User $owner, int $id): array
    {
        $this->owned($owner, $id, false);

        return DB::table('personal_task_events')->where('personal_task_id', $id)->orderByDesc('id')->limit(30)->get(['action', 'changes', 'created_at'])->all();
    }

    /**
     * Private reminders that are due: one in-app notice to the owner per reminder time (scheduled every few minutes).
     *
     * @return int reminders sent
     */
    public function sendDueReminders(): int
    {
        $due = DB::table('personal_tasks')->whereNull('deleted_at')->whereNull('archived_at')->where('status', '<>', 'done')
            ->whereNull('reminded_at')->whereNotNull('remind_at')->where('remind_at', '<=', now())->orderBy('id')->limit(500)->get();
        foreach ($due as $task) {
            DB::transaction(function () use ($task): void {
                $locked = DB::table('personal_tasks')->where('id', $task->id)->whereNull('reminded_at')->lockForUpdate()->first();
                if (! $locked) {
                    return; // already handled by another run
                }
                $this->notifications->notify((int) $task->user_id, 'personal_task_reminder', [
                    'title' => 'تذكير بمهمة شخصية', 'body' => Str::limit($task->title, 80),
                    'url' => route('todo.index', [], false), 'dedupe' => 'todo:'.$task->id.':'.$task->remind_at,
                ]);
                DB::table('personal_tasks')->where('id', $task->id)->update(['reminded_at' => now(), 'updated_at' => now()]);
            });
        }

        return $due->count();
    }

    /** The owner's items with their constants' Arabic names. */
    private function presented(User $owner): Builder
    {
        return DB::table('personal_tasks as t')
            ->leftJoin('master_data_values as c', 'c.id', '=', 't.category_value_id')
            ->leftJoin('master_data_values as p', 'p.id', '=', 't.priority_value_id')
            ->where('t.user_id', $owner->id)->whereNull('t.deleted_at')
            ->select(['t.id', 't.title', 't.notes', 't.status', 't.progress', 't.due_at', 't.remind_at', 't.recurrence', 't.completed_at', 't.archived_at',
                't.category_value_id', 't.priority_value_id', 't.created_at', 'c.name_ar as category', 'p.name_ar as priority', 'p.code as priority_code']);
    }

    private function owned(User $owner, int $id, bool $lock): object
    {
        $query = DB::table('personal_tasks')->where('id', $id)->where('user_id', $owner->id)->whereNull('deleted_at');

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? abort(404);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function row(array $data): array
    {
        $due = ! empty($data['due_at']) ? Carbon::parse($data['due_at']) : null;
        $remind = ! empty($data['remind_at']) ? Carbon::parse($data['remind_at']) : null;
        $recurrence = $data['recurrence'] ?? 'none';
        if ($recurrence !== 'none' && ! $due) {
            throw ValidationException::withMessages(['recurrence' => 'المهمة المتكررة تحتاج موعد استحقاق.']);
        }
        $status = $data['status'] ?? 'open';
        $progress = $status === 'done' ? 100 : min(100, max(0, (int) ($data['progress'] ?? 0)));

        return [
            'title' => trim((string) $data['title']), 'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            'category_value_id' => $data['category_value_id'] ?? null, 'priority_value_id' => $data['priority_value_id'] ?? null,
            'status' => $status, 'progress' => $progress, 'due_at' => $due?->toDateTimeString(), 'remind_at' => $remind?->toDateTimeString(),
            'recurrence' => $recurrence, 'completed_at' => $status === 'done' ? now() : null,
        ];
    }

    /**
     * The next occurrence of a recurring item, created once (unique per series and due time).
     */
    private function scheduleNext(User $owner, object $task): void
    {
        if ($task->recurrence === 'none' || ! $task->due_at) {
            return;
        }
        $step = fn (Carbon $at): Carbon => match ($task->recurrence) {
            'daily' => $at->copy()->addDay(), 'weekly' => $at->copy()->addWeek(), default => $at->copy()->addMonthNoOverflow(),
        };
        $nextDue = $step(Carbon::parse($task->due_at));
        $nextRemind = $task->remind_at ? $step(Carbon::parse($task->remind_at)) : null;
        $series = $task->series_id ?: (string) Str::uuid();
        if (! $task->series_id) {
            DB::table('personal_tasks')->where('id', $task->id)->update(['series_id' => $series]);
        }
        $inserted = DB::table('personal_tasks')->insertOrIgnore([
            'user_id' => $owner->id, 'series_id' => $series, 'title' => $task->title, 'notes' => $task->notes,
            'category_value_id' => $task->category_value_id, 'priority_value_id' => $task->priority_value_id,
            'status' => 'open', 'progress' => 0, 'due_at' => $nextDue->toDateTimeString(), 'remind_at' => $nextRemind?->toDateTimeString(),
            'recurrence' => $task->recurrence, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted) {
            $nextId = (int) DB::table('personal_tasks')->where('series_id', $series)->where('due_at', $nextDue->toDateTimeString())->whereNull('deleted_at')->value('id');
            $this->event($nextId, $owner, 'recurred', ['from' => (int) $task->id]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    private function event(int $taskId, User $user, string $action, ?array $changes): void
    {
        DB::table('personal_task_events')->insert([
            'personal_task_id' => $taskId, 'user_id' => $user->id, 'action' => $action,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }
}
