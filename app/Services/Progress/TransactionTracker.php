<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Models\User;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Finance\FinanceAccessGrants;
use App\Services\LeaveApproverResolver;
use App\Services\LeaveWorkflow;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowInbox;
use App\Services\Workflow\WorkflowProgress;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «طلباتي» and «بانتظار إجرائي» across the system: requests on the workflow engine (generic requests and
 * module records such as leave) plus leave requests that are not on the engine (drafts, legacy mode).
 *
 * Every listed request carries its compact stepper and a "where is it / what next / why" summary, built
 * from the real path in a fixed number of queries for the page shown (no per-row lookups).
 */
final class TransactionTracker
{
    /** @var array<string, string> status filter => Arabic label */
    public const STATUS_FILTERS = [
        'open' => 'قيد المعالجة',
        'returned' => 'مُعاد للتعديل',
        'draft' => 'مسودة',
        'completed' => 'مكتمل / معتمد',
        'rejected' => 'مرفوض',
        'cancelled' => 'ملغى',
    ];

    public const LEAVE_TYPE = 'leave';

    public const PAYSLIP_TYPE = 'payslip';

    /** @var array<int, Collection<int, array<string, mixed>>> user id => that user's requests, loaded once per request */
    private array $loaded = [];

    private const LEAVE_STATUS_KEYS = [
        'draft' => 'draft', 'submitted' => 'open', 'manager_approved' => 'open', 'pending_registration' => 'open', 'returned' => 'returned',
        'approved' => 'completed', 'rejected' => 'rejected', 'cancelled' => 'cancelled',
    ];

    public function __construct(
        private readonly WorkflowInbox $inbox,
        private readonly WorkflowProgress $workflowProgress,
        private readonly LeaveProgress $leaveProgress,
        private readonly LeaveApproverResolver $leaveApprover,
    ) {}

    /**
     * @param  array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string}  $filters
     */
    public function requestsOf(User $user, array $filters, int $page, int $perPage = 15): LengthAwarePaginator
    {
        $items = $this->items($user)->filter(fn (array $item): bool => $this->matches($item, $filters))->values();

        return new LengthAwarePaginator(
            $this->present($items->forPage($page, $perPage)->values()),
            $items->count(), $perPage, $page,
            ['path' => request()->url(), 'query' => array_filter($filters, fn ($value): bool => $value !== null && $value !== '')],
        );
    }

    /**
     * The latest requests and the open/completed counts, for «مساحتي».
     *
     * @return array{latest: list<array<string, mixed>>, open: int, completed: int}
     */
    public function overview(User $user, int $limit): array
    {
        $items = $this->items($user);

        return [
            'latest' => $this->present($items->take($limit)->values()),
            'open' => $items->whereIn('statusKey', ['open', 'returned', 'draft'])->count(),
            'completed' => $items->where('statusKey', 'completed')->count(),
        ];
    }

    /**
     * The request types this user has, for the filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public function typeOptions(User $user): array
    {
        return $this->items($user)->map(fn (array $item): array => ['value' => $item['type'], 'label' => $item['type'] === self::LEAVE_TYPE ? 'الإجازات' : ($item['type'] === self::PAYSLIP_TYPE ? 'قسائم الراتب' : $item['kind'])])
            ->unique('value')->sortBy('label')->values()->all();
    }

    /**
     * Everything waiting for this user's action: workflow tasks (leave requests included) and legacy leave approvals.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tasksOf(User $user): Collection
    {
        $now = now();
        $workflowTasks = $this->inbox->tasksFor($user)->map(fn (object $task): array => [
            'key' => 'wf-'.$task->public_id,
            'title' => $task->title ?: $task->workflow_name,
            'kind' => $task->workflow_name,
            'step' => $task->step_name,
            'detail' => $task->workflow_name.' · '.($task->step_name ?? ''),
            'reference' => $task->reference_no,
            'requester' => $task->requester_name,
            'ownRequest' => (int) $task->requested_by === (int) $user->id,
            'dueAt' => $task->due_at,
            'overdue' => $task->sla_breached_at !== null || ($task->due_at !== null && Carbon::parse($task->due_at)->lt($now)),
            'url' => $task->url,
        ]);

        $leaveTasks = $this->leaveApprover->awaiting($user)->map(fn (object $leave): array => [
            'key' => 'leave-'.$leave->id,
            'title' => 'اعتماد '.($leave->leave_type_name ?? 'إجازة'),
            'kind' => 'طلب إجازة',
            'step' => 'اعتماد الطلب',
            'detail' => 'من '.$leave->start_date.' إلى '.$leave->end_date.($leave->requested_days !== null ? ' · '.(float) $leave->requested_days.' يوم' : ''),
            'reference' => 'إجازة #'.$leave->id,
            'requester' => $leave->requester_name,
            'ownRequest' => (int) $leave->requested_by === (int) $user->id,
            'dueAt' => null,
            'overdue' => false,
            'url' => route('leaves.show', ['leave' => $leave->id], false),
        ]);

        $correspondence = collect();
        if (Schema::hasTable('official_correspondences')) {
            $access = app(CorrespondenceAccess::class);
            $correspondence = $access->visible($user)->join('correspondence_tasks as ct', 'ct.correspondence_id', '=', 'c.id')
                ->join('users as author', 'author.id', '=', 'c.author_id')->whereNull('c.workflow_instance_id')
                ->where('ct.assigned_to', $user->id)->where('ct.status', 'open')
                ->whereIn('ct.action_id', $access->accessQuery($user)->select('access.action_id'))
                ->get(['c.public_id', 'c.reference', 'c.author_id', 'ct.due_at', 'author.name as author_name'])
                ->map(fn (object $row): array => ['key' => 'correspondence-'.$row->public_id, 'title' => 'مراسلة رسمية '.$row->reference,
                    'kind' => 'مراسلة رسمية', 'step' => 'متابعة المراسلة', 'detail' => 'مراسلة بانتظار الإجراء', 'reference' => $row->reference,
                    'requester' => $row->author_name, 'ownRequest' => (int) $row->author_id === (int) $user->id, 'dueAt' => $row->due_at,
                    'overdue' => $row->due_at !== null && Carbon::parse($row->due_at)->lt($now), 'url' => route('correspondence.show', $row->public_id, false)]);
        }

        // Sensitive finance grants and SoD exceptions wait for the executive director.
        $financeGrants = collect();
        if (Schema::hasTable('finance_access_grants') && DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.user_id', $user->id)->where('r.code', 'executive_director')->exists()) {
            $grants = app(FinanceAccessGrants::class);
            $financeGrants = DB::table('finance_access_grants as g')->join('users as u', 'u.id', '=', 'g.user_id')->join('users as rq', 'rq.id', '=', 'g.requested_by')
                ->where('g.state', 'pending_approval')->where('g.user_id', '!=', $user->id)->where('g.requested_by', '!=', $user->id)->orderBy('g.id')
                ->get(['g.*', 'u.name as target_name', 'rq.name as requester_name'])
                ->map(fn (object $g): array => ['key' => 'fin-grant-'.$g->public_id, 'title' => $grants->describe($g).' — '.$g->target_name,
                    'kind' => 'صلاحية مالية', 'step' => 'قرار المدير التنفيذي', 'detail' => $g->is_exception ? 'استثناء مؤقت من فصل المهام حتى '.$g->valid_until : 'صلاحية حساسة',
                    'reference' => 'صلاحية #'.$g->id, 'requester' => $g->requester_name, 'ownRequest' => false, 'dueAt' => null, 'overdue' => false,
                    'url' => route('finance.access-grants.show', $g->public_id, false)]);
        }

        return $workflowTasks->concat($leaveTasks)->concat($correspondence)->concat($financeGrants)
            ->sortBy(fn (array $task): string => ($task['overdue'] ? '0' : '1').($task['dueAt'] ?? '9999'))
            ->values();
    }

    /**
     * All of this user's requests, newest first, without progress (cheap: two list queries).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function items(User $user): Collection
    {
        return $this->loaded[(int) $user->id] ??= $this->load($user);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function load(User $user): Collection
    {
        $workflow = $this->inbox->requestsBy($user)->map(fn (object $row): array => [
            'key' => 'wf-'.$row->public_id,
            'source' => 'workflow',
            'row' => $row,
            'type' => $row->subject_type === LeaveWorkflow::SUBJECT_TYPE ? self::LEAVE_TYPE : $row->workflow_code,
            'kind' => $row->workflow_name,
            'title' => $row->title ?: $row->workflow_name,
            'reference' => $row->reference_no,
            'statusKey' => match (true) {
                $row->status === 'in_progress' && $row->step_assignment_type === 'requester' => 'returned',
                $row->status === 'in_progress' => 'open',
                default => $row->status,
            },
            'createdAt' => (string) $row->created_at,
            'url' => $row->url,
        ]);

        $leaves = DB::table('employee_leave_requests as l')
            ->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->where('l.requested_by', $user->id)
            ->whereNull('l.workflow_instance_id')
            ->orderByDesc('l.id')
            ->limit(200)
            ->get(['l.id', 'l.status', 'l.start_date', 'l.end_date', 'l.requested_by', 'l.employee_id', 'l.requested_days', 'l.workflow_instance_id',
                'l.created_at', 'l.submitted_at', 't.name_ar as leave_type_name'])
            ->map(fn (object $row): array => [
                'key' => 'leave-'.$row->id,
                'source' => 'leave',
                'row' => $row,
                'type' => self::LEAVE_TYPE,
                'kind' => 'طلب إجازة',
                'title' => ($row->leave_type_name ?? 'إجازة').' · '.$row->start_date.' ← '.$row->end_date,
                'reference' => 'إجازة #'.$row->id,
                'statusKey' => self::LEAVE_STATUS_KEYS[$row->status] ?? $row->status,
                'createdAt' => (string) $row->created_at,
                'url' => route('leaves.show', ['leave' => $row->id], false),
            ]);

        // «طلب قسيمة راتب»: answered from published payroll runs, not by a workflow.
        $payslips = Schema::hasTable('finance_payslip_requests') ? DB::table('finance_payslip_requests as r')
            ->leftJoin('finance_payslips as s', 's.id', '=', 'r.payslip_id')->where('r.user_id', $user->id)->orderByDesc('r.id')->limit(100)
            ->get(['r.id', 'r.reference', 'r.period', 'r.state', 'r.created_at', 'r.fulfilled_at', 's.reference as slip'])
            ->map(fn (object $row): array => [
                'key' => 'payslip-'.$row->id,
                'source' => 'payslip',
                'row' => $row,
                'type' => self::PAYSLIP_TYPE,
                'kind' => 'طلب قسيمة راتب',
                'title' => 'قسيمة راتب شهر '.$row->period,
                'reference' => $row->reference,
                'statusKey' => $row->state === 'fulfilled' ? 'completed' : 'open',
                'createdAt' => (string) $row->created_at,
                'url' => $row->slip ? route('payslips.show', $row->slip, false) : route('payslips.create', [], false),
            ]) : collect();

        return $workflow->concat($leaves)->concat($payslips)->sortByDesc('createdAt')->values();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{type?: ?string, status?: ?string, q?: ?string, from?: ?string, to?: ?string}  $filters
     */
    private function matches(array $item, array $filters): bool
    {
        $created = substr($item['createdAt'], 0, 10);
        $query = trim((string) ($filters['q'] ?? ''));

        return (empty($filters['type']) || $item['type'] === $filters['type'])
            && (empty($filters['status']) || $item['statusKey'] === $filters['status'])
            && (empty($filters['from']) || $created >= $filters['from'])
            && (empty($filters['to']) || $created <= $filters['to'])
            && ($query === '' || mb_stripos((string) $item['reference'], $query) !== false || mb_stripos((string) $item['title'], $query) !== false);
    }

    /** @return array{steps: list<array<string, mixed>>, summary: array<string, mixed>} */
    private function payslipProgress(object $row): array
    {
        $ready = $row->state === 'fulfilled';
        $summary = match ($row->state) {
            'fulfilled' => ['status' => 'القسيمة جاهزة', 'tone' => 'success', 'where' => 'صدرت من مسير الشهر المنشور', 'next' => 'افتح القسيمة واطبعها أو احفظها PDF'],
            'awaiting_payroll' => ['status' => 'بانتظار نشر المسير', 'tone' => 'warning', 'where' => 'لدى مسؤول الرواتب في الحسابات', 'next' => 'نشر مسير الشهر ثم إشعارك'],
            'not_included' => ['status' => 'لا يوجد سطر لك في المسير', 'tone' => 'danger', 'where' => 'لدى مسؤول الرواتب في الحسابات', 'next' => 'مراجعة المسير؛ التصحيح في مسير لاحق'],
            default => ['status' => 'حسابك غير مرتبط بملف موظف', 'tone' => 'danger', 'where' => 'لدى الموارد البشرية', 'next' => 'ربط حسابك بملفك الوظيفي ثم إعادة الطلب'],
        };

        return ['steps' => [
            ['label' => 'تقديم الطلب', 'state' => 'done', 'owner' => null, 'startedAt' => $row->created_at, 'completedAt' => $row->created_at],
            ['label' => 'نشر مسير الشهر', 'state' => $ready ? 'done' : 'current', 'owner' => null, 'startedAt' => $row->created_at, 'completedAt' => $row->fulfilled_at],
            ['label' => 'القسيمة جاهزة', 'state' => $ready ? 'done' : 'upcoming', 'owner' => null, 'startedAt' => $row->fulfilled_at, 'completedAt' => $row->fulfilled_at],
        ], 'summary' => $summary + ['dueAt' => null, 'overdue' => false, 'reason' => null]];
    }

    /**
     * Adds each item's stepper and summary, then drops the internal rows.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function present(Collection $items): array
    {
        $generic = $items->filter(fn (array $item): bool => $item['source'] === 'workflow' && $item['row']->subject_type === WorkflowEngine::SUBJECT_TYPE)
            ->map(fn (array $item): object => $item['row'])->values();
        $flows = $this->workflowProgress->forList($generic, 'public_status');

        $leaveIds = $items->map(fn (array $item): ?int => match (true) {
            $item['source'] === 'payslip' => null,
            $item['source'] === 'leave' => (int) $item['row']->id,
            $item['row']->subject_type === LeaveWorkflow::SUBJECT_TYPE => (int) $item['row']->subject_id,
            default => null,
        })->filter()->values();
        $leaveRows = $leaveIds->isEmpty() ? collect() : DB::table('employee_leave_requests')->whereIn('id', $leaveIds)->get();
        $leaveFlows = $this->leaveProgress->forList($leaveRows);

        return $items->map(function (array $item) use ($flows, $leaveFlows): array {
            $progress = match (true) {
                $item['source'] === 'payslip' => $this->payslipProgress($item['row']),
                $item['source'] === 'leave' => $leaveFlows[(int) $item['row']->id] ?? null,
                $item['row']->subject_type === LeaveWorkflow::SUBJECT_TYPE => $leaveFlows[(int) $item['row']->subject_id] ?? null,
                default => $flows[(int) $item['row']->id] ?? null,
            } ?? ['steps' => [], 'summary' => ['status' => '—', 'tone' => 'muted']];
            $summary = $progress['summary'];

            return [
                'key' => $item['key'],
                'kind' => $item['kind'],
                'title' => $item['title'],
                'reference' => $item['reference'],
                'status' => $summary['status'] ?? '—',
                'tone' => $summary['tone'] ?? 'muted',
                'createdAt' => $item['createdAt'],
                'url' => $item['url'],
                'steps' => $progress['steps'],
                'summary' => [
                    'where' => $summary['where'] ?? null, 'next' => $summary['next'] ?? null, 'reason' => $summary['reason'] ?? null,
                    'dueAt' => $summary['dueAt'] ?? null, 'overdue' => (bool) ($summary['overdue'] ?? false),
                ],
            ];
        })->all();
    }
}
