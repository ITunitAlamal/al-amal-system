<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Workflow\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/**
 * Pay and bank changes on the central engine: HR request → executive director's decision, or, when the employee is
 * the executive director himself, the Head of Accounts' decision (owner decision 1, 2026-11-27; the route is the
 * condition «employee_is_executive» of the published version, see migration 2026_11_30_000032). The change takes
 * effect only on approval (EmployeePayChanges::apply, in the same transaction as the move). The requester and the
 * employee are told the decision without amounts: the requester's notice opens the request, the employee's
 * opens their own file («ملفي الوظيفي»), since the request page is closed to them.
 */
final class EmployeePayChangeWorkflow implements WorkflowSubject
{
    public const SUBJECT_TYPE = 'employee_pay_change';

    /** The decision stage of the executive director's own change. */
    public const ACCOUNTS_STEP = 'accounts_decision';

    private const STATUS_BY_STEP = ['hr' => 'returned', 'ed_decision' => 'in_review', self::ACCOUNTS_STEP => 'in_review', 'approved' => 'approved', 'rejected' => 'rejected'];

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
            // The engine would hand a headless step to the head of FIN's parent department, whom the guard below
            // refuses: send the executive director's change only while the Head of Accounts can decide it.
            if ($target->code === self::ACCOUNTS_STEP) {
                $head = $this->access->accountsHeadId();
                abort_if($head === null || $head === $actorId, 403, $head === null
                    ? 'لا يوجد رئيس معيَّن لقسم الحسابات يقرر تعديل راتب المدير التنفيذي أو حسابه البنكي؛ يُعيَّن أولًا من الهيكل التنظيمي ثم يُرفع الطلب.'
                    : 'رئيس قسم الحسابات هو من يقرر هذا الطلب؛ لا يرفعه بنفسه. يرفعه موظف آخر في الموارد البشرية.');
            }
        } elseif ($this->access->employeeIsExecutive((int) $change->employee_id)) {
            // The executive director's own pay or bank: the Head of Accounts decides, and nobody else, at any stage.
            abort_unless($from->code === self::ACCOUNTS_STEP && $this->access->isAccountsHead(User::findOrFail($actorId)), 403,
                'تعديل راتب المدير التنفيذي أو حسابه البنكي يقرره رئيس قسم الحسابات؛ اسحب الطلب وأعد رفعه إن كان في مرحلة أخرى.');
        } else {
            abort_if($from->code === self::ACCOUNTS_STEP, 403, 'يقرر رئيس قسم الحسابات تعديل المدير التنفيذي وحده؛ بقية الموظفين يقرر تعديلاتهم المدير التنفيذي.');
        }
        if ($target->code === 'approved') {
            // Conflict of interest: nobody approves a change to their own pay or bank record.
            abort_if((int) DB::table('employees')->where('id', $change->employee_id)->value('user_id') === $actorId, 403, 'لا يعتمد الموظف تعديل راتبه أو حسابه البنكي.');
            $this->changes->apply($change, $actorId);
        }
    }

    /**
     * Users the record concerns; AssigneeResolver excludes them from the candidates like the requester.
     *
     * @return array<int>
     */
    public function interestedUserIds(object $instance): array
    {
        $userId = (int) DB::table('employee_pay_changes as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->where('c.id', $instance->subject_id)->value('e.user_id');

        return $userId > 0 ? [$userId] : [];
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
            $approved = $target->code === 'approved';
            $requesterId = (int) $change->requested_by;
            $dedupe = 'pay-change:'.$change->id.':'.$target->code;
            $this->notifications->notifyMany([$requesterId], 'employee_pay_change_decided', [
                'title' => $approved ? 'اعتُمد تعديل بيانات الراتب أو الحساب البنكي' : 'رُفض طلب تعديل بيانات الراتب أو الحساب البنكي',
                'body' => $approved ? 'يسري التعديل من '.$change->effective_from.'. التفاصيل في الملف الوظيفي لمن يملك الاطلاع.' : 'راجع سبب القرار في الطلب.',
                'url' => $this->url((int) $change->id),
                'dedupe' => $dedupe,
            ]);

            // The employee concerned is not a party to the administrative request and cannot open it: their notice
            // opens their own file, where the result shows under the rules of «ملفي الوظيفي». No figures in the text.
            $employeeUser = (int) DB::table('employees')->where('id', $change->employee_id)->value('user_id');
            if ($employeeUser > 0 && $employeeUser !== $requesterId) {
                $tab = $this->ownProfileTab($change, $employeeUser);
                $from = substr((string) $change->effective_from, 0, 10);
                $this->notifications->notifyMany([$employeeUser], 'employee_pay_change_decided', [
                    'title' => $approved ? 'اعتُمد تعديل على بيانات راتبك أو حسابك البنكي' : 'لم يُعتمد تعديل مقترح على بيانات راتبك أو حسابك البنكي',
                    'body' => match (true) {
                        ! $approved => 'لم يتغير شيء في بياناتك المسجلة. للاستفسار راجع الموارد البشرية.',
                        $tab === null => 'يسري التعديل من '.$from.'. للاستفسار راجع الموارد البشرية.',
                        // The pay tab shows the record in force today: a later one appears there on its date.
                        $tab === 'pay' && $from > now()->toDateString() => 'يسري التعديل من '.$from.'، ويظهر في «ملفي الوظيفي» من ذلك التاريخ.',
                        default => 'يسري التعديل من '.$from.'. تجده في «ملفي الوظيفي».',
                    },
                    'url' => route('my.profile', $tab !== null ? ['tab' => $tab] : [], false),
                    'dedupe' => $dedupe,
                ]);
            }
        }
    }

    /**
     * The tab of «ملفي الوظيفي» where the employee sees what the change touched: pay only when the employee may
     * see their own pay (the «self_view_pay» setting of the employee files, or a pay permission of their own),
     * the bank account (always shown to its owner, masked) otherwise. Null when neither part is theirs to see.
     */
    private function ownProfileTab(object $change, int $userId): ?string
    {
        $user = User::find($userId);
        $employee = Employee::find($change->employee_id);
        $sections = $user && $employee ? $this->access->sections($user, $employee) : [];

        return match (true) {
            (bool) $change->changes_pay && ! empty($sections['pay']) => 'pay',
            (bool) $change->changes_bank && ! empty($sections['bank']) => 'bank',
            default => null,
        };
    }

    /**
     * «employee_is_executive»: the employee's own account holds an effective executive_director assignment, so HR's
     * send action routes the request to the Head of Accounts instead of the executive director.
     *
     * @return array{employee_is_executive: bool}
     */
    public function conditionContext(object $instance): array
    {
        $employeeId = (int) DB::table('employee_pay_changes')->where('id', $instance->subject_id)->value('employee_id');

        return ['employee_is_executive' => $employeeId > 0 && $this->access->employeeIsExecutive($employeeId)];
    }

    /**
     * The facts conditionContext() supplies, for the publishing check (WorkflowSubjects::conditionFields).
     *
     * @return array<string, string>
     */
    public function conditionFields(): array
    {
        return ['employee_is_executive' => 'boolean'];
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
