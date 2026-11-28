<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Organization\Authority;
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
        $departmentId = $employee->department_id ? (int) $employee->department_id : null;
        // The head counts only with an effective department_head appointment there (Organization\Authority).
        $head = app(Authority::class)->headId($departmentId);
        if ($departmentId !== null && $head !== (int) $leave->requested_by) {
            $seat = DB::table('departments')->where('id', $departmentId)->value('head_user_id');
            if ($head === null && $seat && $this->isExecutive((int) $seat)) {
                return $this->executiveId(); // the executive office: its head is the executive director
            }

            // Otherwise no approver when the department has no valid head (never the ED).
            return $head;
        }

        return $this->executiveId();
    }

    /** The executive director in office (Organization\Authority). */
    public function isExecutive(int $userId): bool
    {
        return app(Authority::class)->isExecutive($userId);
    }

    /** The one executive director in office, or null (Organization\Authority). */
    public function executiveId(): ?int
    {
        return app(Authority::class)->executiveId();
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
