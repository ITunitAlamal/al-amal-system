<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceMoney;
use App\Services\Finance\FinanceSettlementService;
use App\Services\Finance\FinanceSourceBridge;
use App\Services\Rentals\RentalLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Additive finance workflows. Never mutates executed financial records. */
final class FinanceExtensionsController extends Controller
{
    public function __construct(private readonly FinanceGuard $guard) {}

    private function cents($value): int
    {
        return FinanceMoney::cents((string) $value);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function allocate(Request $r, int $id)
    {
        $this->guard->assert($r->user(), 'finance.receipt.execute');
        $d = $r->validate(['allocations' => 'required|array|min:1', 'allocations.*.module' => 'required|string|max:55',
            'allocations.*.reference' => 'required|integer|min:1', 'allocations.*.amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/']);
        foreach ($d['allocations'] as &$allocation) {
            $allocation['reference'] = (string) (int) $allocation['reference'];
        }
        unset($allocation);

        return DB::transaction(function () use ($r, $id, $d) {
            $tx = DB::table('finance_transactions')->lockForUpdate()->find($id);
            if (! $tx || $tx->state !== 'executed' || $tx->kind !== 'receipt') {
                $this->fail('transaction', 'السند ليس قبضًا منفذًا');
            }
            app(FinanceSettlementService::class)->assertAvailable($tx, 'collection');
            $existing = DB::table('finance_payment_allocations')->where('transaction_id', $id)->whereNull('reversed_by_transaction_id')->get();
            $used = 0;
            foreach ($existing as $e) {
                $used += $this->cents($e->amount);
            }
            $total = 0;
            $seen = [];
            $rentalDues = [];
            foreach ($d['allocations'] as $a) {
                $key = $a['module'].'|'.$a['reference'];
                if (isset($seen[$key])) {
                    $this->fail('allocations', 'مرجع مكرر داخل الطلب');
                }$seen[$key] = true;
                $amount = $this->cents($a['amount']);
                if ($amount <= 0) {
                    $this->fail('amount', 'المبلغ يجب أن يكون موجبًا');
                }
                if ($existing->contains(fn ($e) => $e->receivable_module === $a['module'] && $e->receivable_id === $a['reference'])) {
                    $this->fail('allocations', 'سبق تخصيص هذا المستحق من السند');
                }
                if ($a['module'] !== 'finance') {
                    $this->fail('module', 'المصدر غير متصل فعليًا؛ لا يمكن اعتبار استحقاقه محصلًا');
                }
                // A rent due is locked before its receivable, the order the rentals paths use (terminate, settlement),
                // so the two never deadlock. Its remainder also counts the receipts and settlements at /finance/rentals.
                $receivable = DB::table('finance_receivables')->where('id', $a['reference'])->first();
                if ($receivable && $receivable->source_module === 'rentals') {
                    DB::table('aims_rental_dues')->where('id', (int) $receivable->source_reference)->lockForUpdate()->first();
                }
                $receivable = $receivable ? DB::table('finance_receivables')->where('id', $a['reference'])->lockForUpdate()->first() : null;
                if (! $receivable || $receivable->currency !== $tx->currency) {
                    $this->fail('receivable', 'المستحق غير موجود أو العملة مختلفة');
                }
                if ($receivable->cancelled_at !== null) {
                    $this->fail('receivable', 'ألغى القسم المصدر هذا المستحق ('.$receivable->cancel_reason.')؛ لا يُخصص له قبض');
                }
                if ($amount > app(FinanceSourceBridge::class)->openCents($receivable)) {
                    $this->fail('allocations', 'التخصيص يتجاوز الرصيد المتبقي للمستحق');
                }
                $rentalDues[] = $receivable->source_module === 'rentals' ? (int) $receivable->source_reference : null;
                $total += $amount;
            }
            if ($used + $total > $this->cents($tx->amount)) {
                $this->fail('allocations', 'التخصيص يتجاوز قيمة السند');
            }
            foreach ($d['allocations'] as $a) {
                DB::table('finance_payment_allocations')->insert([
                    'transaction_id' => $id, 'receivable_module' => $a['module'], 'receivable_id' => $a['reference'],
                    'amount' => $a['amount'], 'created_at' => now(), 'updated_at' => now()]);
            }
            // A rent due paid off here closes in the rentals file as it does after an external receipt.
            foreach (array_filter($rentalDues) as $dueId) {
                app(RentalLedger::class)->refreshStatus($dueId);
            }
            $this->guard->audit('transaction', $id, $r->user()->id, 'allocate', ['amount' => FinanceMoney::format($total)]);

            return response()->json(['allocated' => FinanceMoney::format($used + $total), 'remaining' => FinanceMoney::format($this->cents($tx->amount) - $used - $total)]);
        });
    }

    public function transfer(Request $r)
    {
        $this->guard->assert($r->user(), 'finance.transfer.execute');
        $d = $r->validate(['from_id' => 'required|integer|different:to_id', 'to_id' => 'required|integer',
            'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'reference' => 'required|string|max:80']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'المبلغ يجب أن يكون موجبًا');
        }

        return DB::transaction(function () use ($r, $d) {
            $previous = DB::table('finance_internal_transfers')->where('reference', $d['reference'])->first();
            if ($previous) {
                $this->fail('reference', 'مرجع التحويل مستخدم مسبقًا');
            }
            $ids = [$d['from_id'], $d['to_id']];
            sort($ids, SORT_NUMERIC);
            $accounts = DB::table('finance_treasuries')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $accounts->get($d['from_id']);
            $to = $accounts->get($d['to_id']);
            if (! $from || ! $to || ! $from->is_active || ! $to->is_active) {
                $this->fail('treasury', 'الصندوق أو البنك غير موجود أو غير فعال');
            }
            if ($from->currency !== $to->currency) {
                $this->fail('currency', 'التحويل عبر عملتين مختلفتين يتطلب إجراء صرف عملة معتمدًا');
            }
            $balance = DB::table('finance_transactions')->where('treasury_id', $from->id)->where('state', 'executed')
                ->selectRaw("COALESCE(SUM(CASE WHEN kind='receipt' THEN amount ELSE -amount END),0) AS balance")->value('balance');
            if ($this->cents($balance) < $this->cents($d['amount'])) {
                $this->fail('amount', 'رصيد المصدر لا يكفي');
            }
            $at = now();
            $actor = $r->user()->id;
            $transfer = DB::table('finance_internal_transfers')->insertGetId(['reference' => $d['reference'], 'from_treasury_id' => $from->id,
                'to_treasury_id' => $to->id, 'amount' => $d['amount'], 'currency' => $from->currency,
                'executed_by' => $actor, 'created_at' => $at, 'updated_at' => $at]);
            $pair = [];
            foreach ([['payment', $from->id], ['receipt', $to->id]] as [$kind,$treasury]) {
                $pair[] = DB::table('finance_transactions')->insertGetId([
                    'document_no' => 'TR-'.strtoupper(bin2hex(random_bytes(10))), 'kind' => $kind, 'state' => 'executed',
                    'treasury_id' => $treasury, 'currency' => $from->currency, 'amount' => $d['amount'],
                    'effective_on' => $at->toDateString(), 'description' => 'تحويل داخلي '.$d['reference'],
                    'created_by' => $actor, 'executed_by' => $actor, 'executed_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
            }
            DB::table('finance_internal_transfers')->where('id', $transfer)->update(['out_transaction_id' => $pair[0], 'in_transaction_id' => $pair[1]]);
            $this->guard->audit('transfer', $transfer, $actor, 'execute', ['transactions' => $pair]);

            return response()->json(['id' => $transfer, 'transactions' => $pair], 201);
        });
    }

    public function advance(Request $r)
    {
        $this->guard->assert($r->user(), 'finance.advance.manage');
        $d = $r->validate(['user_id' => 'required|exists:users,id', 'treasury_id' => 'required|exists:finance_treasuries,id',
            'amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'due_on' => 'nullable|date', 'purpose' => 'required|string|max:2000']);
        if ($this->cents($d['amount']) <= 0) {
            $this->fail('amount', 'السلفة يجب أن تكون موجبة');
        }
        $t = DB::table('finance_treasuries')->find($d['treasury_id']);
        if (! $t || ! $t->is_active) {
            $this->fail('treasury_id', 'الحساب غير فعال');
        }
        $id = DB::transaction(function () use ($r, $d, $t) {
            $id = DB::table('finance_advances')->insertGetId($d + ['currency' => $t->currency, 'state' => 'draft', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->guard->audit('advance', $id, $r->user()->id, 'draft');

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function disburseAdvance(Request $r, int $id)
    {
        $this->guard->assert($r->user(), 'finance.advance.manage');
        $d = $r->validate(['transaction_id' => 'required|exists:finance_transactions,id']);

        return DB::transaction(function () use ($r, $id, $d) {
            $a = DB::table('finance_advances')->lockForUpdate()->find($id);
            if (! $a || $a->state !== 'draft') {
                $this->fail('advance', 'السلفة غير متاحة للصرف');
            }
            $tx = DB::table('finance_transactions')->lockForUpdate()->find($d['transaction_id']);
            if (! $tx || $tx->state !== 'executed' || $tx->kind !== 'payment' || (int) $tx->treasury_id !== (int) $a->treasury_id
              || $tx->currency !== $a->currency || $this->cents($tx->amount) !== $this->cents($a->amount)) {
                $this->fail('transaction_id', 'يلزم سند صرف منفذ بالقيمة والعملة والصندوق المطابقين');
            }
            app(FinanceSettlementService::class)->assertAvailable($tx, 'advance');
            if (DB::table('finance_advance_disbursements')->where('transaction_id', $tx->id)->exists()) {
                $this->fail('transaction_id', 'سبق ربط سند الصرف بسلفة أخرى');
            }
            DB::table('finance_advance_disbursements')->insert(['advance_id' => $id, 'transaction_id' => $tx->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('finance_advances')->where('id', $id)->update(['state' => 'disbursed', 'updated_at' => now()]);
            $this->guard->audit('advance', $id, $r->user()->id, 'disbursed', ['transaction_id' => $tx->id]);

            return response()->json(['ok' => true]);
        });
    }

    public function settleAdvance(Request $r, int $id)
    {
        $this->guard->assert($r->user(), 'finance.advance.manage');
        $d = $r->validate(['amount' => 'required|regex:/^\d{1,12}(\.\d{1,2})?$/', 'kind' => 'required|in:expense,refund',
            'reference' => 'required|string|max:255', 'transaction_id' => 'prohibited_unless:kind,refund|nullable|exists:finance_transactions,id']);

        return DB::transaction(function () use ($r, $id, $d) {
            $a = DB::table('finance_advances')->lockForUpdate()->find($id);
            if (! $a || $a->state !== 'disbursed') {
                $this->fail('advance', 'السلفة لم تُصرف بعد');
            }
            $rows = DB::table('finance_advance_settlements')->where('advance_id', $id)->whereNull('reversed_by_transaction_id')->get();
            $used = 0;
            foreach ($rows as $row) {
                $used += $this->cents($row->amount);
            }
            $amount = $this->cents($d['amount']);
            if ($amount <= 0 || $used + $amount > $this->cents($a->amount)) {
                $this->fail('amount', 'مبلغ التسوية غير صالح');
            }
            if ($d['kind'] === 'refund') {
                if (empty($d['transaction_id'])) {
                    $this->fail('transaction_id', 'استرداد السلفة يلزمه سند قبض منفذ');
                }
                $receipt = DB::table('finance_transactions')->where('id', $d['transaction_id'])->lockForUpdate()->first();
                if (! $receipt || $receipt->kind !== 'receipt' || $receipt->state !== 'executed' || $receipt->currency !== $a->currency ||
                    (int) $receipt->treasury_id !== (int) $a->treasury_id || $this->cents($receipt->amount) !== $amount) {
                    $this->fail('transaction_id', 'سند القبض غير مطابق للسلفة');
                }
                app(FinanceSettlementService::class)->assertAvailable($receipt, 'advance_refund');
                if (DB::table('finance_advance_settlements')->where('transaction_id', $receipt->id)->exists()) {
                    $this->fail('transaction_id', 'سند القبض سبق استخدامه');
                }
            }
            $entry = DB::table('finance_advance_settlements')->insertGetId($d + ['advance_id' => $id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($used + $amount === $this->cents($a->amount)) {
                DB::table('finance_advances')->where('id', $id)->update(['state' => 'settled', 'updated_at' => now()]);
            }
            $this->guard->audit('advance', $id, $r->user()->id, 'settle', ['entry' => $entry]);

            return response()->json(['id' => $entry, 'remaining' => FinanceMoney::format($this->cents($a->amount) - $used - $amount)], 201);
        });
    }

    public function reconcile(Request $r)
    {
        $this->guard->assert($r->user(), 'finance.reconcile');
        $d = $r->validate(['treasury_id' => 'required|exists:finance_treasuries,id', 'as_of' => 'required|date',
            'actual' => 'required|regex:/^-?\d{1,12}(\.\d{1,2})?$/', 'notes' => 'nullable|string|max:4000']);

        return DB::transaction(function () use ($r, $d) {
            DB::table('finance_treasuries')->where('id', $d['treasury_id'])->lockForUpdate()->first();
            $expected = DB::table('finance_transactions')->where('treasury_id', $d['treasury_id'])->where('state', 'executed')
                ->whereDate('effective_on', '<=', $d['as_of'])
                ->selectRaw("COALESCE(SUM(CASE WHEN kind='receipt' THEN amount ELSE -amount END),0) AS balance")->value('balance');
            $diff = $this->cents($d['actual']) - $this->cents($expected);
            $id = DB::table('finance_reconciliations')->insertGetId($d + ['expected' => $expected, 'state' => $diff === 0 ? 'matched' : 'open',
                'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->guard->audit('reconciliation', $id, $r->user()->id, 'create', ['difference' => FinanceMoney::format($diff)]);

            return response()->json(['id' => $id, 'expected' => $expected, 'difference' => FinanceMoney::format($diff)], 201);
        });
    }
}
