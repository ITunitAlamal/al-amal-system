<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who approves a leave request under the "department_or_executive" mode:
 * the employee's department head, or the executive director for department
 * heads and employees without a department.
 */
final class LeaveApproverResolver
{
    public function approverFor(object $leave): ?int
    {
        if ($this->isExecutive((int) $leave->requested_by)) {
            return (int) $leave->requested_by;
        }
        $employee = DB::table('employees')->where('id', $leave->employee_id)->first(['department_id']);
        if (! $employee) {
            return null;
        }
        $headId = $employee->department_id
            ? DB::table('departments')->where('id', $employee->department_id)->value('head_user_id')
            : null;
        $isHead = $headId && (int) $headId === (int) $leave->requested_by;
        if ($employee->department_id && ! $isHead) {
            if (! $headId) {
                return null;
            }
            $active = DB::table('users')->where('id', $headId)->where('is_active', true)->exists();

            return $active ? (int) $headId : null;
        }

        return $this->executiveId();
    }

    public function isExecutive(int $userId): bool
    {
        return DB::table('department_user_roles as a')
            ->join('roles as role', 'role.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.user_id', $userId)->where('role.code', 'executive_director')
            ->where('u.is_active', true)->exists();
    }

    public function executiveId(): ?int
    {
        $ids = DB::table('department_user_roles as a')
            ->join('roles as role', 'role.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('role.code', 'executive_director')->where('u.is_active', true)
            ->distinct()->pluck('a.user_id');
        if ($ids->count() !== 1) {
            return null;
        }

        return (int) $ids->first();
    }

    /**
     * Submitted legacy (single-step) leave requests whose approver is this user. Requests on the workflow
     * engine are listed with the user's workflow tasks instead.
     *
     * @return Collection<int, object>
     */
    public function awaiting(User $user): Collection
    {
        $isExecutive = $this->isExecutive((int) $user->id);

        return DB::table('employee_leave_requests as l')
            ->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->join('users as requester', 'requester.id', '=', 'l.requested_by')
            ->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('l.status', 'submitted')
            ->whereNull('l.workflow_instance_id')
            ->where(fn ($query) => $isExecutive
                ? $query->where('d.head_user_id', $user->id)->orWhereNull('e.department_id')
                    ->orWhereColumn('d.head_user_id', 'l.requested_by')->orWhere('l.requested_by', $user->id)
                : $query->where('d.head_user_id', $user->id))
            ->orderBy('l.submitted_at')
            ->get(['l.id', 'l.employee_id', 'l.requested_by', 'l.start_date', 'l.end_date', 'l.requested_days', 'l.submitted_at',
                't.name_ar as leave_type_name', 'requester.name as requester_name'])
            ->filter(fn (object $leave): bool => $this->approverFor($leave) === (int) $user->id)
            ->values();
    }
}
