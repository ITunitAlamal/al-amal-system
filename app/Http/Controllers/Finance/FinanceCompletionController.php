<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceApprovalQueue;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney as M;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\FinanceReports;
use App\Services\Finance\FinanceSettlementService;
use App\Services\Finance\FinanceSod;
use App\Services\Finance\FinanceSourceBridge;
use App\Services\Finance\StockExact as S;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Finance actions not provided by the original skeleton.  No external module is invented. */
final class FinanceCompletionController extends Controller
{
    public function __construct(private readonly FinanceGuard $guard, private readonly FinanceOperations $ops) {}

    private function require(Request $r, string $permission): int
    {
        $this->guard->assert($r->user(), $permission);

        return (int) $r->user()->id;
    }

    private function fail(string $key, string $why): never
    {
        throw ValidationException::withMessages([$key => $why]);
    }

    private function cents(string|int $amount): int
    {
        return M::cents($amount);
    }

    private function stamp(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    private function paid(int $treasury): int
    {
        $rows = DB::table('finance_transactions')->where('treasury_id', $treasury)->where('state', 'executed')->get(['kind', 'amount']);
        $sum = 0;
        foreach ($rows as $r) {
            $sum += ($r->kind === 'receipt' ? 1 : -1) * $this->cents((string) $r->amount);
        }

        return $sum;
    }

    public function bootstrap(Request $r)
    {
        $this->require($r, 'finance.view');
        $tables = ['treasuries' => 'finance_treasuries', 'budgets' => 'finance_budget_lines', 'suppliers' => 'finance_suppliers', 'warehouses' => 'finance_warehouses', 'items' => 'finance_stock_items', 'funds' => 'finance_restricted_funds', 'transactions' => 'finance_transactions', 'purchases' => 'finance_purchase_requests', 'advances' => 'finance_advances', 'payroll' => 'finance_payroll_batches', 'receivables' => 'finance_receivables', 'transfers' => 'finance_internal_transfers', 'reconciliations' => 'finance_reconciliations', 'counts' => 'finance_inventory_counts', 'invoices' => 'finance_purchase_invoices'];
        $visibility = [
            'treasuries' => ['finance.treasury.manage', 'finance.payment.draft', 'finance.payment.execute', 'finance.receipt.draft', 'finance.receipt.execute', 'finance.transfer.execute'],
            'budgets' => ['finance.budget.manage', 'finance.budget.transfer', 'finance.procurement.manage', 'finance.payment.draft'],
            'suppliers' => ['finance.procurement.manage', 'finance.procurement.review'],
            'warehouses' => ['finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue'],
            'items' => ['finance.warehouse.manage', 'finance.stock.receive', 'finance.stock.issue'],
            'funds' => ['finance.fund.manage', 'finance.fund.approve'],
            'transactions' => ['finance.payment.draft', 'finance.payment.review', 'finance.payment.approve', 'finance.payment.execute',
                'finance.receipt.draft', 'finance.receipt.review', 'finance.receipt.approve', 'finance.receipt.execute'],
            'purchases' => ['finance.procurement.manage', 'finance.procurement.review'],
            'advances' => ['finance.advance.manage'],
            'payroll' => ['finance.payroll.manage', 'finance.payroll.approve'],
            'receivables' => ['finance.receivable.manage'],
            'transfers' => ['finance.transfer.execute', 'finance.transfer.approve'],
            'reconciliations' => ['finance.reconcile'],
            'counts' => ['finance.stock.count', 'finance.stock.count.approve'],
            'invoices' => ['finance.purchase.invoice', 'finance.procurement.review', 'finance.payment.record', 'finance.payment.draft'],
        ];
        // In the documentary cycle the books (funds, budgets, vouchers, payroll...) are kept in the external software.
        $hidden = $this->guard->documentaryMode() ? ['treasuries', 'budgets', 'funds', 'transactions', 'advances', 'payroll', 'receivables', 'transfers', 'reconciliations'] : [];
        $can = function (string $name) use ($visibility, $r, $hidden): bool {
            if (in_array($name, $hidden, true)) {
                return false;
            }
            foreach ([...($visibility[$name] ?? []), 'finance.monitor'] as $p) {
                if ($this->guard->allows($r->user(), $p)) {
                    return true;
                }
            }

            return false;
        };
        $summary = [];
        foreach ($tables as $k => $t) {
            if ($can($k)) {
                $query = DB::table($t);
                if ($k === 'transactions') {
                    $kinds = array_values(array_filter(['payment', 'receipt'], fn (string $kind): bool => $this->guard->canViewTransactions($r->user(), $kind)));
                    $query->whereIn('kind', $kinds);
                    foreach ($kinds as $kind) {
                        $summary[$kind === 'payment' ? 'payments' : 'receipts'] = (clone $query)->where('kind', $kind)->count();
                    }
                }
                $summary[$k] = $query->count();
            }
        }
        $lookups = [];
        foreach (['departments' => 'departments', 'currencies' => 'currencies', 'treasuries' => 'finance_treasuries', 'warehouses' => 'finance_warehouses', 'stock_items' => 'finance_stock_items', 'budgets' => 'finance_budget_lines', 'suppliers' => 'finance_suppliers', 'employees' => 'employees', 'users' => 'users', 'funds' => 'finance_restricted_funds'] as $k => $t) {
            $cols = match ($k) {
                'currencies' => ['code', 'name_ar'],'departments' => ['id', 'name'],'employees' => ['id', 'employee_number', 'first_name_ar', 'family_name_ar'],'users' => ['id', 'name'],'stock_items' => ['id', 'name', 'sku'],'budgets' => ['id', 'title', 'currency'],'funds' => ['id', 'name', 'currency'],default => ['id', 'name']
            };
            $allowedLookup = match ($k) {
                'employees' => $this->guard->allows($r->user(), 'finance.payroll.manage'),
                'users' => $this->guard->allows($r->user(), 'finance.advance.manage') || $this->guard->allows($r->user(), 'finance.payroll.manage'),
                'treasuries' => $can('treasuries'), 'budgets' => $can('budgets'), 'suppliers' => $can('suppliers'),
                'warehouses' => $can('warehouses'), 'stock_items' => $can('items'), 'funds' => $can('funds'),
                default => true,
            };
            $lookups[$k] = $allowedLookup ? DB::table($t)->select($cols)->limit(300)->get() : [];
        }
        $allowed = $this->guard->uiPermissions($r->user());
        $alerts = [];
        if ($can('purchases')) {
            foreach (['pending_finance_head' => 'طلبات تنتظر رئيس الحسابات', 'pending_director' => 'طلبات تنتظر المدير التنفيذي', 'pending_board_treasurer' => 'طلبات تنتظر أمين الصندوق'] as $state => $label) {
                $count = DB::table('finance_purchase_requests')->where('state', $state)->count();
                if ($count) {
                    $alerts[] = ['title' => $label, 'count' => $count];
                }
            }
        }
        $pendingCheques = $this->guard->allows($r->user(), 'finance.cheque.manage') ? DB::table('finance_cheques')->where('state', 'received')->whereDate('due_on', '<', now()->toDateString())->count() : 0;
        if ($pendingCheques) {
            $alerts[] = ['title' => 'شيكات تجاوزت موعد التحصيل', 'count' => $pendingCheques];
        }
        if ($can('items') || $this->guard->allows($r->user(), 'finance.stock.count')) {
            $balances = DB::table('finance_stock_movements')->groupBy('stock_item_id')
                ->select('stock_item_id', DB::raw("SUM(CASE WHEN kind IN ('receipt','return_in','adjust_in') THEN quantity ELSE -quantity END) as balance"));
            $low = DB::table('finance_stock_items as i')->leftJoinSub($balances, 'b', 'b.stock_item_id', '=', 'i.id')->where('i.is_active', true)
                ->where('i.minimum_qty', '>', 0)->whereRaw('COALESCE(b.balance, 0) < i.minimum_qty')->count();
            if ($low) {
                $alerts[] = ['title' => 'أصناف تحت الحد الأدنى في المخازن', 'count' => $low];
            }
        }
        // Cancelled receivables are not arrears; what stays open counts adjustments and, for rent, the rentals ledger.
        $due = $can('receivables') ? DB::table('finance_receivables')->whereDate('due_on', '<', now()->toDateString())->whereNull('cancelled_at')->limit(200)
            ->get(['id', 'amount', 'cancelled_at', 'source_module', 'source_reference']) : collect();
        $unpaid = 0;
        $bridge = app(FinanceSourceBridge::class);
        foreach ($due as $dueRow) {
            if ($bridge->openCents($dueRow) > 0) {
                $unpaid++;
            }
        }
        if ($unpaid) {
            $alerts[] = ['title' => 'مستحقات متأخرة غير مسددة', 'count' => $unpaid];
        }
        if ($this->guard->allows($r->user(), 'finance.warehouse.manage')) {
            $low = 0;
            $warehouses = DB::table('finance_warehouses')->where('is_active', true)->limit(10)->pluck('id');
            foreach ($warehouses as $wh) {
                foreach (DB::table('finance_stock_items')->where('is_active', true)->where('minimum_qty', '>', 0)->limit(100)->get(['id', 'minimum_qty']) as $item) {
                    if (S::balance($wh, $item->id)['qty'] < S::scaled((string) $item->minimum_qty, 3)) {
                        $low++;
                    }
                }
            }
            if ($low) {
                $alerts[] = ['title' => 'أصناف دون الحد الأدنى (عينة حتى 100 صنف لكل مخزن)', 'count' => $low];
            }
        }

        $mode = ['documentary' => $this->guard->documentaryMode(), 'external_system' => $this->guard->externalSystemName()];

        return response()->json(compact('summary', 'lookups', 'allowed', 'alerts', 'mode'));
    }

    public function receivable(Request $r)
    {
        $actor = $this->require($r, 'finance.receivable.manage');
        $d = $r->validate(['reference' => 'required|string|max:100', 'party' => 'required|string|max:255', 'currency' => 'required|exists:currencies,code', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'due_on' => 'required|date', 'kind' => 'required|in:rent,service,donation,sponsorship,grant,other', 'description' => 'nullable|string|max:2000']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'المبلغ يجب أن يكون موجبًا');
        }

        return DB::transaction(function () use ($d, $actor) {
            $id = DB::table('finance_receivables')->insertGetId($d + ['source_module' => 'manual', 'created_by' => $actor] + $this->stamp());
            $this->guard->audit('receivable', $id, $actor, 'create');

            return response()->json(['id' => $id], 201);
        });
    }

    public function budgetTransfer(Request $r)
    {
        $actor = $this->require($r, 'finance.budget.transfer');
        $d = $r->validate(['from_line_id' => 'required|integer|exists:finance_budget_lines,id|different:to_line_id', 'to_line_id' => 'required|integer|exists:finance_budget_lines,id', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'reason' => 'required|string|min:6|max:2000']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'قيمة المناقلة غير صالحة');
        }

        return DB::transaction(function () use ($actor, $d) {
            $id = DB::table('finance_budget_transfers')->insertGetId($d + ['created_by' => $actor] + $this->stamp());
            $this->guard->audit('budget_transfer', $id, $actor, 'request');

            return response()->json(['id' => $id], 201);
        });
    }

    public function approveBudgetTransfer(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.budget.approve');

        return DB::transaction(function () use ($actor, $id) {
            $tx = DB::table('finance_budget_transfers')->lockForUpdate()->find($id);
            abort_unless($tx, 404);
            if ($tx->state !== 'pending' || $tx->created_by === $actor) {
                $this->fail('approval', 'المناقلة غير قابلة للاعتماد أو توجد مخالفة فصل مهام');
            }
            $ids = [(int) $tx->from_line_id, (int) $tx->to_line_id];
            sort($ids);
            $lines = DB::table('finance_budget_lines')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $a = $lines[$tx->from_line_id];
            $b = $lines[$tx->to_line_id];
            if ($a->currency !== $b->currency || $a->fiscal_year !== $b->fiscal_year) {
                $this->fail('budget', 'لا يسمح بالمناقلة بين عملتين أو سنتين مختلفتين');
            }
            if ($this->cents($this->ops->budgetAvailable($a->id)) < $this->cents((string) $tx->amount)) {
                $this->fail('budget', 'رصيد البند المحوّل منه غير كاف');
            }
            foreach ([[$a->id, '-'.(string) $tx->amount], [$b->id, (string) $tx->amount]] as [$line,$amount]) {
                DB::table('finance_budget_movements')->insert(['budget_line_id' => $line, 'kind' => 'adjustment', 'amount' => $amount, 'state' => 'approved', 'reason' => 'مناقلة '.$id, 'created_by' => $tx->created_by, 'approved_by' => $actor] + $this->stamp());
            }
            DB::table('finance_budget_transfers')->where('id', $id)->update(['state' => 'approved', 'approved_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('budget_transfer', $id, $actor, 'approve');

            return response()->json(['ok' => true]);
        });
    }

    public function createTransfer(Request $r)
    {
        $actor = $this->require($r, 'finance.transfer.execute');
        $d = $r->validate(['from_id' => 'required|integer|exists:finance_treasuries,id|different:to_id', 'to_id' => 'required|integer|exists:finance_treasuries,id', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'reference' => 'required|string|max:80']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'القيمة غير صالحة');
        }

        return DB::transaction(function () use ($d, $actor) {
            $ids = [$d['from_id'], $d['to_id']];
            sort($ids);
            $accounts = DB::table('finance_treasuries')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($accounts->count() !== 2 || ! $accounts[$d['from_id']]->is_active || ! $accounts[$d['to_id']]->is_active || $accounts[$d['from_id']]->currency !== $accounts[$d['to_id']]->currency) {
                $this->fail('currency', 'الصناديق يجب أن تكون فعالة وبالعملة نفسها');
            }
            $id = DB::table('finance_internal_transfers')->insertGetId(['reference' => $d['reference'], 'from_treasury_id' => $d['from_id'], 'to_treasury_id' => $d['to_id'], 'amount' => $d['amount'], 'currency' => $accounts[$d['from_id']]->currency, 'state' => 'pending_approval', 'executed_by' => $actor] + $this->stamp());
            $this->guard->audit('transfer', $id, $actor, 'request');

            return response()->json(['id' => $id], 201);
        });
    }

    public function approveTransfer(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.transfer.approve');

        return DB::transaction(function () use ($id, $actor) {
            $t = DB::table('finance_internal_transfers')->lockForUpdate()->find($id);
            abort_unless($t, 404);
            if ($t->state !== 'pending_approval' || $t->executed_by === $actor) {
                $this->fail('approval', 'تحويل غير صالح أو اعتماد ذاتي');
            }DB::table('finance_internal_transfers')->where('id', $id)->update(['state' => 'approved', 'approved_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('transfer', $id, $actor, 'approve');

            return response()->json(['ok' => true]);
        });
    }

    public function executeTransfer(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.transfer.execute');

        return DB::transaction(function () use ($actor, $id) {
            $t = DB::table('finance_internal_transfers')->lockForUpdate()->find($id);
            abort_unless($t, 404);
            if ($t->state !== 'approved' || $t->executed_by !== $actor || $t->approved_by === $actor) {
                $this->fail('transfer', 'التحويل غير معتمد أو المنفذ مختلف عن مقدم الطلب');
            }
            $ids = [(int) $t->from_treasury_id, (int) $t->to_treasury_id];
            sort($ids);
            DB::table('finance_treasuries')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($this->paid($t->from_treasury_id) < $this->cents((string) $t->amount)) {
                $this->fail('balance', 'رصيد المصدر غير كاف');
            }
            $pair = [];
            foreach ([['payment', $t->from_treasury_id], ['receipt', $t->to_treasury_id]] as [$kind,$account]) {
                $pair[] = DB::table('finance_transactions')->insertGetId(['document_no' => 'TR-'.strtoupper(bin2hex(random_bytes(8))), 'kind' => $kind, 'state' => 'executed', 'treasury_id' => $account, 'amount' => $t->amount, 'currency' => $t->currency, 'effective_on' => now()->toDateString(), 'description' => 'تحويل داخلي '.$t->reference, 'created_by' => $actor, 'approved_by' => $t->approved_by, 'executed_by' => $actor, 'executed_at' => now()] + $this->stamp());
            }
            DB::table('finance_internal_transfers')->where('id', $id)->update(['out_transaction_id' => $pair[0], 'in_transaction_id' => $pair[1], 'state' => 'executed', 'updated_at' => now()]);
            $this->guard->audit('transfer', $id, $actor, 'execute', ['transactions' => $pair]);

            return response()->json(['transactions' => $pair]);
        });
    }

    public function stockCount(Request $r)
    {
        $actor = $this->require($r, 'finance.stock.count');
        $d = $r->validate(['warehouse_id' => 'required|exists:finance_warehouses,id', 'stock_item_id' => 'required|exists:finance_stock_items,id', 'physical_qty' => 'required|regex:/^\d{1,9}(\.\d{1,3})?$/', 'reason' => 'required|string|min:5|max:1000']);

        return DB::transaction(function () use ($d, $actor) {
            DB::table('finance_warehouses')->where('id', $d['warehouse_id'])->lockForUpdate()->first();
            $recorded = S::balance($d['warehouse_id'], $d['stock_item_id'])['qty'];
            $id = DB::table('finance_inventory_counts')->insertGetId($d + ['recorded_qty' => S::decimal($recorded, 3), 'counted_by' => $actor] + $this->stamp());
            $this->guard->audit('inventory_count', $id, $actor, 'submit');

            return response()->json(['id' => $id, 'recorded_qty' => S::decimal($recorded, 3)], 201);
        });
    }

    public function approveStockCount(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.stock.count.approve');

        return DB::transaction(function () use ($id, $actor) {
            $count = DB::table('finance_inventory_counts')->lockForUpdate()->find($id);
            abort_unless($count, 404);
            if ($count->state !== 'pending' || $count->counted_by === $actor) {
                $this->fail('count', 'الجرد ليس قيد الاعتماد أو يتعارض مع فصل المهام');
            }
            DB::table('finance_warehouses')->where('id', $count->warehouse_id)->lockForUpdate()->first();
            $cur = S::balance($count->warehouse_id, $count->stock_item_id);
            if ($cur['qty'] !== S::scaled((string) $count->recorded_qty, 3)) {
                $this->fail('count', 'تغيّر الرصيد بعد الجرد، أعد الجرد');
            }$delta = S::scaled((string) $count->physical_qty, 3) - $cur['qty'];
            if ($delta !== 0) {
                S::movement(['warehouse_id' => $count->warehouse_id, 'stock_item_id' => $count->stock_item_id, 'kind' => $delta > 0 ? 'adjust_in' : 'adjust_out', 'quantity' => S::decimal(abs($delta), 3), 'unit_cost' => S::decimal($cur['average'], 4), 'reference_type' => 'inventory_count', 'reference_id' => (string) $id, 'note' => $count->reason], $actor);
            }
            DB::table('finance_inventory_counts')->where('id', $id)->update(['state' => 'approved', 'approved_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('inventory_count', $id, $actor, 'approve', ['difference' => S::decimal(abs($delta), 3)]);

            return response()->json(['ok' => true]);
        });
    }

    public function purchaseReceipt(Request $r, int $orderId)
    {
        $actor = $this->require($r, 'finance.purchase.receive');
        $d = $r->validate(['notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1', 'items.*.purchase_item_id' => 'required|exists:finance_purchase_items,id', 'items.*.stock_item_id' => 'required|exists:finance_stock_items,id', 'items.*.warehouse_id' => 'required|exists:finance_warehouses,id', 'items.*.quantity' => 'required|regex:/^\d{1,9}(\.\d{1,3})?$/', 'items.*.unit_cost' => 'required|regex:/^\d{1,9}(\.\d{1,4})?$/',
            // SRS 3.7.2 «تسجيل المقبول والمرفوض والنقص»: quantity is what is accepted into stock; a rejected quantity
            // is documented with its reason and never enters stock (ACC-15).
            'items.*.rejected_qty' => 'nullable|regex:/^\d{1,9}(\.\d{1,3})?$/', 'items.*.rejection_reason' => 'nullable|string|max:500'],
            [], ['items.*.rejected_qty' => 'الكمية المرفوضة', 'items.*.rejection_reason' => 'سبب الرفض']);
        $rejectionColumns = Schema::hasColumn('finance_purchase_receipt_items', 'rejected_qty');
        foreach ($d['items'] as $index => $i) {
            $rejected = S::scaled((string) ($i['rejected_qty'] ?? '0') ?: '0', 3);
            if ($rejected > 0 && ! $rejectionColumns) {
                $this->fail('items', 'تسجيل الكمية المرفوضة غير متاح قبل تحديث قاعدة البيانات.');
            }
            if ($rejected > 0 && mb_strlen(trim((string) ($i['rejection_reason'] ?? ''))) < 3) {
                $this->fail("items.$index.rejection_reason", 'اذكر سبب رفض الكمية المرفوضة.');
            }
            if ($rejected === 0 && S::scaled((string) $i['quantity'], 3) === 0) {
                $this->fail("items.$index.quantity", 'سجّل كمية مقبولة أو مرفوضة للبند.');
            }
        }
        // Not the order's issuer, the request's creator or the employee who asked for it from «طلباتي».
        $sod = app(FinanceSod::class);
        $sod->assertDistinct($actor, $sod->orderOrigin($orderId), 'استلام أصناف الأمر', 'purchase_order', $orderId);

        return DB::transaction(function () use ($orderId, $actor, $d, $sod) {
            $order = DB::table('finance_purchase_orders')->lockForUpdate()->find($orderId);
            abort_unless($order, 404);
            if (! in_array($order->state, ['issued', 'partial_received'], true)) {
                $this->fail('order', 'الأمر غير قابل للاستلام');
            }
            $purchase = DB::table('finance_purchase_requests')->find($order->purchase_request_id);
            $sod->assertDistinct($actor, $sod->orderOrigin($orderId), 'استلام أصناف الأمر', 'purchase_order', $orderId, false);
            $seen = [];
            foreach ($d['items'] as $i) {
                $line = $i['purchase_item_id'];
                if (isset($seen[$line])) {
                    $this->fail('items', 'بند مكرر');
                }$seen[$line] = true;
                $source = DB::table('finance_purchase_items')->where('purchase_request_id', $purchase->id)->where('id', $line)->first();
                if (! $source) {
                    $this->fail('items', 'الصنف لا يخص أمر الشراء');
                }if (S::scaled((string) $i['unit_cost'], 4) > S::scaled((string) $source->unit_price, 4)) {
                    $this->fail('unit_cost', 'تكلفة الوحدة المستلمة تتجاوز سعر طلب الشراء المعتمد');
                }$already = DB::table('finance_purchase_receipt_items as x')->join('finance_purchase_receipts as y', 'x.receipt_id', '=', 'y.id')->where('y.order_id', $orderId)->where('x.purchase_item_id', $line)->sum('x.quantity');
                if (S::scaled((string) $already, 3) + S::scaled($i['quantity'], 3) > S::scaled((string) $source->quantity, 3)) {
                    $this->fail('quantity', 'الاستلام يتجاوز الكمية المطلوبة');
                }
                // A purchase line keeps the stock item its first accepted delivery entered (ACC-15): a later partial
                // delivery of the same line cannot land on another item.
                $mapped = DB::table('finance_purchase_receipt_items as x')->join('finance_purchase_receipts as y', 'x.receipt_id', '=', 'y.id')
                    ->where('y.order_id', $orderId)->where('x.purchase_item_id', $line)->where('x.quantity', '>', 0)->orderBy('x.id')->value('x.stock_item_id');
                if ($mapped !== null && (int) $mapped !== (int) $i['stock_item_id']) {
                    $this->fail('items', 'بند الشراء «'.$source->item_name.'» استُلم من قبل على صنف مخزني آخر؛ استلم الدفعة على الصنف نفسه.');
                }
            }
            $accepted = collect($d['items'])->sum(fn (array $i): int => S::scaled((string) $i['quantity'], 3));
            $rejectedAny = collect($d['items'])->contains(fn (array $i): bool => S::scaled((string) ($i['rejected_qty'] ?? '0') ?: '0', 3) > 0);
            $id = DB::table('finance_purchase_receipts')->insertGetId(['order_id' => $orderId, 'number' => 'GRN-'.strtoupper(bin2hex(random_bytes(8))), 'state' => $accepted === 0 ? 'rejected' : 'received', 'notes' => $d['notes'] ?? null, 'received_by' => $actor] + $this->stamp());
            foreach ($d['items'] as $i) {
                $rejected = S::scaled((string) ($i['rejected_qty'] ?? '0') ?: '0', 3);
                $row = ['purchase_item_id' => $i['purchase_item_id'], 'warehouse_id' => $i['warehouse_id'], 'stock_item_id' => $i['stock_item_id'],
                    'quantity' => S::decimal(S::scaled((string) $i['quantity'], 3), 3), 'unit_cost' => $i['unit_cost']];
                if ($rejected > 0) {
                    $row += ['rejected_qty' => S::decimal($rejected, 3), 'rejection_reason' => trim((string) $i['rejection_reason'])];
                }
                DB::table('finance_purchase_receipt_items')->insert($row + ['receipt_id' => $id] + $this->stamp());
                if (S::scaled((string) $i['quantity'], 3) > 0) {
                    S::movement(['warehouse_id' => $i['warehouse_id'], 'stock_item_id' => $i['stock_item_id'], 'kind' => 'receipt', 'quantity' => $i['quantity'], 'unit_cost' => $i['unit_cost'], 'reference_type' => 'purchase_receipt', 'reference_id' => (string) $id, 'note' => 'استلام أمر شراء'], $actor);
                }
            }
            $pending = false;
            foreach (DB::table('finance_purchase_items')->where('purchase_request_id', $purchase->id)->get() as $line) {
                $sum = DB::table('finance_purchase_receipt_items as x')->join('finance_purchase_receipts as y', 'x.receipt_id', '=', 'y.id')->where('y.order_id', $orderId)->where('x.purchase_item_id', $line->id)->sum('x.quantity');
                if (S::scaled((string) $sum, 3) < S::scaled((string) $line->quantity, 3)) {
                    $pending = true;
                }
            }
            // A delivery rejected in full documents the rejection and leaves an order with nothing accepted as issued.
            $acceptedSoFar = (float) DB::table('finance_purchase_receipt_items as x')->join('finance_purchase_receipts as y', 'x.receipt_id', '=', 'y.id')->where('y.order_id', $orderId)->sum('x.quantity') > 0;
            DB::table('finance_purchase_orders')->where('id', $orderId)->update(['state' => $pending ? ($acceptedSoFar ? 'partial_received' : $order->state) : 'received', 'updated_at' => now()]);
            $this->guard->audit('goods_receipt', $id, $actor, $rejectedAny ? 'receive_with_rejection' : 'receive');

            return response()->json(['id' => $id, 'partial' => $pending], 201);
        });
    }

    public function invoice(Request $r, int $orderId)
    {
        $actor = $this->require($r, 'finance.purchase.invoice');
        $d = $r->validate(['invoice_no' => 'required|string|max:80', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'invoice_on' => 'required|date']);
        $d['invoice_no'] = trim($d['invoice_no']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'قيمة الفاتورة غير صالحة');
        }
        $sod = app(FinanceSod::class);
        $sod->assertDistinct($actor, $sod->orderActors($orderId), 'تسجيل فاتورة المورد', 'purchase_order', $orderId);

        return DB::transaction(function () use ($d, $orderId, $actor, $sod) {
            $order = DB::table('finance_purchase_orders')->lockForUpdate()->find($orderId);
            abort_unless($order, 404);
            $sod->assertDistinct($actor, $sod->orderActors($orderId), 'تسجيل فاتورة المورد', 'purchase_order', $orderId, false);
            if (! in_array($order->state, ['partial_received', 'received'], true)) {
                $this->fail('order', 'لم يتم استلام مواد الأمر بعد');
            }
            // A supplier's invoice is recorded once, whatever order it is entered on; the supplier row serializes
            // concurrent entries (the database trigger of 2026_11_30_000027 refuses it too).
            DB::table('finance_suppliers')->where('id', $order->supplier_id)->lockForUpdate()->first();
            $duplicate = DB::table('finance_purchase_invoices as i')->join('finance_purchase_orders as o', 'o.id', '=', 'i.order_id')
                ->where('o.supplier_id', $order->supplier_id)->whereRaw('lower(btrim(i.invoice_no)) = lower(btrim(?))', [$d['invoice_no']])->value('o.number');
            if ($duplicate !== null) {
                $this->fail('invoice_no', 'فاتورة المورد رقم «'.$d['invoice_no'].'» مسجلة من قبل على أمر الشراء '.$duplicate.'؛ لا تُسجَّل الفاتورة نفسها مرتين.');
            }
            $receivedValue = 0;
            $lines = DB::table('finance_purchase_receipt_items as ri')->join('finance_purchase_receipts as gr', 'gr.id', '=', 'ri.receipt_id')->where('gr.order_id', $orderId)
                ->get(['ri.receipt_id', 'ri.warehouse_id', 'ri.stock_item_id', 'ri.quantity', 'ri.unit_cost']);
            foreach ($lines as $ri) {
                $receivedValue += M::lineCents((string) $ri->quantity, (string) $ri->unit_cost);
            }
            // Goods sent back to the supplier against this order's receipts are not invoiced (ACC-15): each return is
            // valued at the unit cost its receipt line recorded.
            $returns = DB::table('finance_stock_movements')->where('kind', 'return_out')->where('reference_type', 'purchase_receipt')
                ->whereIn('reference_id', $lines->pluck('receipt_id')->unique()->map(fn ($v): string => (string) $v)->values()->all())
                ->get(['reference_id', 'warehouse_id', 'stock_item_id', 'quantity']);
            foreach ($returns as $back) {
                $source = $lines->first(fn (object $ri): bool => (string) $ri->receipt_id === (string) $back->reference_id
                    && (int) $ri->warehouse_id === (int) $back->warehouse_id && (int) $ri->stock_item_id === (int) $back->stock_item_id);
                if ($source) {
                    $receivedValue -= M::lineCents((string) $back->quantity, (string) $source->unit_cost);
                }
            }
            $existing = DB::table('finance_purchase_invoices')->where('order_id', $orderId)->get();
            $total = 0;
            foreach ($existing as $v) {
                $total += $this->cents((string) $v->amount);
            }
            if ($total + $this->cents($d['amount']) > $receivedValue) {
                $this->fail('amount', 'قيمة الفواتير تتجاوز قيمة البضاعة المستلمة فعليًا');
            }
            if ($total + $this->cents($d['amount']) > $this->cents((string) $order->amount)) {
                $this->fail('amount', 'إجمالي الفواتير يتجاوز أمر الشراء');
            }$purchase = DB::table('finance_purchase_requests')->find($order->purchase_request_id);
            $id = DB::table('finance_purchase_invoices')->insertGetId($d + ['order_id' => $orderId, 'currency' => $purchase->currency, 'created_by' => $actor] + $this->stamp());
            $this->guard->audit('invoice', $id, $actor, 'create');

            return response()->json(['id' => $id], 201);
        });
    }

    public function payrollBatch(Request $r)
    {
        $actor = $this->require($r, 'finance.payroll.manage');
        $d = $r->validate(['period' => 'required|date_format:Y-m', 'currency' => 'required|exists:currencies,code', 'items' => 'required|array|min:1', 'items.*.employee_id' => 'required|exists:employees,id', 'items.*.gross' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'items.*.deductions' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'hr_approval_reference' => 'required|string|max:150']);

        return DB::transaction(function () use ($d, $actor) {
            $seen = [];
            $total = 0;
            foreach ($d['items'] as $i) {
                if (isset($seen[$i['employee_id']])) {
                    $this->fail('items', 'موظف مكرر');
                }$seen[$i['employee_id']] = true;
                $net = $this->cents($i['gross']) - $this->cents($i['deductions']);
                if ($net < 0) {
                    $this->fail('deductions', 'الخصم يتجاوز الإجمالي');
                }$total += $net;
            }
            $id = DB::table('finance_payroll_batches')->insertGetId(['period' => $d['period'], 'currency' => $d['currency'], 'total' => M::format($total), 'state' => 'pending_approval', 'created_by' => $actor, 'hr_approval_reference' => $d['hr_approval_reference']] + $this->stamp());
            foreach ($d['items'] as $i) {
                DB::table('finance_payroll_items')->insert(['batch_id' => $id, 'employee_id' => $i['employee_id'], 'gross' => $i['gross'], 'deductions' => $i['deductions'], 'net' => M::format($this->cents($i['gross']) - $this->cents($i['deductions']))] + $this->stamp());
            }$this->guard->audit('payroll', $id, $actor, 'submit', ['hr_reference' => $d['hr_approval_reference']]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function approvePayroll(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.payroll.approve');

        return DB::transaction(function () use ($id, $actor) {
            $b = DB::table('finance_payroll_batches')->lockForUpdate()->find($id);
            abort_unless($b, 404);
            if ($b->state !== 'pending_approval' || $b->created_by === $actor) {
                $this->fail('payroll', 'المسير غير قابل للاعتماد أو يوجد تعارض');
            }DB::table('finance_payroll_batches')->where('id', $id)->update(['state' => 'approved', 'approved_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('payroll', $id, $actor, 'approve');

            return response()->json(['ok' => true]);
        });
    }

    public function payrollPay(Request $r, int $itemId)
    {
        $actor = $this->require($r, 'finance.payroll.manage');
        $d = $r->validate(['transaction_id' => 'required|exists:finance_transactions,id']);

        return DB::transaction(function () use ($d, $itemId, $actor) {
            $item = DB::table('finance_payroll_items')->lockForUpdate()->find($itemId);
            abort_unless($item, 404);
            $batch = DB::table('finance_payroll_batches')->find($item->batch_id);
            $tx = DB::table('finance_transactions')->where('id', $d['transaction_id'])->lockForUpdate()->first();
            if (! $batch || $batch->state !== 'approved' || $item->state !== 'pending' || ! $tx || $tx->state !== 'executed' || $tx->kind !== 'payment' || $tx->currency !== $batch->currency || $this->cents((string) $tx->amount) !== $this->cents((string) $item->net)) {
                $this->fail('payroll', 'يلزم سند صرف منفذ بقيمة صافي راتب الموظف وعملته');
            }
            app(FinanceSettlementService::class)->assertAvailable($tx, 'payroll');
            if (DB::table('finance_payroll_disbursements')->where('transaction_id', $tx->id)->exists()) {
                $this->fail('transaction', 'السند مستخدم في راتب آخر');
            }DB::table('finance_payroll_disbursements')->insert(['payroll_item_id' => $itemId, 'transaction_id' => $tx->id] + $this->stamp());
            DB::table('finance_payroll_items')->where('id', $itemId)->update(['state' => 'paid', 'updated_at' => now()]);
            $this->guard->audit('payroll_item', $itemId, $actor, 'pay');

            return response()->json(['ok' => true]);
        });
    }

    public function cheque(Request $r)
    {
        $actor = $this->require($r, 'finance.cheque.manage');
        $d = $r->validate(['transaction_id' => 'required|exists:finance_transactions,id', 'cheque_no' => 'required|string|max:80', 'bank_name' => 'required|string|max:255', 'due_on' => 'required|date']);

        return DB::transaction(function () use ($d, $actor) {
            $tx = DB::table('finance_transactions')->lockForUpdate()->find($d['transaction_id']);
            if (! $tx || $tx->kind !== 'receipt' || $tx->state !== 'draft') {
                $this->fail('cheque', 'يجب ربط شيك بسند قبض مسودة');
            }$id = DB::table('finance_cheques')->insertGetId($d + ['updated_by' => $actor] + $this->stamp());
            $this->guard->audit('cheque', $id, $actor, 'register');

            return response()->json(['id' => $id], 201);
        });
    }

    public function chequeStatus(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.cheque.manage');
        $d = $r->validate(['state' => 'required|in:cleared,returned']);

        return DB::transaction(function () use ($id, $actor, $d) {
            $c = DB::table('finance_cheques')->lockForUpdate()->find($id);
            abort_unless($c, 404);
            if ($c->state !== 'received') {
                $this->fail('cheque', 'حالة الشيك نهائية');
            }$tx = DB::table('finance_transactions')->find($c->transaction_id);
            if ($tx->state === 'executed') {
                $this->fail('cheque', 'لا يمكن تغيير حالة شيك محصّل');
            }DB::table('finance_cheques')->where('id', $id)->update(['state' => $d['state'], 'updated_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('cheque', $id, $actor, $d['state']);

            return response()->json(['ok' => true]);
        });
    }

    public function fundMovement(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.fund.manage');
        $d = $r->validate(['kind' => 'required|in:receive,commit,release,spend', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'reference' => 'required|string|max:120', 'reason' => 'nullable|string|max:2000']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'القيمة يجب أن تكون موجبة');
        }

        return DB::transaction(function () use ($id, $actor, $d) {
            abort_unless(DB::table('finance_restricted_funds')->where('id', $id)->exists(), 404);
            $key = DB::table('finance_restricted_movements')->insertGetId($d + ['fund_id' => $id, 'created_by' => $actor] + $this->stamp());
            $this->guard->audit('fund_movement', $key, $actor, 'request');

            return response()->json(['id' => $key], 201);
        });
    }

    public function fundApprove(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.fund.approve');

        return DB::transaction(function () use ($id, $actor) {
            $m = DB::table('finance_restricted_movements')->lockForUpdate()->find($id);
            abort_unless($m, 404);
            if ($m->state !== 'pending' || $m->created_by === $actor) {
                $this->fail('fund', 'الحركة غير قابلة للاعتماد');
            }$fund = DB::table('finance_restricted_funds')->where('id', $m->fund_id)->lockForUpdate()->first();
            $received = $this->cents((string) $fund->received);
            $committed = $this->cents((string) $fund->committed);
            $spent = $this->cents((string) $fund->spent);
            $amount = $this->cents((string) $m->amount);
            switch ($m->kind) {
                case 'receive':$received += $amount;
                    break;
                case 'commit':$committed += $amount;
                    break;
                case 'release':if ($committed < $amount) {
                    $this->fail('fund', 'الالتزامات غير كافية');
                }$committed -= $amount;
                    break;
                case 'spend':if ($committed < $amount) {
                    $this->fail('fund', 'الصرف يتطلب التزامًا معتمدًا');
                }$committed -= $amount;
                    $spent += $amount;
                    break;
            }
            if ($received < $spent + $committed) {
                $this->fail('fund', 'الرصيد المخصص لا يكفي');
            }DB::table('finance_restricted_funds')->where('id', $fund->id)->update(['received' => M::format($received), 'committed' => M::format($committed), 'spent' => M::format($spent), 'updated_at' => now()]);
            DB::table('finance_restricted_movements')->where('id', $id)->update(['state' => 'approved', 'approved_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('fund_movement', $id, $actor, 'approve');

            return response()->json(['ok' => true]);
        });
    }

    public function report(Request $r, string $module)
    {
        $actor = $this->require($r, 'finance.reports.view');
        $map = ['receipts' => ['finance_transactions', ['id', 'document_no', 'currency', 'amount', 'effective_on', 'counterparty', 'state'], 'kind', 'receipt'], 'payments' => ['finance_transactions', ['id', 'document_no', 'currency', 'amount', 'effective_on', 'counterparty', 'state'], 'kind', 'payment'], 'purchases' => ['finance_purchase_requests', ['id', 'number', 'currency', 'total_amount', 'state', 'created_at'], null, null], 'budgets' => ['finance_budget_lines', ['id', 'fiscal_year', 'department_id', 'title', 'currency', 'approved_amount', 'adjustment_amount'], null, null], 'stock' => ['finance_stock_movements', ['id', 'warehouse_id', 'stock_item_id', 'kind', 'quantity', 'unit_cost', 'created_at'], null, null], 'payroll' => ['finance_payroll_batches', ['id', 'period', 'currency', 'state', 'total'], null, null], 'advances' => ['finance_advances', ['id', 'user_id', 'currency', 'amount', 'state', 'due_on'], null, null], 'receivables' => ['finance_receivables', ['id', 'reference', 'party', 'currency', 'amount', 'due_on'], null, null],
            'orders' => ['finance_purchase_orders', ['id', 'number', 'purchase_request_id', 'supplier_id', 'amount', 'state', 'created_at'], null, null],
            'invoices' => ['finance_purchase_invoices', ['id', 'order_id', 'invoice_no', 'invoice_on', 'amount', 'currency', 'state', 'external_voucher_no', 'external_voucher_on', 'created_at'], null, null]];
        abort_unless(isset($map[$module]), 404);
        [$table,$cols,$filter,$value] = $map[$module];
        $restricted = ['payroll' => ['finance.payroll.manage'], 'stock' => ['finance.stock.receive', 'finance.stock.issue', 'finance.warehouse.manage'], 'advances' => ['finance.advance.manage'],
            'receivables' => ['finance.receivable.manage'], 'purchases' => ['finance.procurement.manage', 'finance.procurement.review'], 'budgets' => ['finance.budget.manage'],
            'orders' => ['finance.procurement.manage', 'finance.procurement.review', 'finance.purchase.receive'], 'invoices' => ['finance.purchase.invoice', 'finance.procurement.review', 'finance.payment.record'],
            // Vouchers belong to the books: in the documentary cycle they are kept in the external accounting software.
            'receipts' => ['finance.receipt.draft', 'finance.receipt.review', 'finance.receipt.approve', 'finance.receipt.execute'],
            'payments' => ['finance.payment.draft', 'finance.payment.review', 'finance.payment.approve', 'finance.payment.execute']];
        if (isset($restricted[$module]) && ! collect($restricted[$module])->contains(fn (string $permission): bool => $this->guard->allows($r->user(), $permission))) {
            abort(403, 'لا تملك صلاحية هذا التقرير');
        }
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $q = DB::table($table);
        if ($filter) {
            $q->where($filter, $value);
        }if (isset($d['from'])) {
            $q->whereDate('created_at', '>=', $d['from']);
        }if (isset($d['to'])) {
            $q->whereDate('created_at', '<=', $d['to']);
        }$rows = $q->orderByDesc('id')->limit(20000)->get($cols);
        $this->guard->audit('report', $module, $actor, 'export', ['rows' => $rows->count(), 'from' => $d['from'] ?? null, 'to' => $d['to'] ?? null]);

        return response()->streamDownload(function () use ($rows, $cols) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $cols);
            foreach ($rows as $row) {
                $cells = [];
                foreach ($cols as $c) {
                    $v = (string) ($row->{$c} ?? '');
                    $cells[] = preg_match('/^[=+@\-]/u', $v) ? "'".$v : $v;
                }fputcsv($f, $cells);
            }fclose($f);
        }, 'AIMS_finance_'.$module.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function purchaseRules(Request $r)
    {
        abort_unless($r->user()?->isSystemAdmin(), 403);
        $actor = (int) $r->user()->id;
        $d = $r->validate(['min_quotes' => 'required|integer|between:1,20', 'decision_reference' => 'required|string|min:5|max:1000']);

        return DB::transaction(function () use ($d, $actor) {
            DB::table('finance_purchase_rules')->where('code', 'default_quotes')->update($d + ['updated_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('purchase_rules', 'default_quotes', $actor, 'update', $d);

            return response()->json(['ok' => true]);
        });
    }

    public function purchaseValuation(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.procurement.manage');
        $d = $r->validate(['rate_id' => 'required|exists:exchange_rates,id']);

        return DB::transaction(function () use ($id, $actor, $d) {
            $p = DB::table('finance_purchase_requests')->lockForUpdate()->find($id);
            abort_unless($p, 404);
            if ($p->currency === 'ILS' || ! in_array($p->state, ['draft', 'pending_finance_head', 'pending_director'], true)) {
                $this->fail('purchase', 'لا يمكن تعديل التقييم بعد الاعتماد');
            }$rate = DB::table('exchange_rates')->find($d['rate_id']);
            if ($rate->from_currency_code !== $p->currency || $rate->to_currency_code !== 'ILS' || $rate->effective_at > now()) {
                $this->fail('rate', 'سعر صرف غير صالح لتقييم الشيكل');
            }
            // No floating-point currency conversion: rate is stored as DECIMAL(24,10).
            $rateScaled = S::scaled((string) $rate->rate, 10);
            $amount = $this->cents((string) $p->total_amount);
            if ($rateScaled <= 0 || $rateScaled > 1000000000000000) {
                $this->fail('rate', 'قيمة سعر الصرف خارج النطاق');
            }
            if ($rateScaled <= 0 || $rateScaled > intdiv(PHP_INT_MAX - 5000000000, max(1, $amount))) {
                $this->fail('rate', 'قيمة التقييم أكبر من النطاق الآمن');
            }
            $ils = intdiv($amount * $rateScaled + 5000000000, 10000000000);
            DB::table('finance_purchase_requests')->where('id', $id)->update(['evaluated_ils' => M::format($ils), 'valuation_rate_id' => $rate->id, 'updated_at' => now()]);
            $this->guard->audit('purchase', $id, $actor, 'evaluate_ils', ['rate_id' => $rate->id]);

            return response()->json(['evaluated_ils' => M::format($ils)]);
        });
    }

    public function invoicePayment(Request $r, int $invoiceId)
    {
        $actor = $this->require($r, 'finance.purchase.invoice');
        if ($this->guard->documentaryMode()) {
            $this->fail('transaction', 'تُسدَّد الفواتير في '.$this->guard->externalSystemName().'؛ سجّل رقم سند الصرف من هناك على الفاتورة.');
        }
        $d = $r->validate(['transaction_id' => 'required|exists:finance_transactions,id']);

        return DB::transaction(function () use ($invoiceId, $d, $actor) {
            $invoice = DB::table('finance_purchase_invoices')->lockForUpdate()->find($invoiceId);
            abort_unless($invoice, 404);
            $tx = DB::table('finance_transactions')->where('id', $d['transaction_id'])->lockForUpdate()->first();
            $source = $tx && $tx->source_id ? DB::table('finance_sources')->find($tx->source_id) : null;
            if (! $source || $source->source_module !== 'finance_purchase_invoice' || (string) $source->source_id !== (string) $invoiceId) {
                $this->fail('transaction', 'يجب إنشاء سند الصرف من الفاتورة، وليس ربط سند عام بها');
            }
            if (! $tx || $tx->state !== 'executed' || $tx->kind !== 'payment' || $tx->currency !== $invoice->currency) {
                $this->fail('transaction', 'السند غير منفذ أو عملته غير صحيحة');
            }app(FinanceSettlementService::class)->assertAvailable($tx, 'invoice');
            $already = DB::table('finance_invoice_payments')->where('invoice_id', $invoiceId)->whereNull('reversed_by_transaction_id')->get();
            $total = 0;
            foreach ($already as $a) {
                $total += $this->cents((string) $a->amount);
            }$amount = $this->cents((string) $tx->amount);
            if ($total + $amount > $this->cents((string) $invoice->amount)) {
                $this->fail('amount', 'المدفوعات تتجاوز الفاتورة');
            }if (DB::table('finance_invoice_payments')->where('transaction_id', $tx->id)->exists()) {
                $this->fail('transaction', 'السند مسجل مسبقًا');
            }$id = DB::table('finance_invoice_payments')->insertGetId(['invoice_id' => $invoiceId, 'transaction_id' => $tx->id, 'amount' => $tx->amount] + $this->stamp());
            if ($total + $amount === $this->cents((string) $invoice->amount)) {
                DB::table('finance_purchase_invoices')->where('id', $invoiceId)->update(['state' => 'paid', 'updated_at' => now()]);
            }$this->guard->audit('invoice', $invoiceId, $actor, 'payment', ['transaction_id' => $tx->id]);

            return response()->json(['id' => $id, 'remaining' => M::format($this->cents((string) $invoice->amount) - $total - $amount)], 201);
        });
    }

    public function requestReversal(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.reversal.request');
        $d = $r->validate(['reason' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($id, $actor, $d, $r) {
            $tx = DB::table('finance_transactions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($tx, 404);
            abort_unless($this->guard->canViewTransactions($r->user(), $tx->kind), 403);
            if ($tx->state !== 'executed' || $tx->reversal_of_id) {
                $this->fail('transaction', 'السند غير قابل للعكس');
            }if (DB::table('finance_internal_transfers')->where('in_transaction_id', $id)->orWhere('out_transaction_id', $id)->exists()) {
                $this->fail('transaction', 'تحويل داخلي يحتاج إجراء عكس مترابط مستقل');
            }if (DB::table('finance_transactions')->where('reversal_of_id', $id)->exists()) {
                $this->fail('transaction', 'سبق طلب عكس السند');
            }
            $kind = $tx->kind === 'receipt' ? 'payment' : 'receipt';
            $new = DB::table('finance_transactions')->insertGetId(['document_no' => 'REV-'.strtoupper(bin2hex(random_bytes(8))), 'kind' => $kind, 'state' => 'pending_director', 'treasury_id' => $tx->treasury_id, 'currency' => $tx->currency, 'amount' => $tx->amount, 'effective_on' => now()->toDateString(), 'counterparty' => $tx->counterparty, 'description' => 'طلب عكس '.$tx->document_no.': '.$d['reason'], 'created_by' => $actor, 'reviewed_by' => $actor, 'reversal_of_id' => $id] + $this->stamp());
            $this->guard->audit('transaction', $new, $actor, 'reversal_request', ['original' => $id, 'reason' => $d['reason']]);

            return response()->json(['id' => $new], 201);
        });
    }

    public function resolveReconciliation(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.reconcile');
        $d = $r->validate(['reference' => 'required|string|max:150', 'reason' => 'required|string|min:10|max:2000']);

        return DB::transaction(function () use ($id, $d, $actor) {
            $row = DB::table('finance_reconciliations')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            if ($row->state !== 'open') {
                $this->fail('reconciliation', 'العملية مغلقة');
            }$fresh = $this->paid($row->treasury_id);
            if ($fresh !== $this->cents((string) $row->actual)) {
                $this->fail('reconciliation', 'لم تتم تسوية الفارق فعليًا؛ تحقق من السندات');
            }DB::table('finance_reconciliation_resolutions')->insert(['reconciliation_id' => $id, 'reference' => $d['reference'], 'reason' => $d['reason'], 'resolved_by' => $actor] + $this->stamp());
            DB::table('finance_reconciliations')->where('id', $id)->update(['state' => 'matched', 'updated_at' => now()]);
            $this->guard->audit('reconciliation', $id, $actor, 'resolve');

            return response()->json(['ok' => true]);
        });
    }

    public function exportReport(Request $r, string $module)
    {
        $this->require($r, 'finance.report.export');
        // Reports with a definition in «التقارير والتصدير» use it (Arabic headings, same filters and scope).
        if (isset(app(FinanceReports::class)->definitions()[$module])) {
            return redirect()->route('finance.report.export', ['report' => $module, 'format' => 'csv'] + $r->only(['from', 'to']));
        }

        return $this->report($r, $module);
    }

    public function stockTransfer(Request $r)
    {
        $actor = $this->require($r, 'finance.stock.issue');
        $this->require($r, 'finance.stock.receive');
        $d = $r->validate(['from_id' => 'required|exists:finance_warehouses,id|different:to_id', 'to_id' => 'required|exists:finance_warehouses,id', 'stock_item_id' => 'required|exists:finance_stock_items,id', 'quantity' => 'required|regex:/^\d{1,9}(\.\d{1,3})?$/', 'reference' => 'required|string|max:140']);

        return DB::transaction(function () use ($d, $actor) {
            $ids = [$d['from_id'], $d['to_id']];
            sort($ids);
            DB::table('finance_warehouses')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $b = S::balance($d['from_id'], $d['stock_item_id']);
            $q = S::scaled($d['quantity'], 3);
            if ($q <= 0 || $q > $b['qty']) {
                $this->fail('quantity', 'كمية النقل تتجاوز المخزون');
            }
            if (DB::table('finance_stock_movements')->where('reference_type', 'warehouse_transfer')->where('reference_id', $d['reference'])->exists()) {
                $this->fail('reference', 'مرجع النقل مستخدم');
            }
            $a = S::movement(['warehouse_id' => $d['from_id'], 'stock_item_id' => $d['stock_item_id'], 'kind' => 'issue', 'quantity' => $d['quantity'], 'reference_type' => 'warehouse_transfer', 'reference_id' => $d['reference'], 'note' => 'نقل إلى مخزن '.$d['to_id']], $actor);
            $b = S::movement(['warehouse_id' => $d['to_id'], 'stock_item_id' => $d['stock_item_id'], 'kind' => 'receipt', 'quantity' => $d['quantity'], 'unit_cost' => S::decimal($b['average'], 4), 'reference_type' => 'warehouse_transfer', 'reference_id' => $d['reference'], 'note' => 'نقل من مخزن '.$d['from_id']], $actor);
            $this->guard->audit('stock_transfer', $d['reference'], $actor, 'execute', ['out' => $a, 'in' => $b]);

            return response()->json(['movements' => [$a, $b]], 201);
        });
    }

    public function invoicePaymentDraft(Request $r, int $invoiceId)
    {
        $actor = $this->require($r, 'finance.payment.draft');
        $d = $r->validate(['treasury_id' => 'required|exists:finance_treasuries,id', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'effective_on' => 'required|date']);

        return DB::transaction(function () use ($invoiceId, $actor, $d) {
            $invoice = DB::table('finance_purchase_invoices')->lockForUpdate()->find($invoiceId);
            abort_unless($invoice, 404);
            $order = DB::table('finance_purchase_orders')->find($invoice->order_id);
            $purchase = DB::table('finance_purchase_requests')->find($order->purchase_request_id);
            $treasury = DB::table('finance_treasuries')->where('id', $d['treasury_id'])->first();
            if (! $treasury || ! $treasury->is_active || $treasury->currency !== $invoice->currency) {
                $this->fail('currency', 'صندوق غير فعال أو عملته مختلفة');
            }$rows = DB::table('finance_invoice_payments')->where('invoice_id', $invoiceId)->whereNull('reversed_by_transaction_id')->get();
            $used = 0;
            foreach ($rows as $p) {
                $used += $this->cents((string) $p->amount);
            }$amount = $this->cents($d['amount']);
            if ($amount <= 0 || $amount + $used > $this->cents((string) $invoice->amount)) {
                $this->fail('amount', 'الدفعة تتجاوز الرصيد المتبقي');
            }$source = $this->ops->createSource('finance_purchase_invoice', (string) $invoiceId, 'payment', ['order_id' => $order->id]);
            $id = DB::table('finance_transactions')->insertGetId(['document_no' => 'FV-'.strtoupper(bin2hex(random_bytes(8))), 'kind' => 'payment', 'state' => 'draft', 'treasury_id' => $treasury->id, 'budget_line_id' => $purchase->budget_line_id, 'source_id' => $source, 'currency' => $treasury->currency, 'amount' => $d['amount'], 'effective_on' => $d['effective_on'], 'counterparty' => 'مورد #'.$order->supplier_id, 'description' => 'سداد فاتورة '.$invoice->invoice_no, 'created_by' => $actor] + $this->stamp());
            $this->guard->audit('invoice', $invoiceId, $actor, 'payment_draft', ['transaction_id' => $id]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function closeOrder(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.procurement.manage');

        return DB::transaction(function () use ($id, $actor) {
            $order = DB::table('finance_purchase_orders')->lockForUpdate()->find($id);
            abort_unless($order, 404);
            if ($order->state !== 'received') {
                $this->fail('order', 'يلزم استلام جميع الأصناف قبل الإغلاق');
            }$purchase = DB::table('finance_purchase_requests')->find($order->purchase_request_id);
            $invoices = DB::table('finance_purchase_invoices')->where('order_id', $id)->get();
            if ($invoices->isEmpty()) {
                $this->fail('invoice', 'لا توجد فاتورة');
            }$invoiced = 0;
            foreach ($invoices as $invoice) {
                if ($invoice->state !== 'paid') {
                    $this->fail('invoice', 'توجد فاتورة غير مسددة');
                }$invoiced += $this->cents((string) $invoice->amount);
            }if ($invoiced > $this->cents((string) $order->amount)) {
                $this->fail('invoice', 'فواتير أكبر من أمر الشراء');
            }
            $diff = $this->cents((string) $purchase->total_amount) - $invoiced;
            if ($diff < 0) {
                $this->fail('budget', 'تجاوز المبلغ المعتمد');
            }
            // In the documentary cycle no budget commitment was made, so there is nothing to release.
            $line = $this->guard->documentaryMode() ? null : DB::table('finance_budget_lines')->where('id', $purchase->budget_line_id)->lockForUpdate()->first();
            if (! $line && ! $this->guard->documentaryMode()) {
                $this->fail('budget', 'بند الموازنة غير موجود');
            }
            $releaseDelta = $diff - $this->cents((string) $order->closure_released_amount);
            if ($line && $releaseDelta !== 0) {
                DB::table('finance_budget_movements')->insert(['budget_line_id' => $line->id, 'kind' => $releaseDelta > 0 ? 'release' : 'commitment', 'amount' => M::format(abs($releaseDelta)), 'state' => 'approved', 'reason' => 'تحرير المتبقي من أمر الشراء '.$order->number, 'created_by' => $actor, 'approved_by' => $purchase->director_approved_by] + $this->stamp());
            }
            DB::table('finance_purchase_orders')->where('id', $id)->update(['state' => 'closed', 'closure_released_amount' => M::format($diff), 'updated_at' => now()]);
            $this->guard->audit('purchase_order', $id, $actor, 'close', ['released' => M::format($diff)]);

            return response()->json(['ok' => true, 'released' => M::format($diff)]);
        });
    }

    public function budgetException(Request $r)
    {
        $actor = $this->require($r, 'finance.budget.transfer');
        $d = $r->validate(['budget_line_id' => 'required|exists:finance_budget_lines,id', 'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'reason' => 'required|string|min:10|max:2000', 'decision_reference' => 'required|string|min:5|max:150']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'المبلغ غير صالح');
        }

        return DB::transaction(function () use ($d, $actor) {
            $id = DB::table('finance_budget_exceptions')->insertGetId($d + ['created_by' => $actor] + $this->stamp());
            $this->guard->audit('budget_exception', $id, $actor, 'request');

            return response()->json(['id' => $id], 201);
        });
    }

    public function approveBudgetException(Request $r, int $id)
    {
        $actor = $this->require($r, 'finance.budget.approve');

        return DB::transaction(function () use ($id, $actor) {
            $req = DB::table('finance_budget_exceptions')->lockForUpdate()->find($id);
            abort_unless($req, 404);
            if ($req->state !== 'pending' || $req->created_by === $actor) {
                $this->fail('exception', 'استثناء غير قابل للاعتماد');
            }DB::table('finance_budget_lines')->where('id', $req->budget_line_id)->lockForUpdate()->first();
            DB::table('finance_budget_movements')->insert(['budget_line_id' => $req->budget_line_id, 'kind' => 'adjustment', 'amount' => $req->amount, 'state' => 'approved', 'reason' => 'استثناء '.$req->decision_reference, 'created_by' => $req->created_by, 'approved_by' => $actor] + $this->stamp());
            DB::table('finance_budget_exceptions')->where('id', $id)->update(['state' => 'approved', 'approved_by' => $actor, 'updated_at' => now()]);
            $this->guard->audit('budget_exception', $id, $actor, 'approve');

            return response()->json(['ok' => true]);
        });
    }

    public function approvals(Request $r, FinanceApprovalQueue $approvals)
    {
        $u = $r->user();
        abort_unless($u && $u->is_active, 403);
        abort_unless($approvals->isApprover($u), 403);

        return Inertia::render('Finance/Approvals', ['queue' => $approvals->entries($u), 'board' => $approvals->isBoardApprover($u), 'cancellable' => $approvals->cancellable($u)]);
    }
}
