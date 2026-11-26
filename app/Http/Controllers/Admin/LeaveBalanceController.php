<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeLeaveBalanceService;
use App\Services\EmployeeLeaveEntitlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class LeaveBalanceController extends Controller
{
    private function guard(Request $r): void
    {
        abort_unless($r->user()?->canManageEmployees(), 403);
    }

    private function typeValid(int $id): bool
    {
        return DB::table('master_data_values as v')->join('master_data_types as t', 'v.type_id', '=', 't.id')
            ->where('v.id', $id)->where('t.code', 'leave_types')->where('v.is_active', true)->where('t.is_active', true)->exists();
    }

    private function account(int $employee, int $type, int $year): int
    {
        DB::table('employee_leave_accounts')->insertOrIgnore([
            'employee_id' => $employee, 'leave_type_value_id' => $type, 'balance_year' => $year,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('employee_leave_accounts')
            ->where('employee_id', $employee)->where('leave_type_value_id', $type)
            ->where('balance_year', $year)->lockForUpdate()->value('id');
    }

    public function index(Request $r, EmployeeLeaveBalanceService $balances, EmployeeLeaveEntitlementService $entitlement): Response
    {
        abort_unless($r->user()?->is_active, 403);
        $managed = $r->user()->canManageEmployees();
        $employeeId = $managed ? (int) $r->query('employee_id', 0) : (int) ($r->user()->employeeProfile?->id ?? 0);
        $employee = $employeeId ? Employee::find($employeeId) : null;
        $year = (int) $r->query('year', now()->year);
        abort_if($year < 2000 || $year > 2200, 422);
        $types = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', 'leave_types')->where('t.is_active', true)->where('v.is_active', true)
            ->orderBy('v.name_ar')->get(['v.id', 'v.name_ar']);
        $items = [];
        if ($employee) {
            foreach ($types as $type) {
                $items[] = [
                    'name' => $type->name_ar, 'type_id' => $type->id,
                    'balance' => $balances->summary($employee->id, $type->id, $year),
                    'entitlement' => $entitlement->explain($employee, $type->id),
                ];
            }
        }

        return Inertia::render('Leaves/Balances', [
            'username' => $r->user()->name,
            'navigation' => [['label' => 'الإجازات', 'url' => route('leaves.index'), 'icon' => '▦', 'active' => false]],
            'managed' => $managed, 'isAdmin' => $r->user()->isSystemAdmin(), 'employee' => $employee?->only(['id', 'employee_number', 'first_name_ar', 'family_name_ar']),
            'basis' => $employee ? $entitlement->basis($employee) : null,
            'year' => $year, 'items' => $items, 'types' => $types,
            'currentYear' => (int) now()->year,
            'employees' => $managed ? Employee::query()->orderBy('employee_number')->limit(500)->get(['id', 'employee_number', 'first_name_ar', 'family_name_ar']) : [],
        ]);
    }

    /**
     * HR records an employee's opening balance once per leave type and year, from the approved source documents.
     * The expected entitlement is shown beside it for review only; nothing is credited automatically.
     * A recorded opening balance is never edited: corrections are documented adjustments.
     */
    public function recordOpening(Request $r): RedirectResponse
    {
        $this->guard($r);
        $d = $r->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'leave_type_value_id' => ['required', 'integer'],
            'balance_year' => ['required', 'integer', 'between:2000,2200'],
            'annual_entitlement_days' => ['required', 'numeric', 'min:0', 'max:366', 'decimal:0,2'],
            'opening_balance_days' => ['required', 'numeric', 'min:0', 'max:9999', 'decimal:0,2'],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ], [], [
            'annual_entitlement_days' => 'الاستحقاق السنوي', 'opening_balance_days' => 'الرصيد الافتتاحي', 'notes' => 'مرجع المستند',
        ]);
        if (! $this->typeValid((int) $d['leave_type_value_id'])) {
            throw ValidationException::withMessages(['leave_type_value_id' => 'نوع الإجازة غير معتمد.']);
        }
        if ((int) $d['balance_year'] !== (int) now()->year) {
            throw ValidationException::withMessages(['balance_year' => 'يُسجل الرصيد الافتتاحي للسنة الحالية فقط؛ السنوات السابقة تحتاج ترحيلًا معتمدًا.']);
        }
        $employee = Employee::query()->findOrFail($d['employee_id']);
        if (! $employee->employment_term_value_id) {
            throw ValidationException::withMessages(['employee_id' => 'حدد بند التوظيف في ملف الموظف أولًا؛ الرصيد الافتتاحي يُسجل حسب بند التوظيف.']);
        }

        DB::transaction(function () use ($r, $d, $employee): void {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $exists = DB::table('employee_leave_opening_balances')->where('employee_id', $employee->id)
                ->where('leave_type_value_id', $d['leave_type_value_id'])->where('balance_year', $d['balance_year'])->exists();
            if ($exists) {
                throw ValidationException::withMessages(['leave_type_value_id' => 'سُجّل الرصيد الافتتاحي لهذا النوع والسنة سابقًا؛ أي تصحيح يتم بتسوية موثقة.']);
            }
            $id = DB::table('employee_leave_opening_balances')->insertGetId([
                'employee_id' => $employee->id, 'leave_type_value_id' => $d['leave_type_value_id'], 'balance_year' => $d['balance_year'],
                'annual_entitlement_days' => $d['annual_entitlement_days'], 'opening_balance_days' => $d['opening_balance_days'],
                'notes' => $d['notes'], 'created_by' => $r->user()->id, 'updated_by' => $r->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'actor_id' => $r->user()->id, 'action' => 'leave_opening_balance.recorded',
                'subject_type' => 'employee_leave_opening_balances', 'subject_id' => $id, 'ip_address' => $r->ip(),
                'metadata' => json_encode(['before' => null, 'after' => [
                    'employee_id' => $employee->id, 'leave_type_value_id' => $d['leave_type_value_id'], 'balance_year' => $d['balance_year'],
                    'annual_entitlement_days' => $d['annual_entitlement_days'], 'opening_balance_days' => $d['opening_balance_days'],
                ]], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'سُجّل الرصيد الافتتاحي. أي تصحيح لاحق يتم بتسوية موثقة.');
    }

    public function grant(Request $r, EmployeeLeaveBalanceService $balances, EmployeeLeaveEntitlementService $service): RedirectResponse
    {
        $this->guard($r);
        abort_unless($r->user()->isSystemAdmin(), 403);
        $data = $r->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'leave_type_value_id' => ['required', 'integer'], 'confirmed' => ['required', 'accepted']]);
        $employee = Employee::findOrFail($data['employee_id']);
        if ($employee->onboarding_status !== 'completed' || $employee->employment_status !== 'active') {
            throw ValidationException::withMessages(['employee_id' => 'يجب إكمال وتفعيل ملف الموظف قبل إثبات الاستحقاق.']);
        }
        $typeId = (int) $data['leave_type_value_id'];
        if (! $this->typeValid($typeId)) {
            throw ValidationException::withMessages(['leave_type_value_id' => 'نوع الإجازة غير معتمد.']);
        }
        $settings = DB::table('leave_module_settings')->where('id', 1)->first();
        if (! $settings || $settings->grant_mode !== 'full_year') {
            throw ValidationException::withMessages(['confirmed' => 'لم تعتمد الإدارة آلية الاستحقاق السنوي الكامل بعد.']);
        }
        $year = (int) now()->year;
        $result = $service->calculate($employee, $typeId, now()->toDateString());
        if ($result['status'] !== 'defined' || $result['annual_days'] <= 0) {
            throw ValidationException::withMessages(['employee_id' => 'لا توجد قاعدة استحقاق معتمدة لهذه الحالة.']);
        }
        $opening = DB::table('employee_leave_opening_balances')->where('employee_id', $employee->id)
            ->where('leave_type_value_id', $typeId)->where('balance_year', $year)->first();
        if ($opening && ((float) $opening->annual_entitlement_days > 0 || (float) $opening->opening_balance_days > 0)) {
            throw ValidationException::withMessages(['confirmed' => 'يوجد رصيد افتتاحي أو استحقاق مسجل لهذه السنة؛ يلزم توثيق تسوية المطابقة قبل إثبات الاستحقاق كاملًا حتى لا يتكرر.']);
        }
        DB::transaction(function () use ($r, $employee, $typeId, $year, $result) {
            $account = $this->account($employee->id, $typeId, $year);
            $key = "annual_grant:{$employee->id}:{$typeId}:{$year}";
            if (DB::table('employee_leave_transactions')->where('external_key', $key)->exists()) {
                throw ValidationException::withMessages(['confirmed' => 'تم إثبات استحقاق هذا العام سابقًا؛ لن يتم تكراره.']);
            }
            DB::table('employee_leave_transactions')->insert([
                'account_id' => $account, 'amount_days' => $result['annual_days'], 'kind' => 'annual_grant',
                'external_key' => $key, 'reason' => 'إثبات الاستحقاق السنوي وفق السياسة '.$result['rule_id'].' بتأكيد إداري',
                'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'تم إثبات الاستحقاق السنوي مرة واحدة.');
    }

    public function adjust(Request $r, EmployeeLeaveBalanceService $balances): RedirectResponse
    {
        $this->guard($r);
        abort_unless($r->user()->isSystemAdmin(), 403);
        $d = $r->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'], 'leave_type_value_id' => ['required', 'integer'],
            'balance_year' => ['required', 'integer', 'between:2000,2200'],
            'amount_days' => ['required', 'numeric', 'between:-9999.99,9999.99', 'not_in:0'],
            'reason' => ['required', 'string', 'min:12', 'max:5000'], 'confirmed' => ['required', 'accepted'],
        ]);
        if (! $this->typeValid((int) $d['leave_type_value_id'])) {
            throw ValidationException::withMessages(['leave_type_value_id' => 'نوع إجازة غير معتمد.']);
        }
        $year = (int) $d['balance_year'];
        if ($year !== (int) now()->year) {
            throw ValidationException::withMessages(['balance_year' => 'التسويات التاريخية تحتاج إجراء ترحيل مستقلًا؛ يُسمح بالسنة الحالية فقط.']);
        }
        if (! DB::table('leave_policy_drafts')->where('status', 'launched')->exists()) {
            throw ValidationException::withMessages(['amount_days' => 'لا تسويات آلية قبل الإطلاق.']);
        }
        DB::transaction(function () use ($r, $d, $year, $balances) {
            $account = $this->account((int) $d['employee_id'], (int) $d['leave_type_value_id'], $year);
            $current = $balances->summary((int) $d['employee_id'], (int) $d['leave_type_value_id'], $year);
            if ($current['available'] + (float) $d['amount_days'] < 0) {
                throw ValidationException::withMessages(['amount_days' => 'التسوية تجعل الرصيد المتاح سالبًا.']);
            }
            DB::table('employee_leave_transactions')->insert([
                'account_id' => $account, 'kind' => 'adjustment', 'amount_days' => $d['amount_days'],
                'external_key' => 'adjustment:'.(string) Str::uuid(), 'reason' => $d['reason'],
                'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'تم توثيق التسوية دون المساس بالأرصدة الافتتاحية.');
    }
}
