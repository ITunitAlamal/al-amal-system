<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Http\Controllers\Admin\AttendancePunchCorrectionController;
use App\Models\User;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\HrActionService;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Finance\FinanceAccessGrants;
use App\Services\Finance\FinancePayslips;
use App\Services\Hr\EmployeeFiles;
use App\Services\Hr\EmployeeNames;
use App\Services\LeaveApproverResolver;
use App\Services\LeaveWorkflow;
use App\Services\Workflow\StepAssignments;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowInbox;
use App\Services\Workflow\WorkflowProgress;
use App\Services\Workflow\WorkflowSubjects;
use App\Services\WorkflowVisibilityService;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
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
 *
 * «مهامي» is one inbox with one number. tasksOf() lists everything that waits for the user: workflow tasks,
 * legacy leave approvals, correspondence tasks, finance grants, and the work that reaches a department outside
 * the engine (employee documents and photos to review, punch corrections, administrative actions, attendance
 * roster approvals). A task is listed only for a user whose decision its endpoint accepts. taskCount() is the
 * same definition counted without building rows, for the badge shared with every page.
 */
final class TransactionTracker
{
    /** @var array<string, string> status filter => Arabic label */
    public const STATUS_FILTERS = [
        'open' => 'قيد المعالجة',
        'returned' => 'مُعاد للتعديل',
        'verify' => 'بانتظار تحققك من الإنجاز',
        'draft' => 'مسودة',
        'completed' => 'مكتمل / معتمد',
        'rejected' => 'مرفوض',
        'cancelled' => 'ملغى',
    ];

    public const LEAVE_TYPE = 'leave';

    public const PAYSLIP_TYPE = 'payslip';

    /** A request back on its start step: its author edits it and sends it again. */
    public const OWN_RETURNED_LABEL = 'طلبك مُعاد إليك للتعديل';

    /** Any other step of a generic request held by its requester: they confirm the work was done, or send it back. */
    public const OWN_VERIFY_LABEL = 'بانتظار تحققك من الإنجاز';

    /** The user's own request on a step they hold in another capacity, or on a module's own step of the requester. */
    public const OWN_REQUEST_LABEL = 'أنت مقدم الطلب';

    private const PUNCH_DIRECTIONS = ['in' => 'دخول', 'out' => 'خروج', 'unknown' => 'غير معروف'];

    private const HANDLED_STATUS = ['completed' => ['مكتمل', 'success'], 'rejected' => ['مرفوض', 'danger'], 'cancelled' => ['ملغى', 'muted']];

    private const SCHEDULE_CHANGE_ACTIONS = ['edit' => 'تعديل بيانات القالب', 'set_default' => 'تعيين كقالب افتراضي', 'activate' => 'تفعيل القالب', 'deactivate' => 'تعطيل القالب'];

    private const EMPLOYEE_NAME_COLUMNS = ['e.full_name_ar', 'e.first_name_ar', 'e.father_name_ar', 'e.grandfather_name_ar', 'e.family_name_ar'];

    /** @var array<int, Collection<int, array<string, mixed>>> user id => that user's requests, loaded once per request */
    private array $loaded = [];

    /** @var array<int, Collection<int, array<string, mixed>>> user id => that user's tasks, loaded once per request */
    private array $tasks = [];

    /** @var array<string, bool> table name => exists */
    private array $tables = [];

    private const LEAVE_STATUS_KEYS = [
        'draft' => 'draft', 'submitted' => 'open', 'manager_approved' => 'open', 'pending_registration' => 'open', 'returned' => 'returned',
        'approved' => 'completed', 'rejected' => 'rejected', 'cancelled' => 'cancelled',
    ];

    public function __construct(
        private readonly WorkflowInbox $inbox,
        private readonly WorkflowProgress $workflowProgress,
        private readonly LeaveProgress $leaveProgress,
        private readonly LeaveApproverResolver $leaveApprover,
        private readonly WorkflowSubjects $subjects,
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
            'open' => $items->whereIn('statusKey', ['open', 'returned', 'verify', 'draft'])->count(),
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
     * Everything waiting for this user's action: tasks with a deadline first (a passed one before the rest), then
     * the others by how long they have waited.
     *
     * Each task: key, title, kind, step, detail, reference, requester (the person it concerns) and personLabel
     * (what that person is to the task), ownRequest and ownLabel (set on the user's own request), assignedBy (the
     * supervisor who assigned it to the user), since (when it reached the user), dueAt, overdue (a real deadline
     * has passed; the sources outside the engine have none) and url (the exact place to act).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tasksOf(User $user): Collection
    {
        return $this->tasks[(int) $user->id] ??= $this->loadTasks($user);
    }

    /**
     * How many tasks tasksOf() lists, from count queries (no rows are read, except legacy single-step leave
     * approvals, whose approver is resolved per request). This is the one number of «مهامي»: the sidebar badge,
     * the top-bar counter and the page heading.
     */
    public function taskCount(User $user): int
    {
        if (isset($this->tasks[(int) $user->id])) {
            return $this->tasks[(int) $user->id]->count();
        }
        $count = $this->workflowTaskCount($user) + $this->leaveApprover->awaiting($user)->count();
        foreach ($this->taskQueues($user) as $query) {
            $count += $query->count();
        }

        return $count;
    }

    /**
     * «أنجزتها»: requests of other people that the user acted on and that are no longer with them, newest action
     * first. Only requests the user may still open (WorkflowVisibilityService), each with the last action they
     * took on it and where it stands now. A fixed number of queries; the per-request access check runs only for
     * a request the user no longer takes part in.
     *
     * @return list<array{key: string, reference: ?string, title: string, kind: string, step: ?string, action: string, actedAt: string, status: string, tone: string, url: string}>
     */
    public function handledBy(User $user, int $limit = 50): array
    {
        $userId = (int) $user->id;
        // The user's latest move on each request. Requests they made themselves are in «طلباتي».
        $mine = DB::table('workflow_events as e')->join('workflow_instances as i', 'i.id', '=', 'e.workflow_instance_id')
            ->where('e.actor_id', $userId)->whereNotNull('e.workflow_transition_id')->where('i.requested_by', '<>', $userId)
            ->whereNotExists(fn ($held) => $held->selectRaw('1')->from('workflow_participants as p')
                ->whereColumn('p.workflow_instance_id', 'i.id')->where('p.user_id', $userId)
                ->where('p.participation_type', 'assignee')->where('p.is_active', true)->where('i.status', 'in_progress'))
            ->groupBy('e.workflow_instance_id')->selectRaw('max(e.id) as event_id')
            ->orderByDesc('event_id')->limit($limit * 2);
        $rows = DB::table('workflow_events as e')->joinSub($mine, 'mine', 'mine.event_id', '=', 'e.id')
            ->join('workflow_instances as i', 'i.id', '=', 'e.workflow_instance_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->leftJoin('workflow_steps as acted', 'acted.id', '=', 'e.from_step_id')
            ->leftJoin('workflow_transitions as t', 't.id', '=', 'e.workflow_transition_id')
            ->leftJoin('aims_requests as r', fn ($join) => $join->on('r.id', '=', 'i.subject_id')->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE))
            ->orderByDesc('e.id')
            ->get(['i.id', 'i.public_id', 'i.reference_no', 'i.status', 'i.held_at', 'i.subject_type', 'i.subject_id', 'd.name as workflow_name', 'd.is_confidential',
                'r.title', 's.name as step_name', 'acted.name as acted_step_name', 't.name as transition_name', 'e.action', 'e.occurred_at']);
        if ($rows->isEmpty()) {
            return [];
        }

        $participating = DB::table('workflow_participants')->whereIn('workflow_instance_id', $rows->pluck('id'))->where('user_id', $userId)
            ->where('is_active', true)->whereNull('revoked_at')->whereIn('visibility_scope', ['public_status', 'operational', 'full_authorized'])
            ->pluck('workflow_instance_id')->flip();
        $letters = $rows->where('subject_type', 'official_correspondence')->pluck('subject_id');
        $visibleLetters = $letters->isEmpty() ? collect()
            : app(CorrespondenceAccess::class)->visible($user)->whereIn('c.id', $letters)->pluck('c.id')->flip();
        $admin = $user->isSystemAdmin();
        $visibility = app(WorkflowVisibilityService::class);
        // The same answer as WorkflowVisibilityService::accessLevel(): its sufficient grounds read in bulk, and the
        // service itself for whatever they do not settle.
        $rows = $rows->filter(fn (object $row): bool => match (true) {
            $row->subject_type === 'official_correspondence' => isset($visibleLetters[$row->subject_id]),
            $row->subject_type === 'correspondence_edition' => $visibility->accessLevel($user, (int) $row->id) !== null,
            isset($participating[$row->id]), $admin && ! $row->is_confidential => true,
            default => $visibility->accessLevel($user, (int) $row->id) !== null,
        })->take($limit)->values();
        $titles = $this->subjects->titles($rows->where('subject_type', '!=', WorkflowEngine::SUBJECT_TYPE));

        return $rows->map(function (object $row) use ($titles): array {
            [$status, $tone] = self::HANDLED_STATUS[$row->status]
                ?? [($row->held_at !== null ? 'معلقة مؤقتًا' : 'قيد المعالجة').($row->step_name ? ' — '.$row->step_name : ''), $row->held_at !== null ? 'warning' : 'info'];

            return [
                'key' => 'wf-'.$row->public_id,
                'reference' => $row->reference_no,
                'title' => ($row->title ?? ($titles[$row->subject_type][(int) $row->subject_id] ?? null)) ?: $row->workflow_name,
                'kind' => $row->workflow_name,
                'step' => $row->acted_step_name,
                'action' => $row->transition_name ?: $row->action,
                'actedAt' => (string) $row->occurred_at,
                'status' => $status,
                'tone' => $tone,
                'url' => $this->subjects->url($row),
            ];
        })->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function loadTasks(User $user): Collection
    {
        $now = now();
        $userId = (int) $user->id;
        $rows = $this->inbox->tasksFor($user)->unique('id')->values();
        $stepTypes = $this->stepTypes($rows);
        $arrivals = $this->arrivals($rows);
        $workflowTasks = $rows->map(function (object $task) use ($userId, $now, $stepTypes, $arrivals): array {
            $own = (int) $task->requested_by === $userId;
            $arrival = $arrivals[(int) $task->id] ?? null;
            // «أسندها إليك»: the step reached the user by a supervisor's assignment (StepAssignments), not by a move.
            $assigned = $arrival && $arrival->workflow_transition_id === null && $arrival->action === StepAssignments::ACTION_ASSIGNED
                && (int) $arrival->assigned_to_id === $userId && (int) $arrival->actor_id !== $userId;

            return $this->task([
                'key' => 'wf-'.$task->public_id,
                'type' => $task->subject_type === LeaveWorkflow::SUBJECT_TYPE ? self::LEAVE_TYPE : $task->workflow_code,
                'title' => $task->title ?: $task->workflow_name,
                'kind' => $task->workflow_name,
                'step' => $task->step_name,
                'detail' => $task->workflow_name.' · '.($task->step_name ?? ''),
                'reference' => $task->reference_no,
                'requester' => $task->requester_name,
                'ownRequest' => $own,
                'ownLabel' => $own ? match (self::requesterHold($task, $stepTypes)) {
                    'returned' => self::OWN_RETURNED_LABEL,
                    'verify' => self::OWN_VERIFY_LABEL,
                    default => self::OWN_REQUEST_LABEL,
                } : null,
                'assignedBy' => $assigned ? $arrival->actor_name : null,
                'since' => $arrival?->occurred_at ?? $task->started_at ?? $task->created_at,
                'dueAt' => $task->due_at,
                'overdue' => $task->sla_breached_at !== null || ($task->due_at !== null && Carbon::parse($task->due_at)->lt($now)),
                'url' => $task->url,
            ]);
        });

        $leaveTasks = $this->leaveApprover->awaiting($user)->map(fn (object $leave): array => $this->task([
            'key' => 'leave-'.$leave->id,
            'type' => self::LEAVE_TYPE,
            'title' => 'اعتماد '.($leave->leave_type_name ?? 'إجازة'),
            'kind' => 'طلب إجازة',
            'step' => 'اعتماد الطلب',
            'detail' => 'من '.$leave->start_date.' إلى '.$leave->end_date.($leave->requested_days !== null ? ' · '.(float) $leave->requested_days.' يوم' : ''),
            'reference' => 'إجازة #'.$leave->id,
            'requester' => $leave->requester_name,
            'ownRequest' => (int) $leave->requested_by === $userId,
            'ownLabel' => (int) $leave->requested_by === $userId ? self::OWN_REQUEST_LABEL : null,
            'since' => $leave->submitted_at,
            'url' => route('leaves.show', ['leave' => $leave->id], false),
        ]));

        return $workflowTasks->concat($leaveTasks)->concat($this->queueTasks($user, $now))
            ->sortBy(fn (array $task): string => ($task['overdue'] ? '0' : '1').($task['dueAt'] ?? '9999').' '.($task['since'] ?? '9999'))
            ->values();
    }

    /**
     * The same rows as WorkflowInbox::tasksFor(), counted: open requests where the user is an active assignee,
     * with the joins and the two correspondence conditions of WorkflowInbox::baseQuery(). A change to that query
     * must be repeated here, or the badge and the list will disagree.
     */
    private function workflowTaskCount(User $user): int
    {
        $query = DB::table('workflow_participants as p')->join('workflow_instances as i', 'i.id', '=', 'p.workflow_instance_id')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->join('users as u', 'u.id', '=', 'i.requested_by')
            ->where('p.user_id', $user->id)->where('p.participation_type', 'assignee')->where('p.is_active', true)->where('i.status', 'in_progress');
        if ($this->hasTable('official_correspondences')) {
            $visible = app(CorrespondenceAccess::class)->visible($user)->select('c.id');
            $query->where(fn ($letters) => $letters->where('i.subject_type', '<>', 'official_correspondence')->orWhereIn('i.subject_id', $visible));
        }
        if ($this->hasTable('correspondence_editions')) {
            $editions = DB::table('correspondence_editions');
            if (! $user->isSystemAdmin()) {
                $editions->whereIn('action_id', app(CorrespondenceAccess::class)->accessQuery($user)->select('access.action_id'));
            }
            $query->where(fn ($edition) => $edition->where('i.subject_type', '<>', 'correspondence_edition')->orWhereIn('i.subject_id', $editions->select('id')));
        }

        return $query->distinct()->count('i.id');
    }

    /**
     * The task sources outside the workflow inbox, each as the query of the rows that wait for this user (no
     * columns, no order). The list and the count both read these queries, so they cannot disagree. A source is
     * present only when the endpoint that decides its rows accepts this user.
     *
     * @return array<string, Builder>
     */
    private function taskQueues(User $user): array
    {
        $userId = (int) $user->id;
        $executive = $this->leaveApprover->isExecutive($userId);
        $queues = [];

        if ($this->hasTable('official_correspondences')) {
            $access = app(CorrespondenceAccess::class);
            $queues['correspondence'] = $access->visible($user)->join('correspondence_tasks as ct', 'ct.correspondence_id', '=', 'c.id')
                ->join('users as author', 'author.id', '=', 'c.author_id')->whereNull('c.workflow_instance_id')
                ->where('ct.assigned_to', $userId)->where('ct.status', 'open')
                ->whereIn('ct.action_id', $access->accessQuery($user)->select('access.action_id'));
        }

        // Sensitive finance grants and SoD exceptions wait for the executive director.
        if ($executive && $this->hasTable('finance_access_grants')) {
            $queues['finance_grants'] = DB::table('finance_access_grants as g')->join('users as u', 'u.id', '=', 'g.user_id')->join('users as rq', 'rq.id', '=', 'g.requested_by')
                ->where('g.state', 'pending_approval')->where('g.user_id', '!=', $userId)->where('g.requested_by', '!=', $userId);
        }

        // Everything below is department work decided by the HR head, the system administrator or the executive
        // director. Those three facts are read once; for anyone else (most users, on every page) nothing more is
        // asked. Each source still applies its endpoint's own check.
        $admin = $user->isSystemAdmin();
        $hrHead = $user->isHrDepartmentHead();
        if (! $user->is_active || ! ($admin || $hrHead || $executive)) {
            return $queues;
        }

        // Employee documents to verify and self-uploaded photos to review: whoever EmployeeFiles accepts (the HR head).
        foreach ($hrHead ? (app(EmployeeFiles::class)->reviewQueues($user) ?? []) : [] as $key => $query) {
            $queues[$key] = $query;
        }

        // Punch corrections (owner decision 8): the HR head, or the executive director when the HR head asked for it or
        // the punch is theirs; never the requester or the employee concerned (AttendanceAccess::correctionsToDecide,
        // the rule AttendancePunchCorrectionController::decide applies).
        if (($hrHead || $executive) && ($corrections = app(AttendanceAccess::class)->correctionsToDecide($userId)) !== null) {
            $queues['corrections'] = $corrections;
        }

        // Administrative actions: under review for whoever may decide them, returned for changes for their author.
        foreach (app(HrActionService::class)->taskQueues($user) ?? [] as $key => $query) {
            if ($query !== null) {
                $queues['hr_'.$key] = $query;
            }
        }

        // Attendance roster approvals: AttendanceController::decide() and AttendanceScheduleManageController::decide()
        // accept the executive director only, for every pending row he did not prepare himself.
        if ($executive) {
            $notOwn = fn (string $column) => fn ($query) => $query->whereNull($column)->orWhere($column, '<>', $userId);
            $queues['schedule_changes'] = DB::table('attendance_schedule_change_requests as r')->where('r.status', 'pending')->where($notOwn('r.requested_by'));
            $queues['assignments'] = DB::table('attendance_assignments as a')->where('a.status', 'pending')->where($notOwn('a.created_by'));
            $queues['overrides'] = DB::table('attendance_overrides as o')->where('o.status', 'pending')->where($notOwn('o.created_by'));
            // Emergency holidays and compensatory work (owner decision 9): AttendanceCompensatoryController::approveGuard
            // accepts the executive director only, for approving and for crediting the day; never his own registration.
            $queues['emergency_holidays'] = DB::table('attendance_emergency_holidays as h')->where('h.status', 'pending')->where($notOwn('h.created_by'));
            $queues['compensatory_work'] = DB::table('attendance_compensatory_work as w')->where('w.status', 'pending')->where($notOwn('w.requested_by'));
            // Approved work is credited once the day has passed (CompensatoryLeaveService::credit checks the punches).
            $queues['compensatory_credit'] = DB::table('attendance_compensatory_work as w')->where('w.status', 'approved')->whereNull('w.credited_at')
                ->whereNull('w.leave_transaction_id')->where('w.work_date', '<', Carbon::now(config('app.timezone'))->toDateString())->where($notOwn('w.requested_by'));
        }

        return $queues;
    }

    /**
     * The rows of taskQueues() as tasks, one query per source.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function queueTasks(User $user, CarbonInterface $now): Collection
    {
        $userId = (int) $user->id;
        $queues = $this->taskQueues($user);
        $tasks = collect();
        $period = fn (object $row): string => 'من '.$row->starts_on.($row->ends_on ? ' إلى '.$row->ends_on : ' دون تاريخ انتهاء');
        $target = fn (object $row): string => EmployeeNames::display($row) ?: ($row->department_name ? 'قسم '.$row->department_name : 'كل الموظفين');

        if (isset($queues['correspondence'])) {
            $tasks = $tasks->concat($queues['correspondence']->get(['c.public_id', 'c.reference', 'c.author_id', 'ct.due_at', 'ct.created_at as assigned_at', 'author.name as author_name'])
                ->map(fn (object $row): array => $this->task(['key' => 'correspondence-'.$row->public_id, 'title' => 'مراسلة رسمية '.$row->reference,
                    'kind' => 'مراسلة رسمية', 'step' => 'متابعة المراسلة', 'detail' => 'مراسلة بانتظار الإجراء', 'reference' => $row->reference,
                    'requester' => $row->author_name, 'personLabel' => 'المرسل', 'ownRequest' => (int) $row->author_id === $userId,
                    'ownLabel' => (int) $row->author_id === $userId ? 'مراسلة أنشأتها أنت' : null, 'since' => $row->assigned_at, 'dueAt' => $row->due_at,
                    'overdue' => $row->due_at !== null && Carbon::parse($row->due_at)->lt($now), 'url' => route('correspondence.show', $row->public_id, false)])));
        }

        if (isset($queues['finance_grants'])) {
            $grants = app(FinanceAccessGrants::class);
            $tasks = $tasks->concat($queues['finance_grants']->orderBy('g.id')->get(['g.*', 'u.name as target_name', 'rq.name as requester_name'])
                ->map(fn (object $g): array => $this->task(['key' => 'fin-grant-'.$g->public_id, 'title' => $grants->describe($g).' — '.$g->target_name,
                    'kind' => 'صلاحية مالية', 'step' => 'قرار المدير التنفيذي', 'detail' => $g->is_exception ? 'استثناء مؤقت من فصل المهام حتى '.$g->valid_until : 'صلاحية حساسة',
                    'reference' => 'صلاحية #'.$g->id, 'requester' => $g->requester_name, 'since' => $g->created_at ?? null,
                    'url' => route('finance.access-grants.show', $g->public_id, false)])));
        }

        if (isset($queues['documents'])) {
            $tasks = $tasks->concat($queues['documents']->leftJoin('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')->orderBy('d.id')
                ->get(['d.id', 'd.employee_id', 'd.title', 'd.created_at', 'v.name_ar as type_name', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => 'employee-document-'.$row->id, 'title' => 'توثيق وثيقة: '.($row->type_name ?: $row->title),
                    'kind' => 'وثيقة موظف', 'step' => 'توثيق الوثيقة', 'detail' => $row->title, 'reference' => 'وثيقة #'.$row->id,
                    'requester' => EmployeeNames::display($row), 'personLabel' => 'الموظف', 'since' => $row->created_at,
                    'url' => EmployeeFiles::reviewUrl((int) $row->employee_id, 'documents')])));
        }

        if (isset($queues['photos'])) {
            $tasks = $tasks->concat($queues['photos']->orderBy('p.id')->get(['p.id', 'p.employee_id', 'p.created_at', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => 'employee-photo-'.$row->id, 'title' => 'مراجعة الصورة الشخصية: '.EmployeeNames::display($row),
                    'kind' => 'صورة موظف', 'step' => 'مراجعة الصورة', 'detail' => 'رفعها الموظف بنفسه؛ تظهر في ملفه بعد اعتمادها.', 'reference' => 'صورة #'.$row->id,
                    'requester' => EmployeeNames::display($row), 'personLabel' => 'الموظف', 'since' => $row->created_at,
                    'url' => EmployeeFiles::reviewUrl((int) $row->employee_id, 'photo')])));
        }

        if (isset($queues['corrections'])) {
            $timezone = config('app.timezone');
            $tasks = $tasks->concat($queues['corrections']->leftJoin('employees as e', 'e.id', '=', 'p.employee_id')->leftJoin('users as u', 'u.id', '=', 'c.requested_by')
                ->orderBy('c.id')
                ->get(['c.id', 'c.original_direction', 'c.corrected_direction', 'c.created_at', 'p.occurred_at', 'u.name as requested_by_name', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => 'punch-correction-'.$row->id, 'title' => 'تصحيح بصمة: '.EmployeeNames::display($row),
                    'kind' => 'تصحيح بصمة', 'step' => 'قرار التصحيح',
                    'detail' => 'حركة '.Carbon::parse($row->occurred_at, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i')
                        .' · من «'.(self::PUNCH_DIRECTIONS[$row->original_direction] ?? $row->original_direction).'» إلى «'.(self::PUNCH_DIRECTIONS[$row->corrected_direction] ?? $row->corrected_direction).'»'
                        .($row->requested_by_name ? ' · طلبه: '.$row->requested_by_name : ''),
                    'reference' => 'تصحيح #'.$row->id, 'requester' => EmployeeNames::display($row), 'personLabel' => 'الموظف', 'since' => $row->created_at,
                    'url' => AttendancePunchCorrectionController::TASKS_URL])));
        }

        foreach (['hr_review' => 'مراجعة الإجراء', 'hr_returned' => HrActionService::RETURNED_LABEL] as $key => $step) {
            if (! isset($queues[$key])) {
                continue;
            }
            $returned = $key === 'hr_returned';
            $tasks = $tasks->concat($queues[$key]->leftJoin('employees as e', 'e.id', '=', 'a.employee_id')->leftJoin('master_data_values as t', 't.id', '=', 'a.type_value_id')
                ->orderBy('a.id')
                ->get(['a.id', 'a.reference_no', 'a.review_note', 'a.submitted_at', 'a.reviewed_at', 'a.updated_at', 't.name_ar as type_name', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => 'hr-action-'.$row->id, 'title' => ($row->type_name ?: 'إجراء إداري').' — '.EmployeeNames::display($row),
                    'kind' => 'إجراء إداري', 'step' => $step,
                    'detail' => $returned ? 'أُعيد إليك للتعديل'.($row->review_note ? ': '.$row->review_note : '') : 'راجع الوقائع والأدلة ثم أصدره أو أعده أو ارفضه.',
                    'reference' => $row->reference_no, 'requester' => EmployeeNames::display($row), 'personLabel' => 'الموظف',
                    'since' => $returned ? $row->reviewed_at : ($row->submitted_at ?? $row->updated_at), 'url' => HrActionService::url()])));
        }

        if (isset($queues['schedule_changes'])) {
            $tasks = $tasks->concat($queues['schedule_changes']->leftJoin('attendance_schedules as s', 's.id', '=', 'r.schedule_id')->leftJoin('users as u', 'u.id', '=', 'r.requested_by')
                ->orderBy('r.id')->get(['r.id', 'r.action', 'r.created_at', 's.name as schedule_name', 'u.name as requested_by_name'])
                ->map(fn (object $row): array => $this->task(['key' => 'schedule-change-'.$row->id, 'title' => 'تعديل قالب دوام: '.($row->schedule_name ?? '—'),
                    'kind' => 'جدول الدوام', 'step' => 'اعتماد تعديل القالب', 'detail' => self::SCHEDULE_CHANGE_ACTIONS[$row->action] ?? $row->action,
                    'reference' => 'تعديل قالب #'.$row->id, 'requester' => $row->requested_by_name, 'personLabel' => 'طلبه', 'since' => $row->created_at,
                    'url' => '/admin/attendance?tab=schedules'])));
        }

        if (isset($queues['assignments'])) {
            $tasks = $tasks->concat($queues['assignments']->leftJoin('employees as e', 'e.id', '=', 'a.employee_id')->leftJoin('departments as dep', 'dep.id', '=', 'a.department_id')
                ->leftJoin('attendance_schedules as s', 's.id', '=', 'a.schedule_id')->orderBy('a.id')
                ->get(['a.id', 'a.starts_on', 'a.ends_on', 'a.created_at', 's.name as schedule_name', 'dep.name as department_name', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => 'attendance-assignment-'.$row->id, 'title' => 'تكليف جدول دوام: '.($row->schedule_name ?? '—'),
                    'kind' => 'جدول الدوام', 'step' => 'اعتماد التكليف', 'detail' => $period($row), 'reference' => 'تكليف #'.$row->id,
                    'requester' => $target($row), 'personLabel' => 'يخص', 'since' => $row->created_at, 'url' => '/admin/attendance?tab=assignments'])));
        }

        if (isset($queues['overrides'])) {
            $tasks = $tasks->concat($queues['overrides']->leftJoin('employees as e', 'e.id', '=', 'o.employee_id')->leftJoin('departments as dep', 'dep.id', '=', 'o.department_id')
                ->orderBy('o.id')->get(['o.id', 'o.label', 'o.starts_on', 'o.ends_on', 'o.created_at', 'dep.name as department_name', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => 'attendance-override-'.$row->id, 'title' => 'استثناء دوام: '.$row->label,
                    'kind' => 'جدول الدوام', 'step' => 'اعتماد الاستثناء', 'detail' => $period($row), 'reference' => 'استثناء #'.$row->id,
                    'requester' => $target($row), 'personLabel' => 'يخص', 'since' => $row->created_at, 'url' => '/admin/attendance?tab=overrides'])));
        }

        $compensatoryUrl = route('admin.attendance.compensatory.index', [], false);
        if (isset($queues['emergency_holidays'])) {
            $tasks = $tasks->concat($queues['emergency_holidays']->leftJoin('employees as e', 'e.id', '=', 'h.employee_id')->leftJoin('departments as dep', 'dep.id', '=', 'h.department_id')
                ->orderBy('h.id')->get(['h.id', 'h.name', 'h.holiday_date', 'h.created_at', 'dep.name as department_name', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => 'emergency-holiday-'.$row->id, 'title' => 'عطلة طارئة: '.$row->name,
                    'kind' => 'العطل والعمل التعويضي', 'step' => 'اعتماد العطلة', 'detail' => 'يوم '.$row->holiday_date, 'reference' => 'عطلة #'.$row->id,
                    'requester' => $target($row), 'personLabel' => 'يخص', 'since' => $row->created_at, 'url' => $compensatoryUrl])));
        }

        foreach (['compensatory_work' => ['اعتماد العمل', 'عمل في يوم عطلة'], 'compensatory_credit' => ['منح يوم تعويضي', 'عمل معتمد لم يُمنح عنه يوم تعويضي بعد']] as $key => [$step, $label]) {
            if (! isset($queues[$key])) {
                continue;
            }
            $tasks = $tasks->concat($queues[$key]->leftJoin('employees as e', 'e.id', '=', 'w.employee_id')->orderBy('w.id')
                ->get(['w.id', 'w.work_date', 'w.created_at', 'w.approved_at', ...self::EMPLOYEE_NAME_COLUMNS])
                ->map(fn (object $row): array => $this->task(['key' => str_replace('_', '-', $key).'-'.$row->id, 'title' => $step.': '.EmployeeNames::display($row),
                    'kind' => 'العطل والعمل التعويضي', 'step' => $step, 'detail' => $label.' · يوم '.$row->work_date, 'reference' => 'عمل تعويضي #'.$row->id,
                    'requester' => EmployeeNames::display($row), 'personLabel' => 'الموظف',
                    'since' => $key === 'compensatory_credit' ? $row->approved_at : $row->created_at, 'url' => $compensatoryUrl])));
        }

        return $tasks;
    }

    /**
     * One shape for every task, whatever its source.
     *
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function task(array $task): array
    {
        // "type": the request type of a workflow task (its workflow code, or "leave" for any leave request), for the
        // filter of «مهامي»; null for the sources outside the engine.
        return $task + ['type' => null, 'detail' => null, 'requester' => null, 'personLabel' => 'مقدم الطلب', 'ownRequest' => false, 'ownLabel' => null,
            'assignedBy' => null, 'since' => null, 'dueAt' => null, 'overdue' => false];
    }

    /**
     * The type of each row's current step, in one query.
     *
     * @param  Collection<int, object>  $rows  rows with current_step_id
     * @return array<int, string> step id => step type
     */
    private function stepTypes(Collection $rows): array
    {
        $ids = $rows->pluck('current_step_id')->filter()->unique()->values();

        return $ids->isEmpty() ? [] : DB::table('workflow_steps')->whereIn('id', $ids)->pluck('step_type', 'id')->all();
    }

    /**
     * Why an open request waits for its own requester, by the same rule as WorkflowProgress: back on its start step
     * it was returned for editing ("returned"); on any other step of the requester a generic request waits for them
     * to verify that the work was done ("verify"), while on a module record such a step is a task of that module (a
     * signing, a filing), not a verification ("task"). Null when the step is not the requester's.
     *
     * @param  array<int, string>  $stepTypes
     */
    private static function requesterHold(object $row, array $stepTypes): ?string
    {
        if ($row->status !== 'in_progress' || $row->step_assignment_type !== 'requester') {
            return null;
        }

        return match (true) {
            ($stepTypes[(int) $row->current_step_id] ?? null) === 'start' => 'returned',
            $row->subject_type === WorkflowEngine::SUBJECT_TYPE => 'verify',
            default => 'task',
        };
    }

    /**
     * How each open request reached its present holder: its latest move between steps or change of holder (an
     * assignment, a withdrawal, a reopening). A deadline flag or a suspension does not restart the wait.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, object> instance id => event with the actor's name
     */
    private function arrivals(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $latest = DB::table('workflow_events')->whereIn('workflow_instance_id', $rows->pluck('id'))
            ->where(fn ($query) => $query->whereNotNull('workflow_transition_id')
                ->orWhereIn('action', [StepAssignments::ACTION_ASSIGNED, StepAssignments::ACTION_WITHDRAWN, 'reopen']))
            ->groupBy('workflow_instance_id')->selectRaw('max(id) as id');

        return DB::table('workflow_events as e')->joinSub($latest, 'latest', 'latest.id', '=', 'e.id')
            ->leftJoin('users as actor', 'actor.id', '=', 'e.actor_id')
            ->get(['e.workflow_instance_id', 'e.workflow_transition_id', 'e.action', 'e.actor_id', 'e.assigned_to_id', 'e.occurred_at', 'actor.name as actor_name'])
            ->keyBy('workflow_instance_id')->all();
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
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
        $requests = $this->inbox->requestsBy($user);
        $stepTypes = $this->stepTypes($requests->where('status', 'in_progress'));
        $workflow = $requests->map(function (object $row) use ($stepTypes): array {
            $hold = self::requesterHold($row, $stepTypes);

            return [
                'key' => 'wf-'.$row->public_id,
                'source' => 'workflow',
                'row' => $row,
                'type' => $row->subject_type === LeaveWorkflow::SUBJECT_TYPE ? self::LEAVE_TYPE : $row->workflow_code,
                'kind' => $row->workflow_name,
                'title' => $row->title ?: $row->workflow_name,
                'reference' => $row->reference_no,
                // On a step held by the requester: "returned" for editing on the start step, "verify" on any other step
                // of a generic request. A module's own step held by the requester stays "open", as its status reads.
                'statusKey' => match (true) {
                    $row->status !== 'in_progress' => $row->status,
                    $hold === 'returned', $hold === 'verify' => $hold,
                    default => 'open',
                },
                'awaitsMe' => $hold !== null,
                'createdAt' => (string) $row->created_at,
                'url' => $row->url,
            ];
        });

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
        $payslips = Schema::hasTable('finance_payslip_requests') ? app(FinancePayslips::class)->requestsOf($user)
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
        if ($ready) {
            return ['steps' => [
                ['label' => 'إصدار القسيمة مباشرة', 'state' => 'done', 'owner' => null, 'startedAt' => $row->created_at, 'completedAt' => $row->fulfilled_at],
                ['label' => 'جاهزة للطباعة', 'state' => 'done', 'owner' => null, 'startedAt' => $row->fulfilled_at, 'completedAt' => $row->fulfilled_at],
            ], 'summary' => ['status' => 'القسيمة جاهزة', 'tone' => 'success', 'where' => 'صدرت تلقائيًا دون اعتماد للطلب',
                'next' => 'افتح القسيمة واطبعها أو احفظها PDF', 'dueAt' => null, 'overdue' => false, 'reason' => null]];
        }
        $summary = match ($row->state) {
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
            // The status label of a requester-held step comes from WorkflowProgress, which words the two cases apart
            // («مُعاد إليك للتعديل» on the start step, «بانتظار تحققك من الإنجاز» on any other); statusKey follows the same rule.
            $summary = $progress['summary'];
            $verify = $item['statusKey'] === 'verify';

            return [
                'key' => $item['key'],
                'kind' => $item['kind'],
                'title' => $item['title'],
                'reference' => $item['reference'],
                'statusKey' => $item['statusKey'],
                // The request is on a step its requester holds (or was returned to them): «اتخاذ إجراء» in «طلباتي».
                'awaitsMe' => (bool) ($item['awaitsMe'] ?? $item['statusKey'] === 'returned'),
                'status' => $summary['status'] ?? '—',
                'tone' => $summary['tone'] ?? 'muted',
                'createdAt' => $item['createdAt'],
                'url' => $item['url'],
                'printable' => $item['source'] === 'payslip' && $item['row']->slip !== null,
                'steps' => $progress['steps'],
                'summary' => [
                    'where' => $summary['where'] ?? null, 'next' => $summary['next'] ?? null, 'reason' => $summary['reason'] ?? null,
                    // On a verification step the note is what the person who did the work wrote, not a reason for a return.
                    'reasonLabel' => $verify ? 'ملاحظة مرفقة' : 'السبب',
                    'dueAt' => $summary['dueAt'] ?? null, 'overdue' => (bool) ($summary['overdue'] ?? false),
                ],
            ];
        })->all();
    }
}
