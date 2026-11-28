<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\DepartmentBudgets;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\FinanceSettlementService;
use App\Services\Finance\StockMovementSources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FinanceOperationsController extends Controller
{
    public function __construct(private readonly FinanceGuard $guard, private readonly FinanceOperations $ops) {}

    private const MODULES = [
        'treasuries' => ['finance_treasuries', 'finance.treasury.manage', ['code' => 'required|string|max:40', 'name' => 'required|string|max:255', 'kind' => 'required|in:cash,bank', 'currency' => 'required|exists:currencies,code', 'bank_name' => 'nullable|string|max:255', 'account_mask' => 'nullable|string|max:255']],
        'budgets' => ['finance_budget_lines', 'finance.budget.manage', ['fiscal_year' => 'required|integer|between:2020,2100', 'department_id' => 'required|exists:departments,id', 'code' => 'required|string|max:60', 'title' => 'required|string|max:255', 'currency' => 'required|exists:currencies,code', 'approved_amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/D']],
        'suppliers' => ['finance_suppliers', 'finance.procurement.manage', ['name' => 'required|string|max:255', 'registration_no' => 'nullable|string|max:255', 'phone' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255']],
        'warehouses' => ['finance_warehouses', 'finance.warehouse.manage', ['code' => 'required|string|max:45', 'name' => 'required|string|max:255']],
        'items' => ['finance_stock_items', 'finance.warehouse.manage', ['sku' => 'required|string|max:80', 'name' => 'required|string|max:255', 'unit' => 'required|string|max:30', 'minimum_qty' => 'required|regex:/^\d{1,9}(\.\d{1,3})?$/D']],
        'funds' => ['finance_restricted_funds', 'finance.fund.manage', ['name' => 'required|string|max:255', 'purpose' => 'required|string', 'currency' => 'required|exists:currencies,code']],
    ];

    public function index(Request $request, DepartmentBudgets $budgets)
    {
        $this->guard->assert($request->user(), 'finance.view');
        $summary = [];
        foreach (self::MODULES as $slug => $meta) {
            if ($this->guard->allows($request->user(), $meta[1])) {
                $summary[$slug] = DB::table($meta[0])->count();
            }
        }
        // Unit counts only for those who work in or monitor that unit.
        $user = $request->user();
        $sees = fn (array $codes): bool => collect([...$codes, 'finance.monitor'])->contains(fn (string $code): bool => $this->guard->allows($user, $code));
        $summary['purchases'] = $sees(['finance.procurement.manage', 'finance.procurement.review'])
            ? DB::table('finance_purchase_requests')->select('state', DB::raw('count(*) as count'))->groupBy('state')->get() : [];
        $transactionKinds = array_values(array_filter(['payment', 'receipt'], fn (string $kind): bool => $this->guard->canViewTransactions($user, $kind)));
        $summary['transactions'] = $transactionKinds !== []
            ? DB::table('finance_transactions')->whereIn('kind', $transactionKinds)->select('state', DB::raw('count(*) as count'))->groupBy('state')->get() : [];

        return Inertia::render('Finance/Index', ['summary' => $summary, 'allowed' => $this->guard->uiPermissions($user),
            'transactionKinds' => $transactionKinds, 'transactionKind' => in_array($request->query('kind'), $transactionKinds, true) ? $request->query('kind') : null,
            'canManageDepartmentBudgets' => $budgets->canManage($user)]);
    }

    public function browse(Request $request, string $module)
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        [$table,$permission] = self::MODULES[$module];
        // Reading a catalog is open to the head's monitoring; creating stays with the unit's permission.
        if (! $this->guard->allows($request->user(), 'finance.monitor')) {
            $this->guard->assert($request->user(), $permission);
        }

        return response()->json(DB::table($table)->orderByDesc('id')->paginate(30));
    }

    public function create(Request $request, string $module)
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        [$table,$permission,$rules] = self::MODULES[$module];
        $this->guard->assert($request->user(), $permission);
        $data = $request->validate($rules);
        $id = DB::transaction(function () use ($data, $table, $request) {
            $id = DB::table($table)->insertGetId(array_merge($data, ['created_at' => now(), 'updated_at' => now()]));
            $this->guard->audit($table, $id, $request->user()->id, 'create');

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function purchase(Request $request)
    {
        $this->guard->assert($request->user(), 'finance.procurement.manage');
        $documentary = $this->guard->documentaryMode();
        // A purchase request entered by hand has no source record. The link to a «طلب شراء» is made only by the
        // workflow when procurement accepts it (AimsRequestEffects); a typed link would put that request's requester
        // in the creator's place in the separation-of-duties checks (FinanceOperations::requesterOf).
        $data = $request->validate(['department_id' => 'required|exists:departments,id', 'budget_line_id' => $documentary ? 'prohibited' : 'required|exists:finance_budget_lines,id', 'currency' => 'required|exists:currencies,code', 'purpose' => 'required|string|max:5000', 'source_module' => 'prohibited', 'source_id' => 'prohibited', 'items' => 'required|array|min:1', 'items.*.item_name' => 'required|string|max:255', 'items.*.quantity' => 'required|regex:/^\d{1,9}(\.\d{1,3})?$/', 'items.*.unit' => 'required|string|max:40', 'items.*.unit_price' => 'required|regex:/^\d{1,9}(\.\d{1,4})?$/'],
            ['source_module.prohibited' => 'ربط طلب الشراء بطلب من «طلباتي» يتم تلقائيًا عند استلام المشتريات للطلب، ولا يُدخل يدويًا.', 'source_id.prohibited' => 'ربط طلب الشراء بطلب من «طلباتي» يتم تلقائيًا عند استلام المشتريات للطلب، ولا يُدخل يدويًا.']);
        $id = DB::transaction(function () use ($data, $request) {
            $id = DB::table('finance_purchase_requests')->insertGetId([
                'number' => 'PR-'.strtoupper(bin2hex(random_bytes(8))), 'department_id' => $data['department_id'],
                'budget_line_id' => $data['budget_line_id'] ?? null, 'currency' => $data['currency'],
                'purpose' => $data['purpose'], 'source_module' => null, 'source_id' => null,
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['items'] as $i) {
                DB::table('finance_purchase_items')->insert($i + ['purchase_request_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->guard->audit('purchase', $id, $request->user()->id, 'create');

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function purchaseAction(Request $request, int $id, string $action)
    {
        $perms = ['submit' => 'finance.procurement.manage', 'review' => 'finance.procurement.review', 'director' => 'finance.procurement.director_approve', 'board' => 'finance.procurement.board_approve'];
        abort_unless(isset($perms[$action]), 404);
        $this->guard->assert($request->user(), $perms[$action]);
        match ($action) {
            'submit' => $this->ops->submitPurchase($id, $request->user()->id),
            'review' => $this->ops->reviewPurchase($id, $request->user()->id),
            'director' => $this->ops->directorPurchase($id, $request->user()->id),
            'board' => $this->ops->boardPurchase($id, $request->user()->id)
        };

        return response()->json(['ok' => true]);
    }

    /** Reject or return the request at its current approval stage; the stage's own permission decides. */
    public function purchaseDecision(Request $request, int $id, string $decision)
    {
        $state = DB::table('finance_purchase_requests')->where('id', $id)->value('state');
        abort_unless($state !== null, 404);
        // Permission before validation; a request outside an approval stage is refused by its stage's own rule.
        $this->guard->assert($request->user(), FinanceOperations::PURCHASE_STAGES[$state] ?? 'finance.procurement.review');
        $data = $request->validate(['note' => 'required|string|min:5|max:2000'], [], ['note' => 'السبب']);
        $this->ops->decidePurchase($id, (int) $request->user()->id, $decision, trim($data['note']));

        return response()->json(['ok' => true]);
    }

    /** Cancelling an approved purchase, its unfilled order or an order's undelivered remainder: the executive director's decision. */
    public function purchaseCancel(Request $request, int $id)
    {
        abort_unless(DB::table('finance_purchase_requests')->where('id', $id)->exists(), 404);
        $this->guard->assert($request->user(), 'finance.procurement.director_approve');
        if (! Schema::hasColumn('finance_purchase_requests', 'cancelled_by')) {
            throw ValidationException::withMessages(['state' => 'الإلغاء غير متاح قبل تحديث قاعدة البيانات.']);
        }
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000'], [], ['reason' => 'سبب الإلغاء']);
        $outcome = $this->ops->cancelPurchase($id, (int) $request->user()->id, trim($data['reason']));

        return response()->json(['ok' => true, 'outcome' => $outcome]);
    }

    public function purchaseLines(Request $request, int $id)
    {
        $this->guard->assert($request->user(), 'finance.procurement.manage');
        $data = $request->validate(['purpose' => 'nullable|string|max:5000', 'items' => 'required|array|min:1|max:100', 'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|regex:/^\d{1,9}(\.\d{1,3})?$/', 'items.*.unit' => 'required|string|max:40', 'items.*.unit_price' => 'required|regex:/^\d{1,9}(\.\d{1,4})?$/']);
        $this->ops->replacePurchaseLines($id, (int) $request->user()->id, $data['items'], $data['purpose'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function movement(Request $request)
    {
        // Permission before validation: a user outside the stores never learns the form's rules.
        if (! $this->guard->allows($request->user(), 'finance.stock.receive') && ! $this->guard->allows($request->user(), 'finance.stock.issue')) {
            $this->guard->assert($request->user(), 'finance.stock.receive');
        }
        $data = $request->validate(['warehouse_id' => 'required|exists:finance_warehouses,id', 'stock_item_id' => 'required|exists:finance_stock_items,id', 'kind' => 'required|in:receipt,return_in,adjust_in,issue,return_out,adjust_out', 'quantity' => 'required|regex:/^\d{1,9}(\.\d{1,3})?$/', 'unit_cost' => 'required|regex:/^\d{1,9}(\.\d{1,4})?$/', 'reference_type' => 'required|string|max:55', 'reference_id' => 'required|string|max:140', 'note' => 'nullable|string|max:1000'],
            [], ['reference_type' => 'نوع المرجع', 'reference_id' => 'رقم المرجع']);
        $incoming = in_array($data['kind'], ['receipt', 'return_in', 'adjust_in'], true);
        $this->guard->assert($request->user(), $incoming ? 'finance.stock.receive' : 'finance.stock.issue');
        if (str_starts_with($data['kind'], 'adjust_')) {
            throw ValidationException::withMessages(['kind' => 'تسوية الجرد تُنفذ حصريًا عبر اعتماد محضر الجرد']);
        }
        // Every movement entered by hand stands on an approved source it names (StockMovementSources::SOURCES).
        $id = DB::transaction(function () use ($data, $request): int {
            DB::table('finance_warehouses')->where('id', $data['warehouse_id'])->lockForUpdate()->first();

            return $this->ops->stockMovement(app(StockMovementSources::class)->assert($data, $request->user()), $request->user()->id);
        });

        return response()->json(['id' => $id], 201);
    }

    public function createTransaction(Request $request)
    {
        $kind = $request->validate(['kind' => 'required|in:receipt,payment'])['kind'];
        $this->guard->assert($request->user(), FinanceGuard::transactionPermission($kind, 'draft'));
        $data = $request->validate(['kind' => 'required|in:receipt,payment', 'treasury_id' => 'required|exists:finance_treasuries,id', 'budget_line_id' => 'nullable|exists:finance_budget_lines,id', 'source_id' => 'nullable|exists:finance_sources,id', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'effective_on' => 'required|date', 'counterparty' => 'nullable|string|max:255', 'description' => 'nullable|string|max:2000']);
        if ($data['kind'] === 'payment' && empty($data['budget_line_id'])) {
            throw ValidationException::withMessages(['budget_line_id' => 'بند الموازنة مطلوب للصرف']);
        }
        if (FinanceMoney::cents($data['amount']) <= 0) {
            throw ValidationException::withMessages(['amount' => 'المبلغ غير صالح']);
        }
        $treasury = DB::table('finance_treasuries')->find($data['treasury_id']);
        if (! $treasury || ! $treasury->is_active) {
            throw ValidationException::withMessages(['treasury_id' => 'الحساب غير فعال']);
        }
        $id = DB::transaction(function () use ($data, $request, $treasury) {
            $id = DB::table('finance_transactions')->insertGetId(array_merge($data, ['currency' => $treasury->currency, 'state' => 'draft', 'document_no' => 'FV-'.strtoupper(bin2hex(random_bytes(8))), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]));
            $this->guard->audit('transaction', $id, $request->user()->id, 'create');

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function transactionAction(Request $request, int $id, string $action)
    {
        $map = ['submit' => ['draft', 'draft', 'pending_review'], 'review' => ['review', 'pending_review', 'pending_director'], 'approve' => ['approve', 'pending_director', 'approved'], 'execute' => ['execute', 'approved', 'executed']];
        abort_unless(isset($map[$action]), 404);
        [$perm,$from,$to] = $map[$action];
        $transaction = DB::table('finance_transactions')->find($id, ['id', 'kind']);
        abort_unless($transaction, 404);
        $this->guard->assert($request->user(), FinanceGuard::transactionPermission($transaction->kind, $perm));
        DB::transaction(function () use ($request, $id, $action, $perm, $from, $to) {
            $row = DB::table('finance_transactions')->lockForUpdate()->find($id);
            if (! $row || $row->state !== $from) {
                throw ValidationException::withMessages(['state' => 'الحركة ليست في المرحلة المطلوبة']);
            }
            $this->guard->assert($request->user(), FinanceGuard::transactionPermission($row->kind, $perm));
            $actor = $request->user()->id;
            if ($action === 'approve' && $row->reversal_of_id !== null) {
                $this->guard->assert($request->user(), 'finance.reversal.approve');
            }
            if ($action !== 'submit' && in_array($actor, [(int) $row->created_by, (int) $row->reviewed_by, (int) $row->approved_by], true)) {
                throw ValidationException::withMessages(['actor' => 'تعارض في صلاحيات المراجعة والاعتماد والتنفيذ']);
            }
            if ($action === 'execute') {
                if ($row->source_id && DB::table('finance_sources')->where('id', $row->source_id)->where('source_module', 'finance_purchase_invoice')->exists() && (! $row->budget_line_id || $row->kind !== 'payment')) {
                    throw ValidationException::withMessages(['invoice' => 'سداد فاتورة يجب أن يكون سند صرف مرتبطًا ببند الموازنة']);
                }
                $cheque = DB::table('finance_cheques')->where('transaction_id', $id)->first();
                if ($cheque && $cheque->state !== 'cleared') {
                    throw ValidationException::withMessages(['cheque' => 'لا يمكن تنفيذ سند شيك قبل تأكيد تحصيله']);
                }
                DB::table('finance_treasuries')->where('id', $row->treasury_id)->lockForUpdate()->first();
                if ($row->kind === 'payment' && $row->budget_line_id) {
                    $line = DB::table('finance_budget_lines')->where('id', $row->budget_line_id)->lockForUpdate()->first();
                    if (! $line || $line->currency !== $row->currency) {
                        throw ValidationException::withMessages(['budget' => 'عملة الموازنة تختلف عن عملة الصرف']);
                    }
                    $source = $row->source_id ? DB::table('finance_sources')->find($row->source_id) : null;
                    if ($source && $source->source_module === 'finance_purchase_invoice') {
                        $invoice = DB::table('finance_purchase_invoices')->where('id', $source->source_id)->lockForUpdate()->first();
                        if (! $invoice) {
                            throw ValidationException::withMessages(['invoice' => 'الفاتورة غير موجودة']);
                        }
                        $order = DB::table('finance_purchase_orders')->where('id', $invoice->order_id)->first();
                        $purchase = DB::table('finance_purchase_requests')->where('id', $order->purchase_request_id)->first();
                        if ($purchase->state !== 'approved' || (int) $row->budget_line_id !== (int) $purchase->budget_line_id || $row->currency !== $purchase->currency) {
                            throw ValidationException::withMessages(['purchase' => 'المطالبة لا تطابق أمر الشراء']);
                        }
                        app(FinanceSettlementService::class)->assertAvailable($row, 'invoice', true);
                        $paid = DB::table('finance_invoice_payments')->where('invoice_id', $invoice->id)->whereNull('reversed_by_transaction_id')->get(['amount']);
                        $used = 0;
                        foreach ($paid as $pay) {
                            $used += FinanceMoney::cents((string) $pay->amount);
                        }
                        if ($used + FinanceMoney::cents((string) $row->amount) > FinanceMoney::cents((string) $invoice->amount)) {
                            throw ValidationException::withMessages(['invoice' => 'الصرف يتجاوز الرصيد المتبقي للفاتورة']);
                        }
                        DB::table('finance_budget_movements')->insert(['budget_line_id' => $row->budget_line_id, 'kind' => 'release', 'amount' => $row->amount, 'state' => 'approved', 'reason' => 'تحويل الالتزام إلى مصروف فاتورة '.$invoice->id, 'created_by' => $actor, 'approved_by' => $row->approved_by, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    if (FinanceMoney::cents((string) $row->amount) > FinanceMoney::cents($this->ops->budgetAvailable((int) $row->budget_line_id))) {
                        throw ValidationException::withMessages(['budget' => 'الموازنة غير كافية']);
                    }
                    DB::table('finance_budget_movements')->insert(['budget_line_id' => $row->budget_line_id, 'kind' => 'spend', 'amount' => $row->amount, 'state' => 'approved', 'created_by' => $actor, 'approved_by' => $row->approved_by, 'created_at' => now(), 'updated_at' => now()]);
                    if (isset($invoice) && $invoice) {
                        DB::table('finance_invoice_payments')->insert(['invoice_id' => $invoice->id, 'transaction_id' => $row->id, 'amount' => $row->amount, 'created_at' => now(), 'updated_at' => now()]);
                        if ($used + FinanceMoney::cents((string) $row->amount) === FinanceMoney::cents((string) $invoice->amount)) {
                            DB::table('finance_purchase_invoices')->where('id', $invoice->id)->update(['state' => 'paid', 'updated_at' => now()]);
                        }
                    }
                }
                // Fail-closed for cash overdraft. Only same currency of treasury allowed by construction.
                $balance = DB::table('finance_transactions')->where('treasury_id', $row->treasury_id)->where('state', 'executed')->selectRaw("COALESCE(SUM(CASE WHEN kind='receipt' THEN amount ELSE -amount END),0) AS balance")->value('balance');
                if ($row->kind === 'payment' && FinanceMoney::cents((string) $row->amount) > FinanceMoney::cents((string) $balance)) {
                    throw ValidationException::withMessages(['treasury' => 'الرصيد النقدي غير كافٍ']);
                }
            }
            if ($action === 'execute' && $row->reversal_of_id) {
                app(FinanceSettlementService::class)->reverse($row, (int) $actor);
            }
            $updates = ['state' => $to, 'updated_at' => now()];
            $cols = ['review' => 'reviewed_by', 'approve' => 'approved_by', 'execute' => 'executed_by'];
            if (isset($cols[$action])) {
                $updates[$cols[$action]] = $actor;
            }
            if ($action === 'execute') {
                $updates['executed_at'] = now();
            }
            DB::table('finance_transactions')->where('id', $id)->update($updates);
            $this->guard->audit('transaction', $id, $actor, $action);
        });

        return response()->json(['ok' => true]);
    }

    public function records(Request $request, string $module)
    {
        $map = ['transactions' => 'finance_transactions', 'purchases' => 'finance_purchase_requests', 'stock' => 'finance_stock_movements', 'advances' => 'finance_advances', 'payroll' => 'finance_payroll_batches', 'reconciliations' => 'finance_reconciliations', 'receivables' => 'finance_receivables', 'transfers' => 'finance_internal_transfers', 'counts' => 'finance_inventory_counts', 'invoices' => 'finance_purchase_invoices', 'orders' => 'finance_purchase_orders', 'quotes' => 'finance_purchase_quotes', 'fund_movements' => 'finance_restricted_movements', 'budget_exceptions' => 'finance_budget_exceptions', 'budget_transfers' => 'finance_budget_transfers', 'cheques' => 'finance_cheques', 'purchase_receipts' => 'finance_purchase_receipts', 'allocations' => 'finance_payment_allocations', 'payroll_items' => 'finance_payroll_items', 'advance_settlements' => 'finance_advance_settlements', 'invoice_payments' => 'finance_invoice_payments'];
        abort_unless(isset($map[$module]), 404);
        $this->guard->assert($request->user(), 'finance.view');
        // finance.view alone NEVER discloses payroll, banks, personal payees, or restricted funds.
        $scopes = [
            'transactions' => ['finance.payment.draft', 'finance.payment.review', 'finance.payment.approve', 'finance.payment.execute',
                'finance.receipt.draft', 'finance.receipt.review', 'finance.receipt.approve', 'finance.receipt.execute'],
            'purchases' => ['finance.procurement.manage', 'finance.procurement.review'],
            // The storekeeper receives against orders and the accountant invoices them, so both see the orders.
            'orders' => ['finance.procurement.manage', 'finance.procurement.review', 'finance.purchase.receive', 'finance.purchase.invoice'],
            'quotes' => ['finance.procurement.manage', 'finance.procurement.review'],
            'purchase_receipts' => ['finance.procurement.manage', 'finance.procurement.review', 'finance.purchase.receive', 'finance.purchase.invoice'],
            'invoices' => ['finance.purchase.invoice', 'finance.procurement.review', 'finance.payment.record', 'finance.payment.draft'],
            'invoice_payments' => ['finance.purchase.invoice'],
            'stock' => ['finance.stock.receive', 'finance.stock.issue', 'finance.warehouse.manage'],
            'counts' => ['finance.stock.count', 'finance.stock.count.approve'],
            'advances' => ['finance.advance.manage'],
            'advance_settlements' => ['finance.advance.manage'],
            'payroll' => ['finance.payroll.manage', 'finance.payroll.approve'],
            'payroll_items' => ['finance.payroll.manage', 'finance.payroll.approve'],
            'reconciliations' => ['finance.reconcile'],
            'receivables' => ['finance.receivable.manage'],
            'transfers' => ['finance.transfer.execute', 'finance.transfer.approve'],
            'fund_movements' => ['finance.fund.manage', 'finance.fund.approve'],
            'budget_exceptions' => ['finance.budget.manage', 'finance.budget.transfer', 'finance.budget.approve'],
            'budget_transfers' => ['finance.budget.manage', 'finance.budget.transfer', 'finance.budget.approve'],
            'cheques' => ['finance.cheque.manage'],
            'allocations' => ['finance.receivable.manage', 'finance.receipt.execute'],
        ];
        // The head's monitoring reads every unit's records except individual salary lines.
        $readers = $module === 'payroll_items' ? $scopes[$module] : [...($scopes[$module] ?? []), 'finance.monitor'];
        abort_unless(collect($readers)->contains(fn ($permission) => $this->guard->allows($request->user(), $permission)), 403);
        $query = DB::table($map[$module]);
        if ($module === 'transactions') {
            $data = $request->validate(['kind' => 'nullable|in:payment,receipt']);
            $kinds = array_values(array_filter(['payment', 'receipt'], fn (string $kind): bool => $this->guard->canViewTransactions($request->user(), $kind)));
            if (! empty($data['kind'])) {
                abort_unless(in_array($data['kind'], $kinds, true), 403, 'لا تملك صلاحية الاطلاع على هذا النوع من السندات');
                $kinds = [$data['kind']];
            }
            $query->whereIn('kind', $kinds);
        }
        // Outside procurement and the head's monitoring, purchase orders are a hand-off only: the storekeeper sees
        // the orders awaiting receipt, the accountant those awaiting the supplier's invoice (owner report 2026-11-01).
        if ($module === 'orders' && ! collect(['finance.procurement.manage', 'finance.procurement.review', 'finance.monitor'])
            ->contains(fn ($permission) => $this->guard->allows($request->user(), $permission))) {
            $states = [
                ...($this->guard->allows($request->user(), 'finance.purchase.receive') ? ['issued', 'partial_received'] : []),
                ...($this->guard->allows($request->user(), 'finance.purchase.invoice') ? ['partial_received', 'received'] : []),
            ];
            $query->whereIn('state', array_values(array_unique($states)));
        }

        return response()->json($query->orderByDesc('id')->paginate(40));
    }
}
