<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttendanceAbsenceResolver
{
    public function resolve(int $employeeId, string $date): ?array
    {
        $employee = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->first(['department_id']);
        if (! $employee) {
            return null;
        }
        $holiday = DB::table('leave_holidays')->where('holiday_date', $date)->first(['id', 'name_ar']);
        if ($holiday) {
            return ['status' => 'official_holiday', 'source' => 'leave_holidays', 'id' => $holiday->id, 'label' => $holiday->name_ar];
        }
        if (Schema::hasTable('attendance_emergency_holidays')) {
            $emergency = DB::table('attendance_emergency_holidays')->where('status', 'approved')->where('holiday_date', $date)
                ->where(function ($q) use ($employeeId, $employee) {
                    $q->where('employee_id', $employeeId)
                        ->orWhere(function ($q) use ($employee) {
                            $q->whereNull('employee_id')->where('department_id', $employee->department_id);
                        })
                        ->orWhere(function ($q) {
                            $q->whereNull('employee_id')->whereNull('department_id');
                        });
                })
                ->orderByRaw('CASE WHEN employee_id IS NOT NULL THEN 0 WHEN department_id IS NOT NULL THEN 1 ELSE 2 END')->orderByDesc('id')->first(['id', 'name']);
            if ($emergency) {
                return ['status' => 'emergency_holiday', 'source' => 'attendance_emergency_holidays', 'id' => $emergency->id, 'label' => $emergency->name];
            }
        }
        $leave = DB::table('employee_leave_requests')->where('employee_id', $employeeId)->where('status', 'approved')
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->orderByDesc('id')->first(['id', 'leave_type_value_id']);
        if ($leave) {
            return ['status' => 'approved_leave', 'source' => 'employee_leave_requests', 'id' => $leave->id, 'label' => 'إجازة معتمدة'];
        }

        return null;
    }
}
