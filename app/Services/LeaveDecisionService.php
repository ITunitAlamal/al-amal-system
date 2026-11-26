<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Attendance\AttendanceDailyService;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The effects of a final decision on a leave request, shared by the legacy approval mode and the workflow
 * engine: the balance debit (once, by its ledger key), the status, the decision log, and the notices to HR
 * and to the employee's department head. Callers run it inside their transaction with the leave row locked.
 */
final class LeaveDecisionService
{
    public function __construct(
        private readonly EmployeeLeaveBalanceService $balance,
        private readonly NotificationCenter $notifications,
        private readonly AttendanceDailyService $attendance,
    ) {}

    /**
     * Final approval: the balance must still cover every pending request of the type, then one debit is written.
     */
    public function approve(object $leave, int $actorId, ?string $comment, bool $notifyRequester): void
    {
        if (substr((string) $leave->start_date, 0, 4) !== substr((string) $leave->end_date, 0, 4)) {
            throw ValidationException::withMessages(['end_date' => 'يجب تقديم طلب منفصل لكل سنة مالية.']);
        }
        if ($leave->requested_days === null || (float) $leave->requested_days <= 0) {
            throw ValidationException::withMessages(['status' => 'لم تُحتسب أيام الطلب؛ لا يمكن اعتماده.']);
        }
        $year = (int) substr((string) $leave->start_date, 0, 4);
        DB::table('employee_leave_accounts')->insertOrIgnore([
            'employee_id' => $leave->employee_id, 'leave_type_value_id' => $leave->leave_type_value_id,
            'balance_year' => $year, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $account = DB::table('employee_leave_accounts')->where('employee_id', $leave->employee_id)
            ->where('leave_type_value_id', $leave->leave_type_value_id)->where('balance_year', $year)->lockForUpdate()->first();
        $current = $this->balance->summary((int) $leave->employee_id, (int) $leave->leave_type_value_id, $year);
        if ($current['remaining'] < $current['reserved']) {
            throw ValidationException::withMessages(['status' => 'تغير الرصيد ولم يعد يغطي الطلبات المعلقة.']);
        }
        if (! DB::table('employee_leave_transactions')->where('external_key', 'leave_taken:'.$leave->id)->exists()) {
            DB::table('employee_leave_transactions')->insert([
                'account_id' => $account->id, 'leave_request_id' => $leave->id,
                'kind' => 'leave_taken', 'amount_days' => -(float) $leave->requested_days,
                'external_key' => 'leave_taken:'.$leave->id, 'reason' => 'اعتماد طلب الإجازة '.$leave->id,
                'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->close($leave, 'approved', $actorId, $comment, $notifyRequester);
    }

    public function reject(object $leave, int $actorId, ?string $comment, bool $notifyRequester): void
    {
        $this->close($leave, 'rejected', $actorId, $comment, $notifyRequester);
    }

    public function log(int $leaveId, int $actorId, string $from, string $to, ?string $comment = null): void
    {
        DB::table('employee_leave_approvals')->insert([
            'leave_request_id' => $leaveId, 'actor_id' => $actorId, 'from_status' => $from,
            'to_status' => $to, 'comment' => $comment, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Records the event for the HR department head(s) (follow-up list in «الإجازات»), once per event.
     */
    public function notifyHr(object $leave, string $event): void
    {
        foreach ($this->hrHeads() as $userId) {
            DB::table('leave_hr_notifications')->insertOrIgnore([
                'leave_request_id' => $leave->id, 'recipient_user_id' => $userId,
                'event' => $event, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function close(object $leave, string $status, int $actorId, ?string $comment, bool $notifyRequester): void
    {
        DB::table('employee_leave_requests')->where('id', $leave->id)->update(['status' => $status, 'decided_at' => now(), 'updated_at' => now()]);
        $this->log((int) $leave->id, $actorId, (string) $leave->status, $status, $comment);
        $this->notifyHr($leave, $status);

        $title = $status === 'approved' ? 'اعتُمد طلب إجازة' : 'رُفض طلب إجازة';
        $employeeName = (string) DB::table('employees')->where('id', $leave->employee_id)
            ->selectRaw("trim(coalesce(first_name_ar, '') || ' ' || coalesce(family_name_ar, '')) as name")->value('name');
        $period = 'من '.$leave->start_date.' إلى '.$leave->end_date;
        $url = route('leaves.show', ['leave' => $leave->id], false);

        if ($notifyRequester && (int) $leave->requested_by !== $actorId) {
            $this->notifications->notify((int) $leave->requested_by, 'leave_decided', [
                'title' => $status === 'approved' ? 'اعتُمد طلب إجازتك' : 'رُفض طلب إجازتك',
                'body' => $period, 'url' => $url, 'dedupe' => 'leave:'.$leave->id.':decided',
            ]);
        }
        // The employee's department head and HR are told of the decision; they do not approve it again.
        $recipients = collect($this->hrHeads())->push($this->departmentHead($leave))->filter()
            ->reject(fn (int $id): bool => $id === $actorId || $id === (int) $leave->requested_by)->unique()->values();
        $this->notifications->notifyMany($recipients, 'leave_decision_notice', [
            'title' => $title.': '.$employeeName, 'body' => $period, 'url' => $url, 'dedupe' => 'leave:'.$leave->id.':notice:'.$status,
        ]);

        $this->attendance->refreshExisting((int) $leave->employee_id, (string) $leave->start_date, (string) $leave->end_date);
    }

    /**
     * @return list<int>
     */
    private function hrHeads(): array
    {
        return DB::table('departments as d')->join('users as u', 'u.id', '=', 'd.head_user_id')
            ->join('department_user_roles as a', fn ($join) => $join->on('a.user_id', '=', 'u.id')->on('a.department_id', '=', 'd.id'))
            ->join('roles as role', 'role.id', '=', 'a.role_id')
            ->where('d.code', 'HR')->where('d.is_active', true)->whereNull('d.deleted_at')
            // The HR head is whoever heads the HR department with the head role there; their primary department may differ.
            ->where('role.code', 'department_head')->where('u.is_active', true)
            ->distinct()->pluck('u.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function departmentHead(object $leave): ?int
    {
        $headId = DB::table('employees as e')->join('departments as d', 'd.id', '=', 'e.department_id')
            ->join('users as u', 'u.id', '=', 'd.head_user_id')
            ->where('e.id', $leave->employee_id)->where('u.is_active', true)->value('d.head_user_id');

        return $headId === null ? null : (int) $headId;
    }
}
