<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Hr\EmployeeAccess;
use App\Services\Hr\EmployeeNames;
use App\Services\Hr\EmployeePayChanges;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pay and bank change requests: HR proposes with a reference document, the executive director decides on the
 * central workflow (/workflows/{publicId}/act). Pay figures are shown only to holders of the pay permission.
 */
class EmployeePayChangeController extends Controller
{
    public function __construct(private readonly EmployeePayChanges $changes, private readonly EmployeeAccess $access) {}

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'];
        $rate = ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'];

        return [
            'effective_from' => ['required', 'date_format:Y-m-d', 'after:2000-01-01'],
            'changes_pay' => ['required', 'boolean'], 'changes_bank' => ['required', 'boolean'],
            'currency_code' => ['nullable', Rule::exists('currencies', 'code')->where('is_active', true)],
            'base_salary' => $money, 'spouse_allowance' => $money, 'children_allowance' => $money,
            'cost_of_living_rate' => $rate, 'nature_of_work_rate' => $rate,
            'bank_value_id' => ['nullable', 'integer', Rule::exists('master_data_values', 'id')->where('is_active', true)],
            'account_number' => ['nullable', 'string', 'max:40'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'reference' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function store(Request $request, int $employee): RedirectResponse
    {
        $record = Employee::query()->findOrFail($employee);
        abort_unless($this->access->canRequestPayChange($request->user()), 403);
        $data = $request->validate($this->rules(), [], ['effective_from' => 'تاريخ السريان', 'reason' => 'سبب التعديل', 'reference' => 'المستند المرجعي']);
        $id = $this->changes->propose($request->user(), $record, $data, $request->ip());

        return redirect()->route('admin.employee-pay-changes.show', $id)->with('success', 'أُرسل الطلب إلى المدير التنفيذي. لا يسري التعديل قبل اعتماده.');
    }

    public function update(Request $request, int $change): RedirectResponse
    {
        $data = $request->validate($this->rules(), [], ['effective_from' => 'تاريخ السريان', 'reason' => 'سبب التعديل', 'reference' => 'المستند المرجعي']);
        $this->changes->revise($request->user(), $change, $data, $request->ip());

        return back(303)->with('success', 'حُفظ تصحيح الطلب. أعد رفعه للمدير التنفيذي من الإجراءات.');
    }

    public function withdraw(Request $request, int $change): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => 'سبب السحب']);
        $this->changes->withdraw($request->user(), $change, $data['reason'], $request->ip());

        return back(303)->with('success', 'سُحب الطلب وأُغلق في مسار العمل.');
    }

    public function show(Request $request, int $change, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $row = DB::table('employee_pay_changes as c')->join('employees as e', 'e.id', '=', 'c.employee_id')->leftJoin('users as u', 'u.id', '=', 'c.requested_by')
            ->leftJoin('users as d', 'd.id', '=', 'c.decided_by')->leftJoin('master_data_values as b', 'b.id', '=', 'c.bank_value_id')
            ->where('c.id', $change)->first(['c.*', 'e.employee_number', 'e.full_name_ar', 'e.first_name_ar', 'e.family_name_ar', 'u.name as requester', 'd.name as decider', 'b.name_ar as bank_name']);
        abort_unless($row, 404);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $participant = $instance && $visibility->accessLevel($user, (int) $instance->id) !== null;
        abort_unless($user->is_active && ($this->access->canViewPay($user) || (int) $row->requested_by === (int) $user->id || $participant), 403);
        $pay = $this->access->canViewPay($user);
        $current = $this->changes->currentCompensation((int) $row->employee_id);
        $currentBank = $this->changes->currentBank((int) $row->employee_id);
        $editable = $row->status === 'returned' && (int) $row->requested_by === (int) $user->id;

        return Inertia::render('Admin/EmployeeProfiles/PayChange', [
            'change' => [
                'id' => (int) $row->id, 'status' => $row->status, 'statusLabel' => EmployeePayChanges::STATUS_LABELS[$row->status] ?? $row->status,
                'employee' => ['id' => (int) $row->employee_id, 'number' => $row->employee_number, 'name' => EmployeeNames::display($row)],
                'effective_from' => $row->effective_from, 'changes_pay' => (bool) $row->changes_pay, 'changes_bank' => (bool) $row->changes_bank,
                'pay' => $pay && $row->changes_pay ? array_intersect_key((array) $row, array_flip(['currency_code', 'base_salary', 'spouse_allowance', 'children_allowance', 'cost_of_living_rate', 'nature_of_work_rate'])) : null,
                'bank' => $row->changes_bank ? ['bank' => $row->bank_name, 'account' => $this->access->canViewBank($user) && $row->account_number
                    ? Crypt::decryptString($row->account_number) : EmployeeAccess::maskAccount($row->account_last4)] : null,
                'reason' => $row->reason, 'reference' => $row->reference, 'requester' => $row->requester, 'decider' => $row->decider, 'decided_at' => $row->decided_at,
                'created_at' => $row->created_at, 'bank_value_id' => $row->bank_value_id,
            ],
            'current' => [
                'pay' => $pay && $current ? (array) $current : null,
                'bank' => $currentBank ? ['bank' => $currentBank->bank_name ?? $currentBank->bank_source, 'account' => EmployeeAccess::maskAccount($currentBank->account_last4), 'status' => $currentBank->status] : null,
            ],
            'progress' => $instance ? $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision,
                'status' => $instance->status, 'actions' => $engine->availableActions($instance, $user)] : null,
            'editable' => $editable,
            'canWithdraw' => in_array($row->status, ['in_review', 'returned'], true) && (int) $row->requested_by === (int) $user->id,
            'choices' => $editable ? $this->choices() : null,
            'canSeePay' => $pay,
        ]);
    }

    /**
     * @return array{currencies: list<string>, banks: mixed}
     */
    public function choices(): array
    {
        return [
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code')->all(),
            'banks' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'banks')
                ->where('v.is_active', true)->orderBy('v.sort_order')->get(['v.id', 'v.name_ar']),
        ];
    }
}
