<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Attendance\AttendancePermissionWorkflow;
use App\Services\Attendance\ShiftSwapWorkflow;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\LeaveWorkflow;
use App\Services\Organization\EffectiveAssignments;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\StepAssignments;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowSubjects;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «متابعة القسم»: the requests of the unified department (HrServicesDashboard::DEPARTMENT_CODE), one list for the
 * follow-up page, the head's home page, the department figures and quick search, so a number and the list it opens
 * are the same rows.
 *
 * In scope: general-service and maintenance requests (the department's own request types, at any stage), and any
 * other request that stands at, or has passed through, a step the department supervises (StepAssignments), such
 * as leave registration. Never a confidential workflow, never correspondence.
 *
 * Who holds an open request ("holder kind"):
 *  - "field"    the step waits for field work to be documented;
 *  - "assigned" a supervised step held by someone other than the head (assigned by the head, or picked);
 *  - "head"     a supervised step held by its supervisor, or any other step the department's head holds himself
 *               (the review of a request from his own department, or his own request back with him);
 *  - "other"    the step belongs to another party: the executive director, accounts, stores, purchasing, the
 *               requester or the requester's own head.
 *
 * Every public method answers only for a user HrServicesDashboard::canView accepts. A row's title, requester and
 * link are given only when the viewer may open the request itself (the rules of WorkflowVisibilityService).
 */
final class DepartmentFollowUp
{
    /** The department's own request types: workflow code => name of the type in the filter. */
    public const OWN_WORKFLOWS = ['SERVICE' => 'الخدمات العامة', 'MAINTENANCE' => 'الصيانة'];

    public const HOLDER_FILTERS = ['head' => 'لدى رئيس القسم', 'assigned' => 'إسناد رقمي', 'field' => 'في الميدان', 'other' => 'بانتظار جهة أخرى'];

    /**
     * «جاهز للإسناد»: part of "head", the stages flagged "assignable" that the viewing head holds now. Not a holder
     * kind of its own (the kinds add up to the total); it is what the head's home card of that name continues to.
     */
    public const READY_FILTER = 'ready';

    public const READY_LABEL = 'جاهز للإسناد';

    public const STATUS_FILTERS = ['open' => 'المفتوحة', 'overdue' => 'المتأخرة', 'urgent' => 'العاجلة والطارئة', 'closed' => 'المغلقة خلال 30 يومًا'];

    /**
     * Where an open service or maintenance request stands, in the groups of the HR head's dashboard (HR requirements
     * §5 «جديدة، بانتظار تحديد داخلي/خارجي، بانتظار تكلفة، بانتظار حسابات، قيد التنفيذ»; «متأخرة» is the status
     * filter). Only the department's own types are grouped; a stage an administrator adds under another code, or a
     * request back with its requester, is in none of them.
     */
    public const STAGE_FILTERS = ['new' => 'جديدة', 'classify' => 'بانتظار تحديد داخلي/خارجي', 'cost' => 'بانتظار التكلفة واعتمادها',
        'accounts' => 'بانتظار الحسابات', 'execution' => 'قيد التنفيذ', 'receipt' => 'بانتظار التحقق والإغلاق'];

    /** Stage code of MAINTENANCE and SERVICE => group of STAGE_FILTERS. */
    public const STAGE_GROUPS = [
        'department_head' => 'new', 'executive_director' => 'new',
        'hr_head' => 'classify', 'hr_classify' => 'classify', 'ed_external' => 'classify',
        'hr_cost' => 'cost', 'hr_cost_contract' => 'cost', 'ed_cost' => 'cost', 'ed_needs' => 'cost', 'financial_approval' => 'cost', 'higher_approval' => 'cost',
        'accounting' => 'accounts', 'stores' => 'accounts', 'procurement' => 'accounts', 'purchase' => 'accounts', 'materials' => 'accounts',
        'ext_accounting' => 'accounts', 'ext_payment' => 'accounts',
        'technician' => 'execution', 'execution' => 'execution', 'internal_execution' => 'execution', 'field_work' => 'execution',
        'ext_work' => 'execution', 'ext_execution' => 'execution',
        'verification' => 'receipt', 'ext_verification' => 'receipt', 'ext_receipt' => 'receipt', 'ed_closure' => 'receipt',
    ];

    /**
     * The appointment HR may set when assigning the technician or a field job (field "scheduled_at", migration
     * 2026_11_30_000040). While the request is still at the stage that action opened, the appointment is that
     * stage's deadline: past it, the request counts as «متأخرة» even without SLA hours.
     */
    public const APPOINTMENT_FIELD = 'scheduled_at';

    /** Module records are one type each, whatever their workflow definition (three definitions serve leave). */
    private const MODULE_TYPES = [
        LeaveWorkflow::SUBJECT_TYPE => 'الإجازات',
        AttendancePermissionWorkflow::SUBJECT_TYPE => 'أذونات الدوام',
        ShiftSwapWorkflow::SUBJECT_TYPE => 'تبديل المناوبات',
    ];

    private const EXCLUDED_SUBJECTS = ['official_correspondence', 'correspondence_edition'];

    private const CLOSED_STATUSES = ['completed' => ['مكتملة', 'success'], 'rejected' => ['مرفوضة', 'danger']];

    private const CLOSED_DAYS = 30;

    /** @var array<string, Collection<int, array<string, mixed>>> "open" | "closed" => rows, built once per request */
    private array $loaded = [];

    /** @var array<int, array<string, mixed>>|null instance id => StepAssignments::openSupervised() row */
    private ?array $supervised = null;

    /** @var list<int>|null */
    private ?array $stepIds = null;

    private int|false|null $departmentId = false;

    private int|false|null $headId = false;

    public function __construct(
        private readonly StepAssignments $assignments,
        private readonly AssigneeResolver $resolver,
        private readonly WorkflowSubjects $subjects,
        private readonly HrServicesDashboard $dashboard,
    ) {}

    /**
     * The request types the department follows, for the filter: its own two types first, then the module records
     * and other workflows that have a step it supervises.
     *
     * @return array<string, string> type key => label
     */
    public function types(): array
    {
        $types = self::OWN_WORKFLOWS;
        $stepIds = $this->supervisedStepIds();
        if ($stepIds === []) {
            return $types;
        }
        $definitions = DB::table('workflow_steps as s')->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->whereIn('s.id', $stepIds)->where('d.is_confidential', false)->whereNotIn('d.subject_type', self::EXCLUDED_SUBJECTS)
            ->distinct()->orderBy('d.name')->get(['d.code', 'd.name', 'd.subject_type']);
        foreach ($definitions as $definition) {
            $types[self::typeKey((string) $definition->subject_type, (string) $definition->code)] ??= self::MODULE_TYPES[$definition->subject_type] ?? (string) $definition->name;
        }

        return $types;
    }

    /**
     * The follow-up list for the given filters, one page of it. Rows carry "mine" (the viewer holds the step) and
     * "url" (null when the viewer may not open the request).
     *
     * @param  array{types?: list<string>, holder?: ?string, status?: ?string, stage?: ?string}  $filters
     */
    public function page(User $viewer, array $filters, int $page, int $perPage = 15): LengthAwarePaginator
    {
        $rows = $this->rows($viewer, $filters);
        $page = max(1, min($page, (int) ceil(max(1, $rows->count()) / $perPage)));
        $query = array_filter([
            'type' => implode(',', $filters['types'] ?? []), 'holder' => $filters['holder'] ?? null, 'stage' => $filters['stage'] ?? null,
            'status' => ($filters['status'] ?? 'open') === 'open' ? null : $filters['status'],
        ], fn ($value): bool => $value !== null && $value !== '');

        return new LengthAwarePaginator($this->present($viewer, $rows->forPage($page, $perPage)->values()), $rows->count(), $perPage, $page,
            ['path' => route('hr.dashboard', [], false), 'query' => $query]);
    }

    /**
     * The first rows of one holder kind among the open requests, for the head's home page.
     *
     * @return list<array<string, mixed>>
     */
    public function sample(User $viewer, string $holder, int $limit): array
    {
        return $this->present($viewer, $this->rows($viewer, ['holder' => $holder])->take($limit)->values());
    }

    /**
     * How the open requests are held: the total and one number per holder kind (they add up to the total), plus
     * those past a real deadline. Each number is the count of the list the same filter opens.
     *
     * "stages" counts the same open requests by stage group (STAGE_FILTERS). With a stage given, the holder numbers
     * count that stage only; with a holder given, the stage numbers count that holder only, so each number is the
     * list its filter opens together with the other one.
     *
     * @param  list<string>  $types  limit the count to these types; empty for all
     * @return array{total: int, head: int, assigned: int, field: int, other: int, overdue: int, stages: array<string, int>}
     */
    public function summary(User $viewer, array $types = [], ?string $holder = null, ?string $stage = null): array
    {
        $rows = $this->rows($viewer, ['types' => $types, 'stage' => $stage]);
        $byHolder = $rows->countBy('holder_kind');
        $byStage = $this->rows($viewer, ['types' => $types, 'holder' => $holder])->whereNotNull('stage_group')->countBy('stage_group');

        return ['total' => $rows->count(), 'head' => (int) ($byHolder['head'] ?? 0), 'assigned' => (int) ($byHolder['assigned'] ?? 0),
            'field' => (int) ($byHolder['field'] ?? 0), 'other' => (int) ($byHolder['other'] ?? 0), 'overdue' => $rows->where('overdue', true)->count(),
            'stages' => collect(self::STAGE_FILTERS)->map(fn (string $label, string $key): int => (int) ($byStage[$key] ?? 0))->all()];
    }

    /**
     * Open requests of the given types: all, past a real deadline, and urgent or emergency.
     *
     * @param  list<string>  $types
     * @return array{open: int, overdue: int, urgent: int}
     */
    public function counts(User $viewer, array $types): array
    {
        $rows = $this->rows($viewer, ['types' => $types]);

        return ['open' => $rows->count(), 'overdue' => $rows->where('overdue', true)->count(), 'urgent' => $rows->where('urgent', true)->count()];
    }

    /**
     * Requests in the department's scope whose reference or title matches, newest first, for quick search: only
     * those the viewer may open, so a request that has not reached them yet is not revealed by its title.
     *
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    public function search(User $viewer, string $like, int $limit): array
    {
        $scope = HrServicesDashboard::canView($viewer) ? $this->scope() : null;
        if ($scope === null) {
            return [];
        }
        $rows = $scope->where(fn (Builder $q) => $q->where('i.reference_no', 'ilike', $like)->orWhere('r.title', 'ilike', $like))
            ->orderByDesc('i.created_at')->limit($limit * 4)
            ->get(['i.id', 'i.public_id', 'i.reference_no', 'i.subject_type', 'i.subject_id', 'i.status', 'r.title', 'd.name as workflow_name', 's.name as step_name']);
        if ($rows->isEmpty()) {
            return [];
        }
        $visible = $this->dashboard->visibleInstances($viewer, $rows, $this->supervisedBy($viewer));

        return $rows->filter(fn (object $row): bool => isset($visible[(int) $row->id]))->take($limit)
            ->map(fn (object $row): array => [
                'title' => (string) ($row->title ?: $row->workflow_name),
                'subtitle' => trim(($row->reference_no ?? '').' · '.($row->status === 'in_progress' ? (string) $row->step_name : (self::CLOSED_STATUSES[$row->status][0] ?? '')), ' ·'),
                'url' => $this->subjects->url($row),
            ])->values()->all();
    }

    /**
     * Open requests whose current step the department supervises, as StepAssignments describes them (who holds
     * the step, whether it may be assigned, whether it waits for field work), keyed by instance id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function supervised(): array
    {
        if ($this->supervised === null) {
            $departmentId = $this->departmentId();
            $this->supervised = $departmentId === null ? [] : collect($this->assignments->openSupervised($departmentId))->keyBy('id')->all();
        }

        return $this->supervised;
    }

    /**
     * The filtered rows, without the per-viewer link: open requests longest-waiting first (those past a real
     * deadline before the rest), closed ones newest first.
     *
     * @param  array{types?: list<string>, holder?: ?string, status?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(User $viewer, array $filters): Collection
    {
        if (! HrServicesDashboard::canView($viewer)) {
            return collect();
        }
        $status = $filters['status'] ?? 'open';
        $rows = $status === 'closed' ? $this->load('closed') : $this->load('open');
        $types = $filters['types'] ?? [];
        $holder = $filters['holder'] ?? null;
        $stage = $filters['stage'] ?? null;

        $ready = $holder === self::READY_FILTER;

        return $rows
            ->when($types !== [], fn (Collection $list) => $list->filter(fn (array $row): bool => in_array($row['type'], $types, true)))
            ->when($stage !== null && $stage !== '' && $status !== 'closed', fn (Collection $list) => $list->where('stage_group', $stage))
            ->when($holder !== null && $holder !== '' && ! $ready && $status !== 'closed', fn (Collection $list) => $list->where('holder_kind', $holder))
            // The same rows as DepartmentHome::waiting()'s «جاهز للإسناد»: assignable stages the viewer holds as supervisor.
            ->when($ready && $status !== 'closed', fn (Collection $list) => $list->filter(fn (array $row): bool => $row['holder_kind'] === 'head'
                && $row['assignable'] && $row['mode'] === 'supervisor' && (int) ($this->supervised()[$row['id']]['holder_id'] ?? 0) === (int) $viewer->id))
            ->when($status === 'overdue', fn (Collection $list) => $list->where('overdue', true))
            ->when($status === 'urgent', fn (Collection $list) => $list->where('urgent', true))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function load(string $which): Collection
    {
        if (isset($this->loaded[$which])) {
            return $this->loaded[$which];
        }
        $scope = $this->scope();
        if ($scope === null) {
            return $this->loaded[$which] = collect();
        }
        $closed = $which === 'closed';
        if ($closed) {
            $scope->whereIn('i.status', array_keys(self::CLOSED_STATUSES))->where('i.completed_at', '>=', now()->subDays(self::CLOSED_DAYS))->orderByDesc('i.completed_at');
        } else {
            $scope->where('i.status', 'in_progress');
        }
        $records = $scope->select([
            'i.id', 'i.public_id', 'i.reference_no', 'i.subject_type', 'i.subject_id', 'i.status', 'i.due_at', 'i.held_at', 'i.started_at', 'i.completed_at',
            'i.current_step_id', 's.code as step_code',
            'd.code as workflow_code', 'd.name as workflow_name', 'r.title', 's.name as step_name', 's.assignment_type', 's.assigned_role_code',
            'requester.name as requester_name', 'origin.name as department_name',
        ])->selectSub(DB::table('workflow_participants as hp')->join('users as hu', 'hu.id', '=', 'hp.user_id')
            ->whereColumn('hp.workflow_instance_id', 'i.id')->where('hp.participation_type', 'assignee')->where('hp.is_active', true)
            ->selectRaw("string_agg(hu.name, '، ' ORDER BY hu.name)"), 'holders')->get();
        if ($records->isEmpty()) {
            return $this->loaded[$which] = collect();
        }

        $supervised = $closed ? [] : $this->supervised();
        $titles = $this->subjects->titles($records->where('subject_type', '!=', WorkflowEngine::SUBJECT_TYPE));
        $generic = $records->where('subject_type', WorkflowEngine::SUBJECT_TYPE)->pluck('subject_id');
        $urgent = $generic->isEmpty() ? collect() : DB::table('custom_field_values as f')
            ->join('custom_field_definitions as fd', 'fd.id', '=', 'f.definition_id')
            ->join('master_data_values as m', 'm.id', '=', 'f.value_master_id')
            ->where('f.entity_type', CustomFieldEntities::AIMS_REQUEST)->whereIn('f.entity_id', $generic)
            ->where('fd.code', 'priority')->whereIn('m.code', ['urgent', 'emergency'])->pluck('f.entity_id')->flip();
        // When each open request reached its present holder: its latest move or change of holder.
        $arrived = $closed ? collect() : DB::table('workflow_events')->whereIn('workflow_instance_id', $records->pluck('id'))
            ->where(fn (Builder $q) => $q->whereNotNull('workflow_transition_id')
                ->orWhereIn('action', [StepAssignments::ACTION_ASSIGNED, StepAssignments::ACTION_WITHDRAWN, 'reopen']))
            ->groupBy('workflow_instance_id')->selectRaw('workflow_instance_id, max(occurred_at) as at')->pluck('at', 'workflow_instance_id');
        $now = now()->toDateTimeString();
        // Open requests the department's head holds himself at a step the department does not supervise (for
        // example «مراجعة رئيس قسم مقدم الطلب» of a request from his own department): they wait for him, not for
        // another party.
        $headId = $closed ? null : $this->headId();
        $headHolds = $headId === null ? collect() : DB::table('workflow_participants')->whereIn('workflow_instance_id', $records->pluck('id'))
            ->where('user_id', $headId)->where('participation_type', 'assignee')->where('is_active', true)
            ->pluck('workflow_instance_id')->flip();
        $appointments = $closed ? [] : $this->appointments($records);
        $nowMinute = now()->format('Y-m-d H:i');

        $rows = $records->map(function (object $record) use ($closed, $supervised, $titles, $urgent, $arrived, $now, $headHolds, $appointments, $nowMinute): array {
            $step = $supervised[(int) $record->id] ?? null;
            $kind = match (true) {
                $closed => 'closed',
                $step === null => isset($headHolds[(int) $record->id]) ? 'head' : 'other',
                (bool) $step['field_work'] => 'field',
                $step['mode'] === 'assigned' => 'assigned',
                default => 'head',
            };
            $appointment = $appointments[(int) $record->id] ?? null;
            // A held request has no running deadline (the engine clears due_at), so its appointment waits too.
            $overdue = ! $closed && (($record->due_at !== null && (string) $record->due_at < $now)
                || ($appointment !== null && $record->held_at === null && $appointment < $nowMinute));
            $own = $record->subject_type === WorkflowEngine::SUBJECT_TYPE && isset(self::OWN_WORKFLOWS[$record->workflow_code]);
            [$statusLabel, $tone] = self::CLOSED_STATUSES[$record->status] ?? match (true) {
                $record->held_at !== null => ['معلقة مؤقتًا', 'warning'],
                $overdue => ['متأخر', 'danger'],
                default => ['قيد المعالجة', 'info'],
            };
            $holders = $step['holder_name'] ?? $record->holders;

            return [
                'id' => (int) $record->id,
                'key' => 'wf-'.$record->public_id,
                'reference' => $record->reference_no,
                'title' => $record->title ?? ($titles[$record->subject_type][(int) $record->subject_id] ?? null),
                'type' => self::typeKey((string) $record->subject_type, (string) $record->workflow_code),
                'kind' => $record->workflow_name,
                'step' => $closed ? null : $record->step_name,
                'holder_kind' => $kind,
                'holder' => match ($kind) {
                    'closed' => null,
                    'other' => trim(self::party($record).($holders ? ' — '.$holders : ''), ' —'),
                    default => $holders,
                },
                'assigned_by' => $step['assigned_by_name'] ?? null,
                'mode' => $step['mode'] ?? null,
                'assignable' => (bool) ($step['assignable'] ?? false),
                'field_work' => (bool) ($step['field_work'] ?? false),
                // The worker named on a field job that waits to be documented (StepAssignments::openSupervised).
                'worker' => $step['field_worker'] ?? null,
                'requester' => $record->requester_name,
                'department' => $record->department_name,
                'since' => $closed ? $record->completed_at : ($step['held_since'] ?? $arrived[$record->id] ?? $record->started_at),
                'due_at' => $closed ? null : $record->due_at,
                // HR's appointment for the stage the request stands at (local "Y-m-dTH:i"), when one was set.
                'appointment' => $appointment !== null ? str_replace(' ', 'T', $appointment) : null,
                'stage_group' => ! $closed && $own ? (self::STAGE_GROUPS[(string) $record->step_code] ?? null) : null,
                'overdue' => $overdue,
                'on_hold' => ! $closed && $record->held_at !== null,
                'urgent' => $record->subject_type === WorkflowEngine::SUBJECT_TYPE && isset($urgent[$record->subject_id]),
                'status' => $statusLabel,
                'tone' => $tone,
                'open' => ! $closed,
                'instance' => $record,
            ];
        });

        return $this->loaded[$which] = ($closed ? $rows : $rows->sortBy(fn (array $row): string => ($row['overdue'] ? '0' : '1').' '.($row['since'] ?? '9999')))->values();
    }

    /**
     * The appointment of each open service or maintenance request that still stands at the stage the action which
     * recorded it opened: its latest move recorded "scheduled_at" (kept with the event by
     * CustomFieldService::actionRecord) and led to the current stage. Any later move, a return or a cancelled field
     * job ends it.
     *
     * @param  Collection<int, object>  $records
     * @return array<int, string> instance id => "Y-m-d H:i" (local time)
     */
    private function appointments(Collection $records): array
    {
        $current = $records->filter(fn (object $record): bool => $record->subject_type === WorkflowEngine::SUBJECT_TYPE
            && isset(self::OWN_WORKFLOWS[$record->workflow_code]))->mapWithKeys(fn (object $record): array => [(int) $record->id => (int) $record->current_step_id]);
        if ($current->isEmpty()) {
            return [];
        }
        $latest = DB::table('workflow_events')->whereIn('workflow_instance_id', $current->keys())->whereNotNull('workflow_transition_id')
            ->groupBy('workflow_instance_id')->selectRaw('max(id) as id')->pluck('id');
        $appointments = [];
        foreach (DB::table('workflow_events')->whereIn('id', $latest)->get(['workflow_instance_id', 'to_step_id', 'metadata']) as $event) {
            if ((int) $event->to_step_id !== ($current[(int) $event->workflow_instance_id] ?? 0)) {
                continue;
            }
            foreach (CustomFieldService::eventFields($event->metadata) as $field) {
                if ($field['code'] === self::APPOINTMENT_FIELD && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $field['value'])) {
                    $appointments[(int) $event->workflow_instance_id] = $field['value'];
                }
            }
        }

        return $appointments;
    }

    /**
     * Adds what depends on the viewer to the rows shown: whether they hold the step, and the link when they may
     * open the request. Without that right the row keeps only what a list of the department's work shows anyway
     * (reference, type, stage, holder, origin department).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(User $viewer, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = $rows->pluck('id');
        $visible = $this->dashboard->visibleInstances($viewer, $rows, $this->supervisedBy($viewer));
        $mine = DB::table('workflow_participants as p')->join('workflow_instances as i', 'i.id', '=', 'p.workflow_instance_id')
            ->whereIn('p.workflow_instance_id', $ids)->where('p.user_id', $viewer->id)->where('p.participation_type', 'assignee')
            ->where('p.is_active', true)->where('i.status', 'in_progress')->pluck('p.workflow_instance_id')->flip();
        $supervises = $viewer->isHrDepartmentHead();

        return $rows->map(function (array $row) use ($visible, $mine, $supervises): array {
            $url = isset($visible[$row['id']]) ? $this->subjects->url($row['instance']) : null;
            unset($row['instance']);

            return [...$row,
                'title' => $url ? $row['title'] : null,
                'requester' => $url ? $row['requester'] : null,
                'worker' => $url ? $row['worker'] : null,
                'mine' => $url !== null && isset($mine[$row['id']]),
                'url' => $url,
                // «سحب الإسناد» and «إعادة الإسناد» are done on the request's assignment panel (StepAssignmentPanel).
                'assignment_url' => $url !== null && $supervises && $row['mode'] === 'assigned' ? $url.'#assignment' : null,
            ];
        })->all();
    }

    /**
     * Instances whose current step the viewer supervises: the department's head opens every such request.
     *
     * @return list<int>
     */
    private function supervisedBy(User $viewer): array
    {
        return $viewer->isHrDepartmentHead() ? array_keys($this->supervised()) : [];
    }

    /**
     * Every request in the department's scope, with its definition, current step, requester and origin department.
     */
    private function scope(): ?Builder
    {
        if ($this->departmentId() === null) {
            return null;
        }
        $stepIds = $this->supervisedStepIds() ?: [0];

        return DB::table('workflow_instances as i')
            ->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->join('users as requester', 'requester.id', '=', 'i.requested_by')
            ->leftJoin('departments as origin', 'origin.id', '=', 'i.department_id')
            ->leftJoin('aims_requests as r', fn ($join) => $join->on('r.id', '=', 'i.subject_id')->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE))
            ->where('d.is_confidential', false)
            ->whereNotIn('i.subject_type', self::EXCLUDED_SUBJECTS)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $own) => $own->where('i.subject_type', WorkflowEngine::SUBJECT_TYPE)->whereIn('d.code', array_keys(self::OWN_WORKFLOWS)))
                ->orWhereIn('i.current_step_id', $stepIds)
                ->orWhereExists(fn (Builder $event) => $event->selectRaw('1')->from('workflow_events as e')->whereColumn('e.workflow_instance_id', 'i.id')
                    ->where(fn (Builder $step) => $step->whereIn('e.from_step_id', $stepIds)->orWhereIn('e.to_step_id', $stepIds))));
    }

    /**
     * The steps the department supervises, in every version: the configuration text narrows the search and the
     * resolver decides, as in StepAssignments::openSupervised().
     *
     * @return list<int>
     */
    private function supervisedStepIds(): array
    {
        if ($this->stepIds !== null) {
            return $this->stepIds;
        }
        $departmentId = $this->departmentId();
        if ($departmentId === null) {
            return $this->stepIds = [];
        }

        return $this->stepIds = DB::table('workflow_steps as s')
            ->where(fn (Builder $q) => $q
                ->whereRaw("(s.assignment_config::jsonb ->> 'department_id') = ?", [(string) $departmentId])
                ->orWhereRaw("(s.assignment_config::jsonb ->> 'supervisor_department_id') = ?", [(string) $departmentId]))
            ->get(['s.id', 's.step_type', 's.assignment_type', 's.assignment_config'])
            ->filter(fn (object $step): bool => $this->resolver->supervisorDepartmentId($step) === $departmentId)
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /** The unified department's appointed head (active, holding the department_head role there), read once. */
    private function headId(): ?int
    {
        if ($this->headId === false) {
            $departmentId = $this->departmentId();
            $id = $departmentId === null ? null : DB::table('departments as d')
                ->join('users as u', 'u.id', '=', 'd.head_user_id')
                ->join('department_user_roles as a', fn ($join) => $join->on('a.user_id', '=', 'u.id')->on('a.department_id', '=', 'd.id'))
                ->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('d.id', $departmentId)->where('u.is_active', true)->where('r.code', 'department_head')->whereRaw(EffectiveAssignments::sql('a'))
                ->value('u.id');
            $this->headId = $id === null ? null : (int) $id;
        }

        return $this->headId;
    }

    /** The unified department's id, read once. */
    private function departmentId(): ?int
    {
        if ($this->departmentId === false) {
            $this->departmentId = HrServicesDashboard::departmentId();
        }

        return $this->departmentId;
    }

    /** Generic requests are told apart by their workflow; a module record is one type whatever its workflow. */
    private static function typeKey(string $subjectType, string $workflowCode): string
    {
        return $subjectType === WorkflowEngine::SUBJECT_TYPE ? $workflowCode : $subjectType;
    }

    /** Who a step outside the department waits for, from the step's own assignment. */
    private static function party(object $record): string
    {
        $role = (string) ($record->assigned_role_code ?? '');

        return match (true) {
            $record->assignment_type === 'requester' => 'مقدم الطلب',
            $record->assignment_type === 'department_head' => 'رئيس قسم مقدم الطلب',
            $role === 'executive_director' => 'المدير التنفيذي',
            in_array($role, ['finance_warehouse', 'storekeeper'], true) => 'المخزن',
            in_array($role, ['finance_procurement', 'procurement_officer'], true) => 'المشتريات',
            str_starts_with($role, 'finance_') => 'الحسابات',
            default => 'جهة أخرى',
        };
    }
}
