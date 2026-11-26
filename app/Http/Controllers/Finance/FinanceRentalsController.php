<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceGuard;
use App\Services\Rentals\RentalConstants;
use App\Services\Rentals\RentalLedger;
use App\Services\Rentals\RentalReceipts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts' page for rent: dues with their balances, recording the collections made in the external accounting
 * software (reference, date, amount per due) and reversing a wrong record. Settlements reach Accounts as workflow
 * tasks in «مهامي». Needs finance.rentals.record with an active finance assignment; never the admin's technical role.
 */
class FinanceRentalsController extends Controller
{
    private const FILTERS = ['open' => 'غير المسددة', 'overdue' => 'المتأخرة', 'paid' => 'المسددة', 'all' => 'الكل'];

    public function __construct(private readonly FinanceGuard $guard, private readonly RentalLedger $ledger) {}

    public function index(Request $r, RentalConstants $constants): Response
    {
        $this->guard->assert($r->user(), 'finance.rentals.record');
        $filters = $r->validate(['filter' => ['nullable', Rule::in(array_keys(self::FILTERS))], 'search' => 'nullable|string|max:100']);
        $filter = $filters['filter'] ?? 'open';
        $search = trim((string) ($filters['search'] ?? ''));
        $dues = DB::query()->fromSub($this->ledger->dues(), 'b')->join('aims_rental_tenants as t', 't.id', '=', 'b.tenant_id')
            ->when($filter === 'open', fn ($q) => $q->where('b.remaining', '>', 0))
            ->when($filter === 'overdue', fn ($q) => $q->where('b.overdue', true))
            ->when($filter === 'paid', fn ($q) => $q->where('b.payment_state', 'paid'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('b.contract_number', 'ilike', '%'.$search.'%')->orWhere('t.name', 'ilike', '%'.$search.'%')))
            ->orderBy('b.due_on')->limit(500)->get(['b.*', 't.name as tenant']);

        return Inertia::render('Finance/Rentals', [
            'dues' => $dues,
            'totals' => $this->ledger->totals($this->ledger->dues()),
            'receipts' => DB::table('aims_rental_receipt_allocations as a')->join('aims_rental_dues as d', 'd.id', '=', 'a.due_id')
                ->join('aims_rental_contracts as c', 'c.id', '=', 'd.contract_id')->leftJoin('users as u', 'u.id', '=', 'a.recorded_by')
                ->orderByDesc('a.id')->limit(200)
                ->get(['a.id', 'a.due_id', 'd.due_on', 'c.number as contract_number', 'a.accounting_reference', 'a.amount', 'a.currency_code', 'a.accounting_posted_at',
                    'a.reversed_at', 'a.reversal_reason', 'a.reversal_reference', 'u.name as recorded_by']),
            'paymentMethods' => $constants->options()['payment_methods'],
            'externalSystem' => $this->guard->externalSystemName(),
            'filters' => ['filter' => $filter, 'search' => $search],
            'filterLabels' => self::FILTERS,
        ]);
    }

    public function store(Request $r, RentalReceipts $receipts, RentalConstants $constants): RedirectResponse
    {
        $this->guard->assert($r->user(), 'finance.rentals.record');
        $d = $r->validate([
            'request_key' => 'required|uuid', 'reference' => 'required|string|max:150', 'posted_on' => 'required|date|before_or_equal:today',
            'payment_method_value_id' => 'nullable|integer', 'note' => 'nullable|string|max:2000',
            'allocations' => 'required|array|min:1|max:50', 'allocations.*.due_id' => 'required|integer|exists:aims_rental_dues,id',
            'allocations.*.amount' => 'required|numeric|min:0.01|max:999999999999',
        ]);
        $constants->assert('payment_methods', isset($d['payment_method_value_id']) ? (int) $d['payment_method_value_id'] : null, 'payment_method_value_id');
        $d['allocations'] = array_map(fn (array $row): array => ['due_id' => (int) $row['due_id'], 'amount' => number_format((float) $row['amount'], 2, '.', '')], $d['allocations']);
        $count = $receipts->record($r->user(), $d);

        return back()->with('success', $count === 0 ? 'سبق تسجيل هذه العملية؛ لم يُكرَّر القبض.' : 'سُجل السند '.$d['reference'].' على '.$count.' استحقاق.');
    }

    public function reverse(Request $r, int $id, RentalReceipts $receipts): RedirectResponse
    {
        $this->guard->assert($r->user(), 'finance.rentals.record');
        $d = $r->validate(['reason' => 'required|string|min:3|max:2000', 'reversal_reference' => 'nullable|string|max:150']);
        $receipts->reverse($r->user(), $id, $d['reason'], $d['reversal_reference'] ?? null);

        return back()->with('success', 'عُكس القبض وعاد المبلغ إلى رصيد الاستحقاق؛ بقي السجل الأصلي للتدقيق.');
    }
}
