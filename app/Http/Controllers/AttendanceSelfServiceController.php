<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\AttendanceRequestsController;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendancePermissionService;
use App\Services\Attendance\AttendanceSettings;
use App\Services\Attendance\HrActionService;
use App\Services\Attendance\ShiftSwapService;
use App\Services\AuditService;
use App\Services\Hr\EmployeeAccess;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The employee's own attendance requests («طلب إذن», «طلب تبديل مناوبة») and their administrative actions, started
 * from «الحضور والانصراف» in their file. Every action is limited to the signed-in employee's own records; the
 * request page is also open to HR, the employee's department head and the workflow's participants.
 */
final class AttendanceSelfServiceController extends Controller
{
    public function __construct(private AttendanceAccess $access) {}

    /** One page for a permission or swap request: details, progress, and the actions this viewer may take. */
    public function show(Request $request, string $kind, int $id, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        abort_unless($user?->is_active && in_array($kind, ['permission', 'swap'], true), 404);
        $tz = config('app.timezone');
        $local = fn (mixed $value, string $format = 'Y-m-d H:i'): ?string => $value ? CarbonImmutable::parse($value)->setTimezone($tz)->format($format) : null;
        if ($kind === 'permission') {
            $row = DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')->where('r.id', $id)
                ->first(['r.*', 't.name_ar as type_name', 't.metadata as type_rules']);
            abort_unless($row, 404);
            $employees = [(int) $row->employee_id];
            $owner = (int) $row->requested_by === (int) $user->id;
            $details = ['type' => $row->type_name, 'effect' => AttendancePermissionService::EFFECTS[AttendancePermissionService::rules($row->type_rules)['effect']] ?? null,
                'work_date' => (string) $row->work_date, 'from' => $local($row->starts_at, 'H:i'), 'to' => $local($row->ends_at, 'H:i'), 'minutes' => (int) $row->minutes,
                'reason' => $row->reason, 'status' => $row->status, 'status_label' => AttendancePermissionService::STATUS[$row->status] ?? $row->status,
                'source' => $row->source === 'hr' ? 'سجلته الموارد البشرية' : 'طلب الموظف', 'decision_note' => $row->decision_note, 'decided_at' => $local($row->decided_at),
                'revocation_reason' => $row->revocation_reason, 'attachment_name' => $row->attachment_name,
                'attachment_url' => $row->attachment_path ? '/attendance/permissions/'.$row->id.'/attachment' : null];
            $can = ['cancel' => $owner && $row->status === 'submitted', 'revoke' => $row->status === 'approved' && $this->access->canDecideHr($user)];
        } else {
            $row = DB::table('attendance_shift_swaps')->where('id', $id)->first();
            abort_unless($row, 404);
            $employees = [(int) $row->requester_employee_id, (int) $row->counterpart_employee_id];
            $names = DB::table('employees')->whereIn('id', $employees)->get(['id', 'first_name_ar', 'father_name_ar', 'family_name_ar'])->keyBy('id');
            $counterpartUser = (int) DB::table('employees')->where('id', $row->counterpart_employee_id)->value('user_id');
            $details = ['requester' => AttendanceLedger::fullName($names[$row->requester_employee_id]), 'counterpart' => AttendanceLedger::fullName($names[$row->counterpart_employee_id]),
                'days' => array_map(fn (array $day): array => ['date' => $day['date'], 'requester' => $day['requester_before']['label'], 'counterpart' => $day['counterpart_before']['label']],
                    json_decode((string) $row->days, true) ?: []),
                'reason' => $row->reason, 'status' => $row->status, 'status_label' => ShiftSwapService::STATUS[$row->status] ?? $row->status,
                'counterpart_note' => $row->counterpart_note, 'counterpart_responded_at' => $local($row->counterpart_responded_at),
                'decision_note' => $row->decision_note, 'decided_at' => $local($row->decided_at), 'reversal_reason' => $row->reversal_reason, 'reversed_at' => $local($row->reversed_at)];
            $can = ['respond' => $counterpartUser === (int) $user->id && $row->status === 'awaiting_counterpart',
                'cancel' => (int) $row->requested_by === (int) $user->id && in_array($row->status, ShiftSwapService::OPEN, true),
                'reverse' => $row->status === 'approved' && $this->access->canDecideHr($user)];
        }
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $level = $instance ? $visibility->accessLevel($user, (int) $instance->id) : null;
        abort_unless($level !== null || $this->mayRead($user, $employees, (int) $row->requested_by), 403);

        return Inertia::render('Attendance/RequestShow', [
            'kind' => $kind, 'id' => $id, 'details' => $details, 'can' => $can,
            'progress' => $instance ? $progress->view($instance, $level ?? 'public_status') : ['steps' => [], 'summary' => null],
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision,
                'actions' => $engine->availableActions($instance, $user)] : null,
        ]);
    }

    public function submitPermission(Request $request, AttendancePermissionService $permissions): RedirectResponse
    {
        abort_unless($permissions->available(), 409, 'خدمة طلب الإذن غير مفعلة بعد.');
        $employee = $this->own($request->user());
        $data = $request->validate(AttendanceRequestsController::permissionRules());
        $row = $permissions->submit($request->user(), $employee, $data, $request->file('attachment'));

        return back()->with('success', 'أُرسل طلب الإذن ('.DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->value('reference_no').'). لا يُحتسب قبل اعتماده.');
    }

    public function cancelPermission(Request $request, int $permission, AttendancePermissionService $permissions): RedirectResponse
    {
        $row = DB::table('attendance_permission_requests')->where('id', $permission)->first();
        abort_unless($row, 404);
        $permissions->cancel($request->user(), $row);

        return back()->with('success', 'سُحب طلب الإذن.');
    }

    public function permissionAttachment(Request $request, int $permission, WorkflowVisibilityService $visibility): StreamedResponse
    {
        $row = DB::table('attendance_permission_requests')->where('id', $permission)->first();
        abort_unless($row && $row->attachment_path, 404);
        $user = $request->user();
        $participant = $row->workflow_instance_id && $visibility->accessLevel($user, (int) $row->workflow_instance_id) !== null;
        abort_unless($participant || $this->mayRead($user, [(int) $row->employee_id], (int) $row->requested_by), 403);
        app(AuditService::class)->event('attendance.permission.attachment_downloaded', ['subject_type' => 'attendance_permission_requests', 'subject_id' => $row->id,
            'sensitivity' => 'restricted', 'ip' => $request->ip(), 'metadata' => ['employee_id' => (int) $row->employee_id]]);

        return Storage::disk('local')->download($row->attachment_path, $row->attachment_name ?: 'attachment', ['Cache-Control' => 'no-store, private']);
    }

    public function proposeSwap(Request $request, ShiftSwapService $swaps): RedirectResponse
    {
        abort_unless($swaps->available(), 409, 'خدمة تبديل المناوبة غير مفعلة بعد.');
        $employee = $this->own($request->user());
        $data = $request->validate([
            'counterpart_employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'dates' => ['required', 'array', 'min:1', 'max:'.app(AttendanceSettings::class)->get('swap_max_days')], 'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $swaps->propose($request->user(), $employee, Employee::query()->findOrFail($data['counterpart_employee_id']), $data['dates'], $data['reason']);

        return back()->with('success', 'أُرسل طلب التبديل إلى الزميل؛ لا يتغير الجدول قبل موافقته واعتماد الطلب.');
    }

    public function respondSwap(Request $request, int $swap, ShiftSwapService $swaps): RedirectResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $row = DB::table('attendance_shift_swaps')->where('id', $swap)->first();
        abort_unless($row, 404);
        $swaps->respond($request->user(), $row, (bool) $data['accept'], $data['note'] ?? null);

        return back()->with('success', $data['accept'] ? 'وافقت على التبديل؛ رُفع الطلب للاعتماد.' : 'سُجل اعتذارك وأُبلغ مقدم الطلب.');
    }

    public function cancelSwap(Request $request, int $swap, ShiftSwapService $swaps): RedirectResponse
    {
        $row = DB::table('attendance_shift_swaps')->where('id', $swap)->first();
        abort_unless($row, 404);
        $swaps->cancel($request->user(), $row);

        return back()->with('success', 'سُحب طلب التبديل.');
    }

    public function acknowledgeAction(Request $request, int $action, HrActionService $actions): RedirectResponse
    {
        $actions->acknowledge($request->user(), $this->action($action));

        return back()->with('success', 'سُجل اطلاعك على الإجراء.');
    }

    public function respondAction(Request $request, int $action, HrActionService $actions): RedirectResponse
    {
        $data = $request->validate(['response' => ['required', 'string', 'min:5', 'max:3000']]);
        $actions->respond($request->user(), $this->action($action), $data['response']);

        return back()->with('success', 'سُجل ردك على الإجراء.');
    }

    private function own(User $user): Employee
    {
        $employee = $this->access->ownEmployee($user);
        if (! $employee || $employee->employment_status !== 'active') {
            throw ValidationException::withMessages(['employee' => 'حسابك غير مرتبط بملف موظف نشط.']);
        }

        return $employee;
    }

    private function action(int $id): object
    {
        abort_unless(AttendanceLedger::hasTable('employee_hr_actions'), 404);

        return DB::table('employee_hr_actions')->where('id', $id)->first() ?? abort(404);
    }

    /**
     * The requester, the employees concerned, HR and the executive director, or the head of one of the employees' departments.
     *
     * @param  list<int>  $employeeIds
     */
    private function mayRead(User $user, array $employeeIds, int $requestedBy): bool
    {
        if ($requestedBy === (int) $user->id || $this->access->canViewLedger($user)) {
            return true;
        }
        $access = app(EmployeeAccess::class);
        foreach (Employee::query()->whereIn('id', $employeeIds)->get() as $employee) {
            if ($access->isSelf($user, $employee) || $access->leadsDepartmentOf($user, $employee)) {
                return true;
            }
        }

        return false;
    }
}
