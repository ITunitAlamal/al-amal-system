<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checks that a leave request may be sent for approval now, for a first submission and for a resubmission
 * after it was returned: requests enabled, same year, not in the past, an approved entitlement, enough
 * available balance after the other pending requests, and no overlap. Returns the counted days.
 */
final class LeaveSubmissionRules
{
    /** Statuses that hold days or block an overlapping request. */
    public const OPEN_STATUSES = ['submitted', 'manager_approved', 'pending_registration', 'returned'];

    /** Statuses whose days are reserved from the balance until HR registers or the request closes (SRS LV03). */
    public const HELD_STATUSES = ['submitted', 'manager_approved', 'pending_registration'];

    public function __construct(
        private readonly LeaveDayCounter $counter,
        private readonly EmployeeLeaveBalanceService $balance,
        private readonly EmployeeLeaveEntitlementService $entitlement,
    ) {}

    public function settings(): ?object
    {
        return DB::table('leave_module_settings')->where('id', 1)->first();
    }

    /**
     * Call inside the submitting transaction, with the leave row locked.
     */
    public function validate(object $leave, Employee $employee): float
    {
        $settings = $this->settings();
        if (! $settings || ! $settings->requests_enabled || ! $settings->approval_mode) {
            throw ValidationException::withMessages(['status' => 'طلبات الإجازة غير مفعلة أو مسار الموافقات لم يعتمد.']);
        }
        if (substr((string) $leave->start_date, 0, 4) !== substr((string) $leave->end_date, 0, 4)) {
            throw ValidationException::withMessages(['end_date' => 'يجب تقديم طلب منفصل لكل سنة مالية.']);
        }
        if ($leave->start_date < now()->toDateString()) {
            throw ValidationException::withMessages(['start_date' => 'لا يُسمح بإرسال طلب بتاريخ بدء سابق.']);
        }
        $calc = $this->entitlement->isCompensatoryType((int) $leave->leave_type_value_id)
            ? ['status' => 'defined']
            : $this->entitlement->calculate($employee, (int) $leave->leave_type_value_id, (string) $leave->start_date);
        if ($calc['status'] !== 'defined') {
            throw ValidationException::withMessages(['leave_type_value_id' => 'لا يوجد استحقاق معتمد لهذه الإجازة عند تاريخ بدايتها.']);
        }

        $days = $this->counter->days((string) $leave->start_date, (string) $leave->end_date, $settings);
        $year = (int) substr((string) $leave->start_date, 0, 4);
        DB::table('employee_leave_accounts')->insertOrIgnore(['employee_id' => $employee->id, 'leave_type_value_id' => $leave->leave_type_value_id, 'balance_year' => $year, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_leave_accounts')->where('employee_id', $employee->id)->where('leave_type_value_id', $leave->leave_type_value_id)->where('balance_year', $year)->lockForUpdate()->first();
        Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

        $current = $this->balance->summary((int) $employee->id, (int) $leave->leave_type_value_id, $year);
        $ownHeld = in_array($leave->status, self::HELD_STATUSES, true) ? (float) ($leave->requested_days ?? 0) : 0.0;
        if ($days > $current['available'] + $ownHeld) {
            throw ValidationException::withMessages(['status' => 'الرصيد المتاح لا يغطي أيام الطلب بعد الحجوزات القائمة.']);
        }
        $overlap = DB::table('employee_leave_requests')->where('employee_id', $employee->id)->where('id', '<>', $leave->id)
            ->whereIn('status', [...self::OPEN_STATUSES, 'approved'])
            ->where('start_date', '<=', $leave->end_date)->where('end_date', '>=', $leave->start_date)->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['start_date' => 'توجد إجازة أخرى متداخلة.']);
        }

        return $days;
    }
}
