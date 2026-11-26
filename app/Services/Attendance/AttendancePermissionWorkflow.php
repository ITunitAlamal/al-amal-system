<?php

namespace App\Services\Attendance;

use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «طلب إذن» on the central workflow (ATTENDANCE_PERMISSION). The engine moves the request between steps; on the
 * final step this class records the decision, recalculates the day and tells the employee, in the same transaction.
 * The approval path itself (head, HR, extra stages) is configured in «مسارات العمل», not in code.
 */
final class AttendancePermissionWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'attendance_permission_request';

    public function __construct(
        private readonly AttendancePermissionService $permissions,
        private readonly AssigneeResolver $assignees,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $request = $this->locked($instance);
        if ($request->status !== 'submitted') {
            throw ValidationException::withMessages(['transition' => 'طلب الإذن مغلق ولا يقبل إجراءات جديدة.']);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        if ($target->step_type !== 'end') {
            return;
        }
        $outcome = ($this->assignees->config($target)['outcome'] ?? 'completed') === 'rejected' ? 'rejected' : 'approved';
        $this->permissions->decided($this->locked($instance), $outcome, $actorId, $comment);
    }

    /**
     * Conditions may test the minutes and the type code, e.g. {"minutes": {"gt": 120}}.
     *
     * @return array<string, mixed>
     */
    public function conditionContext(object $instance): array
    {
        $request = DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')
            ->where('r.id', $instance->subject_id)->first(['r.minutes', 't.code']);

        return ['minutes' => $request ? (int) $request->minutes : null, 'permission_type' => $request?->code];
    }

    public function url(int $subjectId): string
    {
        return AttendancePermissionService::url($subjectId);
    }

    public function titles(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        return DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')->whereIn('r.id', $subjectIds)
            ->get(['r.id', 'r.work_date', 'r.minutes', 't.name_ar', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => $row->name_ar.' · '.AttendanceLedger::fullName($row).' · '.$row->work_date.' ('.$row->minutes.' د)'])->all();
    }

    private function locked(object $instance): object
    {
        return DB::table('attendance_permission_requests')->where('id', $instance->subject_id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['transition' => 'طلب الإذن المرتبط بالمعاملة غير موجود.']);
    }
}
