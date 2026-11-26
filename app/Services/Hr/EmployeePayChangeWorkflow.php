<?php

namespace App\Services\Hr;

use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/**
 * Pay and bank changes on the central engine: HR request → executive director's decision. The change takes effect
 * only on approval (EmployeePayChanges::apply, in the same transaction as the move). The requester and the
 * employee are told the decision without amounts.
 */
final class EmployeePayChangeWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'employee_pay_change';

    private const STATUS_BY_STEP = ['hr' => 'returned', 'ed_decision' => 'in_review', 'approved' => 'approved', 'rejected' => 'rejected'];

    public function __construct(
        private readonly EmployeePayChanges $changes,
        private readonly EmployeeAccess $access,
        private readonly NotificationCenter $notifications,
    ) {}

    public function beforeMove(object $instance, object $from, object $target, int $actorId): void
    {
        $change = DB::table('employee_pay_changes')->where('id', $instance->subject_id)->lockForUpdate()->first();
        abort_unless($change, 404);
        if ($from->code === 'hr') {
            $actor = User::findOrFail($actorId);
            abort_unless((int) $change->requested_by === $actorId && $this->access->canRequestPayChange($actor), 403, 'الطلب يُرفع أو يُسحب من مقدمه في الموارد البشرية.');
        }
        if ($target->code === 'approved') {
            $this->changes->apply($change, $actorId);
        }
    }

    public function afterMove(object $instance, object $from, object $target, object $transition, int $actorId, ?string $comment): void
    {
        $change = DB::table('employee_pay_changes')->where('id', $instance->subject_id)->first();
        $status = self::STATUS_BY_STEP[$target->code] ?? $change->status;
        $decided = in_array($target->code, ['approved', 'rejected'], true);
        DB::table('employee_pay_changes')->where('id', $change->id)->update(['status' => $status, 'decided_by' => $decided ? $actorId : $change->decided_by,
            'decided_at' => $decided ? now() : $change->decided_at, 'updated_at' => now()]);
        $this->changes->log($actorId, 'employee_pay_change.'.$transition->action_code, (int) $change->id,
            ['employee_id' => $change->employee_id, 'status' => [$change->status, $status], 'workflow' => $instance->reference_no, 'comment' => $comment], null);

        if ($decided) {
            $employeeUser = DB::table('employees')->where('id', $change->employee_id)->value('user_id');
            $this->notifications->notifyMany(array_filter([(int) $change->requested_by, $employeeUser ? (int) $employeeUser : null]), 'employee_pay_change_decided', [
                'title' => $target->code === 'approved' ? 'اعتُمد تعديل بيانات الراتب أو الحساب البنكي' : 'رُفض طلب تعديل بيانات الراتب أو الحساب البنكي',
                'body' => $target->code === 'approved' ? 'يسري التعديل من '.$change->effective_from.'. التفاصيل في الملف الوظيفي لمن يملك الاطلاع.' : 'راجع سبب القرار في الطلب.',
                'url' => $this->url((int) $change->id),
                'dedupe' => 'pay-change:'.$change->id.':'.$target->code,
            ]);
        }
    }

    public function conditionContext(object $instance): array
    {
        return [];
    }

    public function url(int $subjectId): string
    {
        return '/admin/employee-pay-changes/'.$subjectId;
    }

    public function titles(array $subjectIds): array
    {
        return DB::table('employee_pay_changes as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->whereIn('c.id', $subjectIds)
            ->get(['c.id', 'e.employee_number', 'e.full_name_ar', 'e.first_name_ar', 'e.family_name_ar'])
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => 'تعديل راتب/حساب: '.EmployeeNames::display($row).' ('.$row->employee_number.')'])->all();
    }
}
