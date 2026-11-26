<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceDailyService;
use App\Services\Attendance\CompensatoryLeaveService;
use App\Services\Progress\ApprovalProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class AttendanceCompensatoryController extends Controller
{
    private function executive(Request $r): bool
    {
        return $r->user()?->is_active && ($r->user()->isSystemAdmin() || DB::table('department_user_roles as a')->join('roles as b', 'a.role_id', '=', 'b.id')->where('a.user_id', $r->user()->id)->where('b.code', 'executive_director')->exists());
    }

    private function hr(Request $r): void
    {
        abort_unless($r->user()?->is_active && ($r->user()->isSystemAdmin() || $r->user()->isHrDepartmentHead() || $this->executive($r)), 403);
    }

    private function approveGuard(Request $r): void
    {
        abort_unless($this->executive($r), 403);
    }

    private function audit(Request $r, string $entity, int $id, string $action): void
    {
        DB::table('attendance_audit_events')->insert(['entity_type' => $entity, 'entity_id' => $id, 'action' => $action, 'actor_id' => $r->user()->id, 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function index(Request $r)
    {
        $this->hr($r);
        $progress = app(ApprovalProgress::class);

        return Inertia::render('Admin/Attendance/Compensatory', [
            'emergency_holidays' => DB::table('attendance_emergency_holidays')->orderByDesc('holiday_date')->limit(100)->get()
                ->map(fn (object $row): object => (object) [...(array) $row, 'steps' => $progress->steps($row->status, [
                    'requestLabel' => 'طلب العطلة الطارئة', 'requestedBy' => $row->created_by ? (int) $row->created_by : null, 'requestedAt' => $row->created_at,
                    'decisionLabel' => 'اعتماد العطلة', 'decidedBy' => $row->approved_by ? (int) $row->approved_by : null, 'decidedAt' => $row->approved_at,
                    'decisionOwner' => 'المدير التنفيذي',
                ])]),
            // Approval and the credited compensatory day are separate steps: approved work is not a granted day.
            'compensatory_work' => DB::table('attendance_compensatory_work')->orderByDesc('work_date')->limit(100)->get()
                ->map(fn (object $row): object => (object) [...(array) $row, 'steps' => $progress->steps($row->status === 'credited' ? 'approved' : $row->status, [
                    'requestLabel' => 'تسجيل العمل في يوم عطلة', 'requestedBy' => $row->requested_by ? (int) $row->requested_by : null, 'requestedAt' => $row->created_at,
                    'decisionLabel' => 'اعتماد العمل', 'decidedBy' => $row->approved_by ? (int) $row->approved_by : null, 'decidedAt' => $row->approved_at,
                    'decisionOwner' => 'المدير التنفيذي',
                    'execution' => ['label' => 'منح يوم تعويضي', 'done' => $row->credited_at !== null || $row->leave_transaction_id !== null, 'at' => $row->credited_at, 'owner' => 'الموارد البشرية'],
                ])]),
            'official_holidays' => DB::table('leave_holidays')->orderByDesc('holiday_date')->limit(100)->get(),
            'employees' => DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active')->get(['id', 'employee_number', 'first_name_ar', 'family_name_ar']),
            'departments' => DB::table('departments')->whereNull('deleted_at')->get(['id', 'name']),
            'canApprove' => $this->executive($r),
        ]);
    }

    public function holiday(Request $r)
    {
        $this->hr($r);
        $v = $r->validate(['holiday_date' => 'required|date_format:Y-m-d', 'name' => 'required|string|max:180', 'reason' => 'required|string|min:5|max:2000', 'department_id' => 'nullable|integer|exists:departments,id', 'employee_id' => 'nullable|integer|exists:employees,id']);
        if (! empty($v['employee_id']) && ! empty($v['department_id'])) {
            throw ValidationException::withMessages(['employee_id' => 'اختر الموظف أو القسم فقط.']);
        }
        DB::transaction(function () use ($v, $r) {
            $id = DB::table('attendance_emergency_holidays')->insertGetId($v + ['status' => 'pending', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'emergency_holiday', $id, 'requested');
        });

        return back()->with('success', 'أُرسلت العطلة الطارئة للاعتماد.');
    }

    public function holidayDecision(Request $r, int $id)
    {
        $this->approveGuard($r);
        $v = $r->validate(['decision' => 'required|in:approved,rejected']);
        DB::transaction(function () use ($r, $id, $v) {
            $row = DB::table('attendance_emergency_holidays')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'سبق اتخاذ قرار.']);
            } DB::table('attendance_emergency_holidays')->where('id', $id)->update(['status' => $v['decision'], 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'emergency_holiday', $id, $v['decision']);
            app(AttendanceDailyService::class)->refreshExisting($row->employee_id ? (int) $row->employee_id : null, (string) $row->holiday_date, (string) $row->holiday_date);
        });

        return back()->with('success', 'تم تسجيل القرار.');
    }

    public function work(Request $r)
    {
        $this->hr($r);
        $v = $r->validate(['employee_id' => 'required|integer|exists:employees,id', 'work_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:5|max:2000']);
        if (! DB::table('employees')->where('id', $v['employee_id'])->whereNull('deleted_at')->where('employment_status', 'active')->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'الموظف غير نشط.']);
        }
        DB::transaction(function () use ($r, $v) {
            if (DB::table('attendance_compensatory_work')->where('employee_id', $v['employee_id'])->where('work_date', $v['work_date'])->exists()) {
                throw ValidationException::withMessages(['work_date' => 'يوجد طلب لنفس الموظف واليوم؛ لا يمكن تكراره.']);
            } $id = DB::table('attendance_compensatory_work')->insertGetId($v + ['status' => 'pending', 'requested_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'compensatory_work', $id, 'requested');
        });

        return back()->with('success', 'أُرسل طلب العمل بالعطلة للاعتماد.');
    }

    public function workDecision(Request $r, int $id, CompensatoryLeaveService $service)
    {
        $this->approveGuard($r);
        $v = $r->validate(['decision' => 'required|in:approved,rejected']);
        DB::transaction(function () use ($r, $id, $v, $service) {
            $row = DB::table('attendance_compensatory_work')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'سبق اتخاذ قرار.']);
            }if ($v['decision'] === 'approved' && ! $service->eligibleHoliday((int) $row->employee_id, (string) $row->work_date)) {
                throw ValidationException::withMessages(['work_date' => 'ليس يوم عطلة للموظف حسب البيانات المعتمدة.']);
            }DB::table('attendance_compensatory_work')->where('id', $id)->update(['status' => $v['decision'], 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'compensatory_work', $id, $v['decision']);
        });

        return back()->with('success', 'تم تسجيل القرار.');
    }

    public function credit(Request $r, int $id, CompensatoryLeaveService $service)
    {
        $this->approveGuard($r);
        $service->credit($id, (int) $r->user()->id);
        $this->audit($r, 'compensatory_work', $id, 'credited_or_already_credited');

        return back()->with('success', 'تم التحقق من استحقاق الإجازة التعويضية وإثباته دون تكرار.');
    }
}
