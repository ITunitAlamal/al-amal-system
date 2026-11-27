<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Organization\EffectiveAssignments;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who approves a leave request under the "department_or_executive" mode (owner decision 2026-11-27):
 * the employee's department head; the executive director for department heads and employees without a
 * department; and the executive director approves his own leave. Nobody else approves their own leave.
 * Every decision notifies HR and debits the balance of the leave type (LeaveDecisionService).
 */
final class LeaveApproverResolver
{
    public function approverFor(object $leave): ?int
    {
        $approver = $this->candidate($leave);
        $subject = (int) DB::table('employees')->where('id', $leave->employee_id)->value('user_id');
        if ($approver !== null && $this->isOwnExecutiveLeave($leave, $subject)) {
            return $approver;
        }

        // Otherwise never the requester or the employee on leave.
        return $approver !== null && $approver !== (int) $leave->requested_by && $approver !== $subject ? $approver : null;
    }

    /** The executive director's own leave, requested by himself: he approves it (owner decision 2026-11-27). */
    public function isOwnExecutiveLeave(object $leave, ?int $subjectUserId = null): bool
    {
        $subject = $subjectUserId ?? (int) DB::table('employees')->where('id', $leave->employee_id)->value('user_id');

        return $subject === (int) $leave->requested_by && $this->isExecutive($subject);
    }

    private function candidate(object $leave): ?int
    {
        if ($this->isExecutive((int) $leave->requested_by)) {
            return $this->executiveId();
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
            // The head counts only with an effective department_head appointment there; otherwise no approver (never the ED).
            $head = DB::table('departments as d')
                ->join('users as u', 'u.id', '=', 'd.head_user_id')
                ->join('department_user_roles as a', fn ($join) => $join->on('a.user_id', '=', 'u.id')->on('a.department_id', '=', 'd.id'))
                ->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('d.id', $employee->department_id)->where('u.is_active', true)->where('r.code', 'department_head')
                ->whereRaw(EffectiveAssignments::sql('a'))->value('u.id');

            if ($head === null && $headId && $this->isExecutive((int) $headId)) {
                return $this->executiveId(); // the executive office: its head is the executive director
            }

            return $head === null ? null : (int) $head;
        }

        return $this->executiveId();
    }

    public function isExecutive(int $userId): bool
    {
        return DB::table('department_user_roles as a')
            ->join('roles as role', 'role.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.user_id', $userId)->where('role.code', 'executive_director')
            ->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))->exists();
    }

    public function executiveId(): ?int
    {
        $ids = DB::table('department_user_roles as a')
            ->join('roles as role', 'role.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('role.code', 'executive_director')->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))
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
                    ->orWhereColumn('d.head_user_id', 'l.requested_by')
                : $query->where('d.head_user_id', $user->id))
            ->orderBy('l.submitted_at')
            ->get(['l.id', 'l.employee_id', 'l.requested_by', 'l.start_date', 'l.end_date', 'l.requested_days', 'l.submitted_at',
                't.name_ar as leave_type_name', 'requester.name as requester_name'])
            ->filter(fn (object $leave): bool => $this->approverFor($leave) === (int) $user->id)
            ->values();
    }
}
