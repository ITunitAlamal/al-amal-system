<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class EmployeeLeaveBalanceService
{
    /** Read-only year-scoped balance; opening records remain in their original table. */
    public function summary(int $employeeId, int $typeId, int $year): array
    {
        $opening = DB::table('employee_leave_opening_balances')
            ->where('employee_id', $employeeId)->where('leave_type_value_id', $typeId)
            ->where('balance_year', $year)->first();
        $account = DB::table('employee_leave_accounts')
            ->where('employee_id', $employeeId)->where('leave_type_value_id', $typeId)
            ->where('balance_year', $year)->first();
        $movement = $account ? (float) DB::table('employee_leave_transactions')
            ->where('account_id', $account->id)->sum('amount_days') : 0.0;
        $held = (float) DB::table('employee_leave_requests')
            ->where('employee_id', $employeeId)->where('leave_type_value_id', $typeId)
            ->whereYear('start_date', $year)->whereIn('status', LeaveSubmissionRules::HELD_STATUSES)
            ->sum('requested_days');
        $initial = $opening ? (float) $opening->opening_balance_days : 0.0;

        return [
            'employee_id' => $employeeId, 'leave_type_value_id' => $typeId, 'balance_year' => $year,
            'opening_recorded' => (bool) $opening, 'opening' => round($initial, 2),
            'movements' => round($movement, 2), 'remaining' => round($initial + $movement, 2),
            'reserved' => round($held, 2), 'available' => round($initial + $movement - $held, 2),
        ];
    }
}
