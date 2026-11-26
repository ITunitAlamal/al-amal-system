<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceAudit;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendancePermissionService;
use App\Services\Attendance\AttendanceSettings;
use App\Services\Attendance\ShiftSwapService;
use App\Services\Workflow\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * «الطلبات والموافقات» inside /admin/attendance: one inbox for permission and swap requests with inline workflow
 * actions for whoever holds the current step, HR registration and revocation of permissions, swap reversal, and the
 * rules of each permission type and exemption reason (kept in the system constants' metadata, audited).
 */
final class AttendanceRequestsController extends Controller
{
    public function __construct(private AttendanceAccess $access) {}

    public function inbox(Request $request, WorkflowEngine $engine, AttendancePermissionService $permissions, ShiftSwapService $swaps): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->canViewLedger($user), 403);
        $tz = config('app.timezone');
        $local = fn (mixed $value, string $format = 'H:i'): ?string => $value ? CarbonImmutable::parse($value)->setTimezone($tz)->format($format) : null;
        $workflow = function (?int $instanceId) use ($engine, $user): ?array {
            $instance = $instanceId ? DB::table('workflow_instances as i')->leftJoin('workflow_steps as s', 's.id', '=', 'i.current_step_id')
                ->where('i.id', $instanceId)->first(['i.*', 's.name as step_name']) : null;
            if (! $instance) {
                return null;
            }
            $holders = DB::table('workflow_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')->where('p.workflow_instance_id', $instance->id)
                ->where('p.participation_type', 'assignee')->where('p.is_active', true)->pluck('u.name')->all();

            return ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision, 'status' => $instance->status,
                'step' => $instance->status === 'in_progress' ? $instance->step_name : null, 'holders' => $holders, 'actions' => $engine->availableActions($instance, $user)];
        };

        $permissionRows = $permissions->available() ? DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->where(fn ($query) => $query->where('r.status', 'submitted')->orWhere('r.updated_at', '>=', now()->subDays(14)))
            ->orderByRaw("CASE WHEN r.status = 'submitted' THEN 0 ELSE 1 END")->orderByDesc('r.id')->limit(150)
            ->get(['r.*', 't.name_ar as type_name', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar', 'e.employee_number'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'employee_id' => (int) $row->employee_id, 'employee' => AttendanceLedger::fullName($row),
                'number' => $row->employee_number, 'type' => $row->type_name, 'work_date' => (string) $row->work_date, 'from' => $local($row->starts_at), 'to' => $local($row->ends_at),
                'minutes' => (int) $row->minutes, 'reason' => $row->reason, 'status' => $row->status, 'status_label' => AttendancePermissionService::STATUS[$row->status] ?? $row->status,
                'source' => $row->source, 'decision_note' => $row->decision_note, 'has_attachment' => $row->attachment_path !== null,
                'url' => AttendancePermissionService::url((int) $row->id), 'workflow' => $workflow($row->workflow_instance_id ? (int) $row->workflow_instance_id : null)])
            ->all() : [];

        $swapRows = $swaps->available() ? DB::table('attendance_shift_swaps as s')->join('employees as a', 'a.id', '=', 's.requester_employee_id')
            ->join('employees as b', 'b.id', '=', 's.counterpart_employee_id')
            ->where(fn ($query) => $query->whereIn('s.status', ShiftSwapService::OPEN)->orWhere('s.updated_at', '>=', now()->subDays(30)))
            ->orderByRaw("CASE WHEN s.status IN ('awaiting_counterpart','pending_approval') THEN 0 ELSE 1 END")->orderByDesc('s.id')->limit(100)
            ->get(['s.*', 'a.first_name_ar', 'a.father_name_ar', 'a.family_name_ar', 'b.first_name_ar as b_first', 'b.father_name_ar as b_father', 'b.family_name_ar as b_family'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'requester' => AttendanceLedger::fullName($row), 'counterpart' => trim($row->b_first.' '.$row->b_father.' '.$row->b_family),
                'status' => $row->status, 'status_label' => ShiftSwapService::STATUS[$row->status] ?? $row->status, 'reason' => $row->reason,
                'days' => array_map(fn (array $day): array => ['date' => $day['date'], 'requester' => $day['requester_before']['label'], 'counterpart' => $day['counterpart_before']['label']],
                    json_decode((string) $row->days, true) ?: []),
                'decision_note' => $row->decision_note, 'reversal_reason' => $row->reversal_reason, 'url' => ShiftSwapService::url((int) $row->id),
                'workflow' => $workflow($row->workflow_instance_id ? (int) $row->workflow_instance_id : null)])
            ->all() : [];

        return response()->json([
            'permissions' => $permissionRows,
            'swaps' => $swapRows,
            'counts' => ['permissions' => collect($permissionRows)->where('status', 'submitted')->count(), 'swaps' => collect($swapRows)->whereIn('status', ShiftSwapService::OPEN)->count()],
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function registerPermission(Request $request, AttendancePermissionService $permissions): RedirectResponse
    {
        abort_unless($this->access->canDecideHr($request->user()), 403, 'تسجيل الإذن نيابة عن الموظف لرئيس قسم الموارد البشرية أو المدير التنفيذي.');
        abort_unless($permissions->available(), 409, 'يلزم تنفيذ ترحيل الحضور المعتمد أولًا.');
        $data = $request->validate($this->permissionRules() + [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'justification' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $permissions->registerByHr($request->user(), Employee::query()->findOrFail($data['employee_id']), $data, $data['justification'], $request->file('attachment'));

        return back()->with('success', 'سُجل الإذن معتمدًا وأُعيد احتساب اليوم وأُبلغ الموظف.');
    }

    public function revokePermission(Request $request, int $permission, AttendancePermissionService $permissions): RedirectResponse
    {
        abort_unless($this->access->canDecideHr($request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $row = DB::table('attendance_permission_requests')->where('id', $permission)->first();
        abort_unless($row, 404);
        $permissions->revoke($request->user(), $row, $data['reason']);

        return back()->with('success', 'أُلغي الإذن وأُعيد احتساب اليوم.');
    }

    public function reverseSwap(Request $request, int $swap, ShiftSwapService $swaps): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $row = DB::table('attendance_shift_swaps')->where('id', $swap)->first();
        abort_unless($row, 404);
        $swaps->reverse($request->user(), $row, $data['reason']);

        return back()->with('success', 'أُلغي التبديل وعاد الجدولان كما كانا، وأُعيد احتساب الأيام المتأثرة.');
    }

    /**
     * Rules of a permission type or an exemption reason (labels and activation stay in «ثوابت النظام»).
     */
    public function rules(Request $request, int $value): RedirectResponse
    {
        abort_unless($this->access->isHrOperator($request->user()), 403);
        $row = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('v.id', $value)
            ->whereIn('t.code', ['attendance_permission_types', 'attendance_exemption_reasons'])->first(['v.id', 'v.metadata', 't.code']);
        abort_unless($row, 404);
        $permission = $row->code === 'attendance_permission_types';
        $data = $request->validate($permission ? [
            'effect' => ['required', Rule::in(array_keys(AttendancePermissionService::EFFECTS))],
            'max_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'lead_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'retro_days' => ['nullable', 'integer', 'min:0', 'max:366'], 'attachment' => ['required', Rule::in(array_keys(AttendancePermissionService::ATTACHMENT_RULES))],
            'monthly_cap_count' => ['nullable', 'integer', 'min:1', 'max:100'], 'monthly_cap_minutes' => ['nullable', 'integer', 'min:1', 'max:20000'],
            'excuses' => ['required', 'boolean'],
        ] : ['effect' => ['required', Rule::in(['no_duty', 'no_punch'])]]);
        $before = json_decode((string) $row->metadata, true) ?: [];
        $after = array_replace($before, $data, $permission ? ['pay_effect' => 'none'] : []);
        DB::transaction(function () use ($request, $row, $before, $after): void {
            DB::table('master_data_values')->where('id', $row->id)->update(['metadata' => json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_by' => $request->user()->id, 'updated_at' => now()]);
            AttendanceAudit::record($request->user()->id, $row->code === 'attendance_permission_types' ? 'permission_type' : 'exemption_reason', (int) $row->id, 'rules_updated',
                ['before' => $before, 'after' => $after]);
        });

        return back()->with('success', 'حُفظت القواعد. تسري على الطلبات الجديدة فقط.');
    }

    /**
     * Shift-swap limits: the HR head or the executive director, audited; they apply to new requests and to approvals.
     */
    public function settings(Request $request, AttendanceSettings $settings): RedirectResponse
    {
        abort_unless($this->access->canDecideHr($request->user()), 403, 'ضبط قواعد تبديل المناوبة لرئيس قسم الموارد البشرية أو المدير التنفيذي.');
        abort_unless(AttendanceLedger::hasTable('attendance_module_settings'), 409, 'يلزم تنفيذ ترحيل الحضور المعتمد أولًا.');
        $data = $request->validate([
            'swap_same_department' => ['required', 'boolean'],
            'swap_min_rest_hours' => ['required', 'integer', 'min:0', 'max:24'],
            'swap_max_hours_per_day' => ['required', 'integer', 'min:4', 'max:24'],
            'swap_max_days' => ['required', 'integer', 'min:1', 'max:31'],
            'policy_reference' => ['nullable', 'string', 'max:255'],
        ], [], ['swap_same_department' => 'التبديل داخل القسم فقط', 'swap_min_rest_hours' => 'أدنى راحة بين ورديتين', 'swap_max_hours_per_day' => 'أقصى ساعات للوردية',
            'swap_max_days' => 'أقصى عدد أيام في الطلب', 'policy_reference' => 'مرجع السياسة المعتمدة']);
        $settings->update($request->user(), ['swap_same_department' => (bool) $data['swap_same_department'], 'swap_min_rest_hours' => (int) $data['swap_min_rest_hours'],
            'swap_max_hours_per_day' => (int) $data['swap_max_hours_per_day'], 'swap_max_days' => (int) $data['swap_max_days'],
            'policy_reference' => filled($data['policy_reference'] ?? null) ? trim($data['policy_reference']) : null]);

        return back()->with('success', 'حُفظت قواعد تبديل المناوبة. تسري على الطلبات الجديدة وعلى الاعتماد.');
    }

    /**
     * Shared with the employee's own request form.
     *
     * @return array<string, list<mixed>>
     */
    public static function permissionRules(): array
    {
        return [
            'type_value_id' => ['required', 'integer'], 'work_date' => ['required', 'date_format:Y-m-d'],
            'from' => ['required', 'date_format:H:i'], 'to' => ['required', 'date_format:H:i', 'different:from'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('attendance.permission_attachment_kb', 5120)],
        ];
    }
}
