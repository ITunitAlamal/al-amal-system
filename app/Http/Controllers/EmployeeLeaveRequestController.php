<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeLeaveBalanceService;
use App\Services\LeaveApproverResolver;
use App\Services\LeaveDayCounter;
use App\Services\LeaveDecisionService;
use App\Services\LeaveSubmissionRules;
use App\Services\LeaveWorkflow;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\Authority;
use App\Services\Progress\LeaveProgress;
use App\Services\Workflow\StepAssignments;
use App\Services\Workflow\WorkflowEngine;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee leave requests: draft, submit, follow, cancel; and the decision screen.
 *
 * Two approval modes (leave settings): "workflow" runs the request through the workflow engine on the path
 * the settings name (employee → department head → executive director, or heads → executive director);
 * "department_or_executive" is the earlier single-step mode, kept for requests made under it.
 */
final class EmployeeLeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveDecisionService $decisions,
        private readonly LeaveSubmissionRules $rules,
        private readonly LeaveWorkflow $workflow,
        private readonly WorkflowVisibilityService $visibility,
    ) {}

    private function settings(): ?object
    {
        return $this->rules->settings();
    }

    private function self(Request $r): Employee
    {
        abort_unless($r->user()?->is_active, 403);
        $employee = $r->user()->employeeProfile;
        abort_unless($employee && $employee->onboarding_status === 'completed' && $employee->employment_status === 'active', 403, 'يجب تفعيل ملف الموظف وإكماله قبل طلب الإجازة.');

        return $employee;
    }

    private function types(): array
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 'v.type_id', '=', 't.id')
            ->where('t.code', 'leave_types')->where('t.is_active', true)->where('v.is_active', true)
            ->orderBy('v.name_ar')->get(['v.id', 'v.name_ar'])->toArray();
    }

    private function navigation(): array
    {
        return [['label' => 'الإجازات', 'url' => route('leaves.index'), 'icon' => '▦', 'active' => true]];
    }

    private function mayRead(Request $r, object $leave): bool
    {
        return $r->user()->isSystemAdmin() || $r->user()->canManageEmployees()
            || (int) $leave->requested_by === (int) $r->user()->id
            || $this->managerOf($r, (int) $leave->employee_id)
            || (! $leave->workflow_instance_id && $this->approverFor($leave) === (int) $r->user()->id)
            || ($leave->workflow_instance_id && $this->visibility->accessLevel($r->user(), (int) $leave->workflow_instance_id) !== null);
    }

    /** The valid head of the employee's department (Organization\Authority); head_user_id alone is not enough. */
    private function managerOf(Request $r, int $employeeId): bool
    {
        $headed = app(Authority::class)->headedDepartmentIds($r->user());

        return $headed !== [] && DB::table('employees')->where('id', $employeeId)->whereIn('department_id', $headed)
            ->whereNull('deleted_at')->exists();
    }

    private function isExecutive(int $userId): bool
    {
        return app(LeaveApproverResolver::class)->isExecutive($userId);
    }

    private function approverFor(object $leave): ?int
    {
        return app(LeaveApproverResolver::class)->approverFor($leave);
    }

    private function record(int $id): object
    {
        return DB::table('employee_leave_requests')->where('id', $id)->first() ?? abort(404);
    }

    private function log(int $id, Request $r, string $before, string $after, ?string $comment = null): void
    {
        $this->decisions->log($id, (int) $r->user()->id, $before, $after, $comment);
    }

    public function index(Request $r, LeaveProgress $progress): Response
    {
        abort_unless($r->user()?->is_active, 403);
        $managed = $r->user()->canManageEmployees();
        $query = DB::table('employee_leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->leftJoin('workflow_instances as wi', 'wi.id', '=', 'l.workflow_instance_id')
            ->select(['l.id', 'l.employee_id', 'l.leave_type_value_id', 'l.requested_by', 'l.start_date', 'l.end_date', 'l.requested_days', 'l.status', 'l.created_at',
                'l.submitted_at', 'l.workflow_instance_id', 'wi.reference_no', 'e.employee_number', 'e.first_name_ar', 'e.family_name_ar', 't.name_ar as leave_type_name']);
        if (! $managed) {
            $headed = app(Authority::class)->headedDepartmentIds($r->user());
            $query->where(function ($q) use ($r, $headed) {
                // The requester, the valid head of the employee's department (Organization\Authority), …
                $q->where('l.requested_by', $r->user()->id)->orWhereIn('e.department_id', $headed)->orWhereExists(function ($s) use ($r) {
                    // Anyone the request's workflow reached (current holder, earlier reviewers).
                    $s->selectRaw('1')->from('workflow_participants as p')
                        ->whereColumn('p.workflow_instance_id', 'l.workflow_instance_id')
                        ->where('p.user_id', $r->user()->id)->where('p.is_active', true);
                });
                if ($this->isExecutive((int) $r->user()->id)) {
                    $q->orWhere(function ($execQuery) {
                        $execQuery->where('l.status', 'submitted')->whereNull('l.workflow_instance_id')->where(function ($target) {
                            $target->whereNull('e.department_id')
                                ->orWhereExists(function ($head) {
                                    $head->selectRaw('1')->from('departments as hd')
                                        ->whereColumn('hd.id', 'e.department_id')
                                        ->whereColumn('hd.head_user_id', 'l.requested_by');
                                });
                        });
                    });
                }
            });
        }
        $requests = $query->orderByDesc('l.id')->paginate(20)->withQueryString();
        $steps = $progress->forList(collect($requests->items()));

        return Inertia::render('Leaves/Index', [
            'username' => $r->user()->name, 'navigation' => $this->navigation(),
            'canManage' => $managed, 'canCreate' => $r->user()->employeeProfile?->onboarding_status === 'completed' && $r->user()->employeeProfile?->employment_status === 'active',
            'isAdmin' => $r->user()->isSystemAdmin(),
            'hrNotifications' => $r->user()->isHrDepartmentHead()
                ? DB::table('leave_hr_notifications as n')->join('employee_leave_requests as l', 'l.id', '=', 'n.leave_request_id')
                    ->where('n.recipient_user_id', $r->user()->id)->orderByDesc('n.id')
                    ->limit(50)->get(['n.leave_request_id', 'n.event', 'n.created_at']) : [],
            'requests' => $requests->through(fn (object $row): object => (object) [
                ...(array) $row,
                'steps' => $steps[(int) $row->id]['steps'] ?? [],
                'where' => $steps[(int) $row->id]['summary']['where'] ?? null,
            ]),
        ]);
    }

    public function create(Request $r): Response
    {
        $employee = $this->self($r);

        return Inertia::render('Leaves/Create', [
            'username' => $r->user()->name, 'navigation' => $this->navigation(),
            'employee' => $employee->only(['id', 'employee_number', 'work_start_date']),
            'types' => $this->types(), 'settings' => $this->settings(), 'balances' => $this->balances($employee),
        ]);
    }

    /**
     * Days the employee can still request this year, per active leave type (shown on the request form).
     *
     * @return array<int, array{available: float, reserved: float, opening_recorded: bool, year: int}>
     */
    private function balances(Employee $employee): array
    {
        $year = (int) now()->year;
        $service = app(EmployeeLeaveBalanceService::class);
        $balances = [];
        foreach ($this->types() as $type) {
            $summary = $service->summary((int) $employee->id, (int) $type->id, $year);
            $balances[(int) $type->id] = [
                'available' => $summary['available'], 'reserved' => $summary['reserved'],
                'opening_recorded' => $summary['opening_recorded'], 'year' => $year,
            ];
        }

        return $balances;
    }

    /**
     * @return array<string, list<string>>
     */
    private function requestRules(): array
    {
        return [
            'leave_type_value_id' => ['required', 'integer'],
            // A request can only be sent for a leave that has not started yet, so a past draft would be a dead end.
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function requestMessages(): array
    {
        return ['start_date.after_or_equal' => 'تاريخ بداية الإجازة لا يكون في الماضي. اختر اليوم أو تاريخًا لاحقًا.'];
    }

    public function store(Request $r, LeaveDayCounter $counter): RedirectResponse
    {
        $employee = $this->self($r);
        $data = $r->validate($this->requestRules(), $this->requestMessages());
        if (! in_array((int) $data['leave_type_value_id'], array_map(fn ($v) => (int) $v->id, $this->types()), true)) {
            throw ValidationException::withMessages(['leave_type_value_id' => 'نوع الإجازة غير نشط أو غير معتمد.']);
        }
        if (substr($data['start_date'], 0, 4) !== substr($data['end_date'], 0, 4)) {
            throw ValidationException::withMessages(['end_date' => 'يجب تقديم طلب منفصل لكل سنة مالية.']);
        }
        $settings = $this->settings();
        $days = $settings && $settings->counting_mode ? $counter->days($data['start_date'], $data['end_date'], $settings) : null;
        $id = DB::table('employee_leave_requests')->insertGetId([
            'employee_id' => $employee->id, 'leave_type_value_id' => $data['leave_type_value_id'],
            'requested_by' => $r->user()->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
            'requested_days' => $days, 'status' => 'draft', 'reason' => $data['reason'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('leaves.show', $id)->with('success', 'حُفظ الطلب كمسودة، ولم يُخصم الرصيد.');
    }

    /** A draft, or a request returned to its requester for changes, can be edited by its requester. */
    private function editable(Request $r, object $row, Employee $employee): bool
    {
        return in_array($row->status, ['draft', 'returned'], true) && (int) $row->requested_by === (int) $r->user()->id
            && (int) $row->employee_id === (int) $employee->id;
    }

    public function edit(Request $r, int $leave): Response
    {
        $employee = $this->self($r);
        $row = $this->record($leave);
        abort_unless($this->editable($r, $row, $employee), 403);

        return Inertia::render('Leaves/Create', [
            'username' => $r->user()->name, 'navigation' => $this->navigation(),
            'employee' => $employee->only(['id', 'employee_number', 'work_start_date']),
            'types' => $this->types(), 'settings' => $this->settings(), 'existing' => $row,
            'balances' => $this->balances($employee),
        ]);
    }

    public function update(Request $r, int $leave, LeaveDayCounter $counter): RedirectResponse
    {
        $employee = $this->self($r);
        $data = $r->validate($this->requestRules(), $this->requestMessages());
        if (! in_array((int) $data['leave_type_value_id'], array_map(fn ($v) => (int) $v->id, $this->types()), true)) {
            throw ValidationException::withMessages(['leave_type_value_id' => 'نوع الإجازة غير معتمد.']);
        }
        if (substr($data['start_date'], 0, 4) !== substr($data['end_date'], 0, 4)) {
            throw ValidationException::withMessages(['end_date' => 'يجب تقديم طلب منفصل لكل سنة مالية.']);
        }
        $settings = $this->settings();
        $days = $settings && $settings->counting_mode ? $counter->days($data['start_date'], $data['end_date'], $settings) : null;
        $status = DB::transaction(function () use ($r, $leave, $employee, $data, $days): string {
            $row = DB::table('employee_leave_requests')->where('id', $leave)->lockForUpdate()->first();
            abort_unless($row && $this->editable($r, $row, $employee), 403);
            DB::table('employee_leave_requests')->where('id', $leave)->update([
                'leave_type_value_id' => $data['leave_type_value_id'], 'start_date' => $data['start_date'],
                'end_date' => $data['end_date'], 'reason' => $data['reason'], 'requested_days' => $days, 'updated_at' => now(),
            ]);
            $this->log($leave, $r, $row->status, $row->status, $row->status === 'draft' ? 'تعديل المسودة' : 'تعديل الطلب بعد إعادته');

            return $row->status;
        });

        return redirect()->route('leaves.show', $leave)->with('success', $status === 'draft'
            ? 'تم تحديث المسودة.'
            : 'حُفظت التعديلات. أعد إرسال الطلب من صفحته ليكمل مساره.');
    }

    public function show(Request $r, int $leave, WorkflowEngine $engine, LeaveProgress $progress): Response
    {
        abort_unless($r->user()?->is_active, 403);
        $row = $this->record($leave);
        abort_unless($this->mayRead($r, $row), 403);
        $isOwner = (int) $row->requested_by === (int) $r->user()->id;
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $level = $instance
            ? ($this->visibility->accessLevel($r->user(), (int) $instance->id) ?? ($r->user()->canManageEmployees() ? 'operational' : 'public_status'))
            : 'operational';

        return Inertia::render('Leaves/Show', [
            'username' => $r->user()->name, 'navigation' => $this->navigation(),
            'request' => $row,
            'canApprove' => ! $instance && $row->status === 'submitted' && $this->approverFor($row) === (int) $r->user()->id,
            'progress' => $progress->view($row, $level),
            'workflow' => $instance ? [
                'publicId' => $instance->public_id,
                'referenceNo' => $instance->reference_no,
                'revision' => (int) $instance->revision,
                'name' => DB::table('workflow_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                    ->where('v.id', $instance->workflow_version_id)->value('d.name'),
                'actions' => $engine->availableActions($instance, $r->user()),
            ] : null,
            // «إسناد إلى موظف»: the HR head may hand the registration step to an HR employee, who then acts here.
            'assignment' => $instance ? app(StepAssignments::class)->panel($instance, $r->user(), $level) : null,
            'isOwner' => $isOwner,
            'canEdit' => $isOwner && in_array($row->status, ['draft', 'returned'], true),
            'settings' => $this->settings(),
            'history' => DB::table('employee_leave_approvals as a')->join('users as u', 'u.id', '=', 'a.actor_id')
                ->where('a.leave_request_id', $leave)->orderBy('a.id')->get(['a.from_status', 'a.to_status', 'a.comment', 'a.created_at', 'u.name']),
        ]);
    }

    public function submit(Request $r, int $leave): RedirectResponse
    {
        $employee = $this->self($r);
        $reference = DB::transaction(function () use ($r, $leave, $employee): ?string {
            $row = DB::table('employee_leave_requests')->where('id', $leave)->lockForUpdate()->first();
            abort_unless($row && (int) $row->employee_id === (int) $employee->id && (int) $row->requested_by === (int) $r->user()->id, 403);
            if ($row->status !== 'draft') {
                throw ValidationException::withMessages(['status' => $row->status === 'returned'
                    ? 'الطلب مُعاد إليك: عدّله إن لزم ثم أعد إرساله من «الإجراء المطلوب منك».'
                    : 'لا يمكن إرسال طلب غير مسودة.']);
            }
            $days = $this->rules->validate($row, $employee);
            $mode = $this->settings()?->approval_mode;

            if ($mode === 'workflow') {
                DB::table('employee_leave_requests')->where('id', $leave)->update(['requested_days' => $days, 'submitted_at' => now(), 'updated_at' => now()]);
                $instance = $this->workflow->start($r->user(), DB::table('employee_leave_requests')->where('id', $leave)->first(), $employee);

                return $instance->reference_no;
            }

            if ($mode !== 'department_or_executive') {
                throw ValidationException::withMessages(['status' => 'مسار الموافقات المعتمد غير مهيأ.']);
            }
            if ($this->approverFor($row) === null) {
                throw ValidationException::withMessages(['status' => 'لا يوجد معتمد صالح للطلب؛ تأكد من رئيس القسم أو المدير التنفيذي.']);
            }
            DB::table('employee_leave_requests')->where('id', $leave)->update(['status' => 'submitted', 'requested_days' => $days, 'submitted_at' => now(), 'updated_at' => now()]);
            $this->log($leave, $r, 'draft', 'submitted');
            $this->decisions->notifyHr($row, 'submitted');
            $approverId = $this->approverFor($row);
            if ($approverId !== null && $approverId !== (int) $r->user()->id) {
                app(NotificationCenter::class)->notify($approverId, 'leave_awaiting_approval', [
                    'title' => 'طلب إجازة بانتظار قرارك: '.$r->user()->name,
                    'body' => 'من '.$row->start_date.' إلى '.$row->end_date,
                    'url' => route('leaves.show', ['leave' => $leave], false),
                    'dedupe' => 'leave:'.$leave.':submitted:'.now()->timestamp,
                ]);
            }

            return null;
        });

        return back()->with('success', $reference
            ? 'تم تقديم الطلب برقم '.$reference.' وحُجزت أيامه لحين الاعتماد. تتبّع مراحله من هذه الصفحة.'
            : 'تم تقديم الطلب، وحُجزت أيامه لحين الاعتماد.');
    }

    /**
     * Decision in the legacy single-step mode. Requests on the workflow engine are decided from their workflow actions.
     */
    public function decide(Request $r, int $leave): RedirectResponse
    {
        abort_unless($r->user()?->is_active, 403);
        $data = $r->validate(['decision' => ['required', 'in:approve,reject'], 'comment' => ['nullable', 'string', 'max:5000']]);
        DB::transaction(function () use ($r, $leave, $data) {
            $row = DB::table('employee_leave_requests')->where('id', $leave)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->workflow_instance_id) {
                throw ValidationException::withMessages(['status' => 'هذا الطلب يسير على مسار العمل؛ اتخذ القرار من «الإجراء المطلوب منك» في صفحة الطلب.']);
            }
            $mode = $this->settings()?->approval_mode;
            if ($row->status !== 'submitted' || $mode !== 'department_or_executive') {
                throw ValidationException::withMessages(['status' => 'هذا الطلب ليس بانتظار الاعتماد في المسار المعتمد.']);
            }
            // No self-approval, except the executive director's own leave (owner decision 2026-11-27).
            abort_if((int) $row->requested_by === (int) $r->user()->id && ! app(LeaveApproverResolver::class)->isOwnExecutiveLeave($row),
                403, 'لا يعتمد مقدم الطلب طلبه.');
            $approverId = $this->approverFor($row);
            abort_unless($approverId !== null && $approverId === (int) $r->user()->id, 403);
            $data['decision'] === 'approve'
                ? $this->decisions->approve($row, (int) $r->user()->id, $data['comment'] ?? null, true)
                : $this->decisions->reject($row, (int) $r->user()->id, $data['comment'] ?? null, true);
        });

        return back()->with('success', 'تم توثيق القرار.');
    }

    public function cancel(Request $r, int $leave): RedirectResponse
    {
        abort_unless($r->user()?->is_active, 403);
        DB::transaction(function () use ($r, $leave) {
            $row = DB::table('employee_leave_requests')->where('id', $leave)->lockForUpdate()->first();
            abort_unless($row && (int) $row->requested_by === (int) $r->user()->id, 403);
            if (! in_array($row->status, ['draft', 'submitted', 'manager_approved', 'returned'], true)) {
                // There is no reversal action: a registered leave stays in the record and only its balance is corrected,
                // by the system administrator's documented adjustment («تسوية موثقة») on «أرصدة الإجازات».
                throw ValidationException::withMessages(['status' => match ($row->status) {
                    'approved' => 'هذه الإجازة مسجلة وخُصمت من رصيدك، ولا يمكن إلغاؤها من هذه الصفحة. راجع رئيس قسم الموارد البشرية واذكر سبب الإلغاء؛ تُعاد الأيام إلى رصيدك بـ«تسوية موثقة» يسجلها مدير النظام من صفحة «أرصدة الإجازات»، ويبقى الطلب في سجلك بحالته.',
                    'pending_registration' => 'اعتُمدت الإجازة وهي بانتظار تسجيل الموارد البشرية، ولا يمكن إلغاؤها من هذه الصفحة. راجع رئيس قسم الموارد البشرية قبل تسجيلها؛ بيده إعادتها إلى صاحب الاعتماد بدل تسجيلها. لم يُخصم شيء من رصيدك بعد.',
                    default => 'هذا الطلب منتهٍ (مرفوض أو ملغى) ولا يحتاج إلى إلغاء.',
                }]);
            }
            if ($row->workflow_instance_id) {
                $this->workflow->cancel($r->user(), $row);
            }
            DB::table('employee_leave_requests')->where('id', $leave)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->log($leave, $r, $row->status, 'cancelled');
            if ($row->status !== 'draft') {
                $this->decisions->notifyHr($row, 'cancelled');
            }
        });

        return redirect()->route('leaves.index')->with('success', 'أُلغي الطلب دون خصم الرصيد.');
    }
}
