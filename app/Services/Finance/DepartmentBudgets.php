<?php

namespace App\Services\Finance;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Department allocations and actual recorded spending, without enabling the general accounting module. */
final class DepartmentBudgets
{
    public function __construct(private readonly FinanceGuard $audit) {}

    public function headedDepartments(?User $user): Collection
    {
        if (! $user?->is_active) {
            return collect();
        }
        $today = today()->toDateString();
        $appointments = $user->roleAssignments()->whereHas('role', fn ($role) => $role->where('code', 'department_head'))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->select('department_id');

        return $this->departments()->where('d.head_user_id', $user->id)->whereIn('d.id', $appointments)
            ->orderBy('d.name')->get(['d.id', 'd.name', 'd.code']);
    }

    public function canManage(?User $user): bool
    {
        return $this->headedDepartments($user)->contains(fn (object $department): bool => in_array($department->code, ['FIN', 'ACC'], true));
    }

    public function summary(User $user, string $period, ?int $selectedDepartment = null): array
    {
        $departments = $this->headedDepartments($user);
        if ($selectedDepartment !== null) {
            abort_unless($departments->contains('id', $selectedDepartment), 403, 'ميزانية القسم متاحة لرئيسه المعيّن فقط.');
            $departments = $departments->where('id', $selectedDepartment);
        }
        $rows = $departments->isEmpty() ? collect() : $this->rows($period, $departments->pluck('id')->all())->groupBy('departmentId');
        $items = $departments->flatMap(fn (object $department): array => isset($rows[$department->id])
            ? $rows[$department->id]->map(fn (array $row): array => [...$row, 'configured' => true])->all()
            : [['departmentId' => (int) $department->id, 'department' => $department->name, 'configured' => false,
                'currency' => null, 'allocated' => null, 'spent' => null, 'remaining' => null, 'asOf' => null, 'updatedAt' => null]])->values()->all();

        return ['period' => $period, 'items' => $items, 'manageUrl' => $this->canManage($user) ? route('finance.department-budgets.index', ['period' => $period], false) : null];
    }

    public function management(string $period): array
    {
        $departments = $this->departments()->orderBy('d.name')->get(['d.id', 'd.name', 'd.code']);
        $periods = DB::table('finance_department_budgets')->whereNotNull('fiscal_month')->distinct()->get(['fiscal_year', 'fiscal_month'])
            ->map(fn (object $row): string => sprintf('%04d-%02d', $row->fiscal_year, $row->fiscal_month))
            ->merge([now()->format('Y-m'), now()->startOfMonth()->subMonth()->format('Y-m'), now()->startOfMonth()->addMonth()->format('Y-m'), $period])
            ->unique()->sortDesc()->values()->all();

        return ['period' => $period, 'periods' => $periods, 'departments' => $departments,
            'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->get(['code', 'name_ar']),
            'budgets' => $this->rows($period, $departments->pluck('id')->all())->all()];
    }

    public function create(User $actor, array $data): int
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $data): int {
            $month = CarbonImmutable::createFromFormat('!Y-m', $data['period']);
            $department = $this->departments()->where('d.id', $data['department_id'])->lockForUpdate()->first(['d.id']);
            if (! $department) {
                throw ValidationException::withMessages(['department_id' => 'اختر قسمًا نشطًا من الهيكل التنظيمي.']);
            }
            if (DB::table('finance_department_budgets')->where('department_id', $department->id)->where('fiscal_year', $month->year)->where('fiscal_month', $month->month)->where('currency', $data['currency'])->exists()) {
                throw ValidationException::withMessages(['department_id' => 'أُدخلت ميزانية القسم لهذا الشهر والعملة؛ عدّل السجل الموجود.']);
            }
            $values = [...$this->values($data), 'department_id' => $department->id, 'fiscal_year' => $month->year, 'fiscal_month' => $month->month,
                'currency' => $data['currency'], 'revision' => 1, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()];
            $id = DB::table('finance_department_budgets')->insertGetId($values);
            $this->audit->audit('department_budget', $id, (int) $actor->id, 'create', ['after' => $values]);

            return $id;
        });
    }

    public function update(User $actor, int $id, array $data): string
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id, $data): string {
            $row = DB::table('finance_department_budgets')->where('id', $id)->whereNotNull('fiscal_month')->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($this->departments()->where('d.id', $row->department_id)->exists(), 403, 'القسم غير نشط.');
            if ((int) $row->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'تغيّرت الميزانية منذ فتحها؛ أعد تحميل النسخة الحديثة قبل الحفظ.']);
            }
            $values = [...$this->values($data), 'revision' => (int) $row->revision + 1, 'updated_by' => $actor->id, 'updated_at' => now()];
            DB::table('finance_department_budgets')->where('id', $id)->update($values);
            $this->audit->audit('department_budget', $id, (int) $actor->id, 'update', ['before' => (array) $row, 'after' => $values]);

            return sprintf('%04d-%02d', $row->fiscal_year, $row->fiscal_month);
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($this->canManage($actor), 403, 'إدخال ميزانيات الأقسام وتعديلها لرئيس قسم الحسابات المعيّن فقط.');
    }

    private function departments(): Builder
    {
        return DB::table('departments as d')->where('d.node_type', 'department')->where('d.is_active', true)->whereNull('d.deleted_at');
    }

    private function values(array $data): array
    {
        return ['allocated_amount' => FinanceMoney::format(FinanceMoney::cents((string) $data['allocated_amount'])),
            'as_of' => $data['as_of'], 'reference' => trim($data['reference']), 'notes' => isset($data['notes']) ? trim($data['notes']) : null];
    }

    private function rows(string $period, array $departments): Collection
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $period);
        $asOf = min($month->endOfMonth()->toDateString(), today()->toDateString());
        $spending = $this->spending($period, $departments);

        return DB::table('finance_department_budgets as b')->join('departments as d', 'd.id', '=', 'b.department_id')
            ->whereIn('b.department_id', $departments)->where('b.fiscal_year', $month->year)->where('b.fiscal_month', $month->month)->orderBy('d.name')->orderBy('b.currency')
            ->get(['b.*', 'd.name as department'])->map(function (object $row) use ($spending, $period, $asOf): array {
                $allocated = FinanceMoney::cents((string) $row->allocated_amount);
                $spent = $spending[$row->department_id.'|'.$row->currency] ?? 0;

                return ['id' => (int) $row->id, 'departmentId' => (int) $row->department_id, 'department' => $row->department,
                    'period' => $period, 'currency' => $row->currency, 'allocated' => FinanceMoney::format($allocated),
                    'spent' => FinanceMoney::format($spent), 'remaining' => FinanceMoney::format($allocated - $spent),
                    'asOf' => $asOf, 'allocationAsOf' => (string) $row->as_of, 'reference' => $row->reference, 'notes' => $row->notes,
                    'revision' => (int) $row->revision, 'updatedAt' => (string) $row->updated_at];
            });
    }

    /** Recorded executed payments plus documentary vouchers; an invoice payment is counted once across both paths. */
    private function spending(string $budgetPeriod, array $departments): array
    {
        if ($departments === [] || $budgetPeriod > now()->format('Y-m')) {
            return [];
        }
        $owners = $this->spendingDepartments($departments);
        $sources = array_keys($owners);
        $month = CarbonImmutable::createFromFormat('!Y-m', $budgetPeriod);
        $period = [$month->toDateString(), min($month->endOfMonth()->toDateString(), today()->toDateString())];
        $executed = fn (): Builder => DB::table('finance_transactions as t')->where('t.kind', 'payment')->where('t.state', 'executed')->whereNull('t.reversal_of_id')
            ->whereNotExists(fn (Builder $reversal) => $reversal->from('finance_transactions as reversal')->whereColumn('reversal.reversal_of_id', 't.id')->where('reversal.state', 'executed'));
        $internal = $executed()->join('finance_budget_lines as line', 'line.id', '=', 't.budget_line_id')
            ->whereIn('line.department_id', $sources)->whereBetween('t.effective_on', $period)
            ->groupBy('line.department_id', 't.currency')->select(['line.department_id', 't.currency'])->selectRaw('SUM(t.amount) as spent')->get();
        $settled = $executed()->join('finance_invoice_payments as ip', 'ip.transaction_id', '=', 't.id')->whereNull('ip.reversed_by_transaction_id')
            ->groupBy('ip.invoice_id', 't.currency')->select(['ip.invoice_id', 't.currency'])->selectRaw('SUM(ip.amount) as amount');
        $external = DB::table('finance_purchase_invoices as inv')->join('finance_purchase_orders as po', 'po.id', '=', 'inv.order_id')
            ->join('finance_purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->leftJoinSub($settled, 'settled', fn ($join) => $join->on('settled.invoice_id', '=', 'inv.id')->on('settled.currency', '=', 'inv.currency'))
            ->whereIn('pr.department_id', $sources)->where('inv.state', 'paid')->whereNotNull('inv.external_voucher_no')->whereNotNull('inv.external_recorded_at')
            ->whereBetween('inv.external_voucher_on', $period)->groupBy('pr.department_id', 'inv.currency')->select(['pr.department_id', 'inv.currency'])
            ->selectRaw('SUM(CASE WHEN inv.amount > COALESCE(settled.amount, 0) THEN inv.amount - COALESCE(settled.amount, 0) ELSE 0 END) as spent')->get();
        $result = [];
        foreach ($internal->concat($external) as $row) {
            $key = $owners[$row->department_id].'|'.$row->currency;
            $result[$key] = ($result[$key] ?? 0) + FinanceMoney::cents((string) $row->spent);
        }

        return $result;
    }

    /** Include historical spending of subordinate units; a nested department keeps its own budget. */
    private function spendingDepartments(array $departments): array
    {
        $tree = DB::table('departments')->get(['id', 'parent_id', 'node_type'])->keyBy('id');
        $owners = [];
        foreach ($tree as $source) {
            $node = $source;
            $visited = [];
            while ($node && ! isset($visited[$node->id])) {
                $visited[$node->id] = true;
                if ($node->node_type === 'department') {
                    if (in_array((int) $node->id, $departments, true)) {
                        $owners[(int) $source->id] = (int) $node->id;
                    }
                    break;
                }
                $node = $tree[$node->parent_id] ?? null;
            }
        }

        return $owners;
    }
}
