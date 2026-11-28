<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Services\Organization\Authority;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave requests on the workflow engine (leave approval mode "workflow", owner decisions of 2026-10-22).
 *
 * The leave settings name three workflows: employees, department heads, and the executive director. A department
 * head is whoever validly heads an active department (departments.head_user_id with the «رئيس قسم» role there), the HR head
 * included, so one person heading several departments is covered. The executive director's own leave is
 * approved by a decision of the board of directors, recorded by the board secretary (owner decision 2026-10-22);
 * nobody approves their own leave.
 *
 * The engine moves the request between steps; this class keeps the leave record in step in the same
 * transaction: its status, the decision log, the balance debit when the leave is registered (the end step) and
 * the notices.
 *
 * Who may act on a step is decided by the engine alone (the step's active assignee). So when the HR head hands
 * «تسجيل الإجازة وخصم الرصيد» to an HR employee (StepAssignments), that employee opens this one request, registers
 * it, and is recorded as the actor in the decision log and on the balance debit; no other leave access is given.
 */
final class LeaveWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'employee_leave_request';

    public function __construct(
        private readonly LeaveDecisionService $decisions,
        private readonly LeaveSubmissionRules $rules,
        private readonly AssigneeResolver $assignees,
    ) {}

    /** Validly heads at least one department: the seat with the «رئيس قسم» role there (Organization\Authority). */
    public function isHead(User $user): bool
    {
        return app(Authority::class)->isHead($user);
    }

    /** The executive director in office (Organization\Authority). */
    public function isExecutive(User $user): bool
    {
        return app(Authority::class)->isExecutive($user);
    }

    /**
     * The workflow a new leave request of this person follows.
     */
    public function definitionFor(User $requester): string
    {
        $settings = $this->rules->settings();
        if ($this->isExecutive($requester)) {
            return (string) ($settings?->executive_workflow_code
                ?: throw ValidationException::withMessages(['status' => 'لم يُحدد مسار إجازة المدير التنفيذي (قرار مجلس الإدارة) في إعدادات الإجازات.']));
        }
        $code = $this->isHead($requester) ? $settings?->head_workflow_code : $settings?->employee_workflow_code;
        if (! $code) {
            throw ValidationException::withMessages(['status' => 'لم يُحدد مسار اعتماد الإجازات في إعدادات الإجازات.']);
        }

        return (string) $code;
    }

    /**
     * Sends a validated draft into its workflow. Call inside the submitting transaction, with the leave row locked.
     */
    public function start(User $requester, object $leave, Employee $employee): object
    {
        $departmentId = (int) ($employee->department_id ?: $requester->department_id);
        if ($departmentId <= 0) {
            throw ValidationException::withMessages(['status' => 'يجب ربط ملف الموظف بقسم قبل تقديم طلب الإجازة.']);
        }

        try {
            $instance = app(WorkflowEngine::class)->startForSubject($requester, $this->definitionFor($requester), self::SUBJECT_TYPE, (int) $leave->id, $departmentId);
        } catch (ValidationException $exception) {
            if ($this->isExecutive($requester) && isset($exception->errors()['transition'])) {
                throw ValidationException::withMessages(['status' => 'إجازة المدير التنفيذي تُعتمد بقرار مجلس الإدارة، ولا يوجد حساب يحمل صفة «أمين سر مجلس الإدارة» لتسجيل القرار. يحددها مدير النظام من «حسابات الموظفين والأدوار».']);
            }
            throw $exception;
        }
        DB::table('employee_leave_requests')->where('id', $leave->id)->update(['workflow_instance_id' => $instance->id, 'updated_at' => now()]);

        return $instance;
    }

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $leave = $this->lockedLeave($instance);
        if (in_array($leave->status, ['approved', 'rejected', 'cancelled'], true)) {
            throw ValidationException::withMessages(['transition' => 'طلب الإجازة مغلق ولا يقبل إجراءات جديدة.']);
        }
        // Resubmission after a return: the dates may have changed, so the submission rules run again.
        if ($from->assignment_type === 'requester' && $leave->status === 'returned') {
            $days = $this->rules->validate($leave, Employee::query()->findOrFail($leave->employee_id));
            DB::table('employee_leave_requests')->where('id', $leave->id)->update(['requested_days' => $days, 'submitted_at' => now(), 'updated_at' => now()]);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $leave = $this->lockedLeave($instance);

        if ($target->step_type === 'end') {
            // The requester hears of the closing from the engine; HR and the department head from the decision service.
            ($this->assignees->config($target)['outcome'] ?? 'completed') === 'rejected'
                ? $this->decisions->reject($leave, $actorId, $comment, false)
                : $this->decisions->approve($leave, $actorId, $comment, false);

            return;
        }

        // After the final decision the request waits for the head to inform the employee and for HR to register it
        // (SRS LV02, §6.7); such steps declare "subject_status": "pending_registration". The balance is debited
        // only when HR registers it (the end step).
        $status = match (true) {
            $target->assignment_type === 'requester' => 'returned',
            $from->step_type === 'start' => 'submitted',
            ($this->assignees->config($target)['subject_status'] ?? null) === 'pending_registration' => 'pending_registration',
            default => 'manager_approved',
        };
        DB::table('employee_leave_requests')->where('id', $leave->id)->update(['status' => $status, 'updated_at' => now()]);
        $this->decisions->log((int) $leave->id, $actorId, (string) $leave->status, $status, $comment);
        if ($leave->status === 'draft') {
            $this->decisions->notifyHr($leave, 'submitted');
        }
    }

    /**
     * Transition conditions may test the counted days and the leave type code, e.g. {"requested_days": {"gt": 14}}.
     *
     * @return array<string, mixed>
     */
    public function conditionContext(object $instance): array
    {
        $leave = DB::table('employee_leave_requests as l')->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->where('l.id', $instance->subject_id)->first(['l.requested_days', 't.code as leave_type']);

        return [
            'requested_days' => $leave?->requested_days !== null ? (float) $leave->requested_days : null,
            'leave_type' => $leave?->leave_type,
        ];
    }

    public function url(int $subjectId): string
    {
        return route('leaves.show', ['leave' => $subjectId], false);
    }

    public function titles(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        return DB::table('employee_leave_requests as l')->leftJoin('master_data_values as t', 't.id', '=', 'l.leave_type_value_id')
            ->whereIn('l.id', $subjectIds)->get(['l.id', 'l.start_date', 'l.end_date', 't.name_ar'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => ($row->name_ar ?? 'إجازة').' · '.$row->start_date.' ← '.$row->end_date])
            ->all();
    }

    /**
     * The requester withdraws a leave still in process: the workflow closes as cancelled; nothing is debited.
     */
    public function cancel(User $requester, object $leave): void
    {
        $status = DB::table('workflow_instances')->where('id', $leave->workflow_instance_id)->value('status');
        if ($status === 'in_progress') {
            app(WorkflowEngine::class)->cancel((int) $leave->workflow_instance_id, $requester, 'ألغى مقدم الطلب طلب الإجازة');
        }
    }

    private function lockedLeave(object $instance): object
    {
        return DB::table('employee_leave_requests')->where('id', $instance->subject_id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['transition' => 'طلب الإجازة المرتبط بالمعاملة غير موجود.']);
    }
}
