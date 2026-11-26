<?php

namespace App\Services\Rentals;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Balances of rent and service dues, kept apart as the owner's prompt requires:
 * original amount, cash paid (external receipts Accounts recorded, plus receipts made in AIMS's own accounting
 * mode), posted settlements (reductions and increases), and the remainder. A due's contractual amount never
 * changes when money comes in; only the remainder falls. Reference: 3000 due, 1000 paid, 200 exemption posted
 * gives 1800 remaining, with 3000, 1000 and 200 each shown on its own.
 */
final class RentalLedger
{
    public function __construct(private readonly RentalFinanceService $finance) {}

    /**
     * One row per due with: amount, increased, reduced, paid, net (amount + increased − reduced), remaining,
     * credit (paid above net), payment_state (paid|partial|unpaid|cancelled) and overdue.
     */
    public function dues(): Builder
    {
        $receipts = DB::table('aims_rental_receipt_allocations')->whereNull('reversed_at')
            ->groupBy('due_id')->select('due_id')->selectRaw('SUM(amount) AS paid');
        $settled = DB::table('aims_rental_settlements')->where('status', 'posted')->whereNotNull('due_id')->groupBy('due_id')->select('due_id')
            ->selectRaw("SUM(CASE WHEN effect = 'reduce' THEN amount ELSE 0 END) AS reduced")
            ->selectRaw("SUM(CASE WHEN effect = 'increase' THEN amount ELSE 0 END) AS increased");
        $net = '(d.amount + COALESCE(d.adjustment, 0) + COALESCE(st.increased, 0) - COALESCE(st.reduced, 0))';
        $paid = '(COALESCE(rp.paid, 0) + COALESCE(lg.paid, 0))';
        $remaining = "CASE WHEN d.status = 'cancelled' THEN 0 ELSE GREATEST({$net} - {$paid}, 0) END";

        return DB::table('aims_rental_dues as d')
            ->join('aims_rental_contracts as c', 'c.id', '=', 'd.contract_id')
            ->leftJoinSub($receipts, 'rp', 'rp.due_id', '=', 'd.id')
            ->leftJoinSub($settled, 'st', 'st.due_id', '=', 'd.id')
            ->leftJoinSub($this->finance->paidByDue(), 'lg', 'lg.due_id', '=', DB::raw('CAST(d.id AS VARCHAR)'))
            ->select(['d.id', 'd.contract_id', 'd.due_on', 'd.kind', 'd.service_value_id', 'd.currency_code', 'd.amount', 'd.status', 'd.cancel_reason',
                'c.number as contract_number', 'c.tenant_id'])
            ->selectRaw('COALESCE(d.adjustment, 0) + COALESCE(st.increased, 0) AS increased')
            ->selectRaw('COALESCE(st.reduced, 0) AS reduced')
            ->selectRaw("{$paid} AS paid")
            ->selectRaw("{$net} AS net")
            ->selectRaw("{$remaining} AS remaining")
            ->selectRaw("GREATEST({$paid} - {$net}, 0) AS credit")
            ->selectRaw("CASE WHEN d.status = 'cancelled' THEN 'cancelled' WHEN {$remaining} = 0 THEN 'paid'
                WHEN {$paid} > 0 OR COALESCE(st.reduced, 0) > 0 THEN 'partial' ELSE 'unpaid' END AS payment_state")
            ->selectRaw("(d.status <> 'cancelled' AND d.due_on < CURRENT_DATE AND {$remaining} > 0) AS overdue");
    }

    /** Remaining minor units of one due, read inside the caller's transaction after the due row is locked. */
    public function remainingMinor(int $dueId): int
    {
        $row = $this->dues()->where('d.id', $dueId)->first();

        return $row ? MoneyRules::toMinor(number_format((float) $row->remaining, 2, '.', '')) : 0;
    }

    /** Close a due once nothing remains, reopen it when a reversal or an increase brings a balance back. */
    public function refreshStatus(int $dueId): void
    {
        $row = $this->dues()->where('d.id', $dueId)->first();
        if (! $row || $row->status === 'cancelled') {
            return;
        }
        $status = (float) $row->remaining > 0 ? 'open' : 'closed';
        if ($status !== $row->status) {
            DB::table('aims_rental_dues')->where('id', $dueId)->update(['status' => $status, 'updated_at' => now()]);
        }
    }

    /**
     * Totals by currency for a set of dues (amounts in different currencies are never added together).
     *
     * @return list<array{currency: string, amount: string, paid: string, reduced: string, remaining: string, overdue: string}>
     */
    public function totals(Builder $dues): array
    {
        return DB::query()->fromSub($dues, 'b')->groupBy('b.currency_code')->orderBy('b.currency_code')
            ->select('b.currency_code as currency')
            ->selectRaw('SUM(b.amount) AS amount, SUM(b.paid) AS paid, SUM(b.reduced) AS reduced, SUM(b.remaining) AS remaining')
            ->selectRaw('SUM(CASE WHEN b.overdue THEN b.remaining ELSE 0 END) AS overdue')
            ->get()->map(fn (object $row): array => (array) $row)->all();
    }
}
