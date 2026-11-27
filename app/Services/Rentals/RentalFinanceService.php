<?php

namespace App\Services\Rentals;

use App\Services\Finance\FinanceMoney;
use App\Services\Finance\FinanceSourceBridge;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RentalFinanceService
{
    public function __construct(private readonly FinanceSourceBridge $bridge) {}

    public function registerContract(int $contractId, int $actor): void
    {
        $contract = DB::table('aims_rental_contracts')->where('id', $contractId)->first();
        $tenant = DB::table('aims_rental_tenants')->where('id', $contract->tenant_id)->value('name');
        foreach (DB::table('aims_rental_dues')->where('contract_id', $contractId)->where('status', '<>', 'cancelled')->orderBy('id')->get() as $due) {
            $this->bridge->registerApprovedReceivable(
                'rentals', (string) $due->id, $due->kind === 'service' ? 'service' : 'rent', $tenant, $due->currency_code,
                FinanceMoney::format(FinanceMoney::cents($due->amount) + FinanceMoney::cents($due->adjustment)),
                $due->due_on, $actor, ($due->kind === 'service' ? 'استحقاق خدمة لعقد الإيجار ' : 'استحقاق عقد الإيجار ').$contract->number,
            );
        }
    }

    /** A due the rentals file cancelled (termination, amendment) is cancelled in Accounts' receivables too. */
    public function cancelDue(int $dueId, string $reason, int $actor): void
    {
        $this->bridge->cancelReceivable('rentals', (string) $dueId, $reason, $actor);
    }

    /** A posted settlement changes the registered receivable by its signed amount, once per settlement. */
    public function settleDue(object $settlement, int $actor): void
    {
        if (! $settlement->due_id) {
            return;
        }
        $delta = ($settlement->effect === 'reduce' ? '-' : '').FinanceMoney::format(FinanceMoney::cents((string) $settlement->amount));
        $this->bridge->adjustReceivable('rentals', (string) $settlement->due_id, $delta, 'rental_settlement:'.$settlement->id,
            'تسوية إيجار مرحّلة '.($settlement->posting_reference ?? ''), $actor);
    }

    /** @return list<object> */
    public function receipts(): array
    {
        return $this->paidByDue()->get()->all();
    }

    /** Collections Accounts recorded against each rent due (due_id as text, paid), unreversed allocations only. */
    public function paidByDue(): Builder
    {
        return DB::table('finance_payment_allocations as a')
            ->join('finance_receivables as r', DB::raw('CAST(r.id AS VARCHAR)'), '=', 'a.receivable_id')
            ->where('a.receivable_module', 'finance')->where('r.source_module', 'rentals')
            ->whereNull('a.reversed_by_transaction_id')
            ->select('r.source_reference as due_id')->selectRaw('SUM(a.amount) as paid')
            ->groupBy('r.source_reference');
    }
}
