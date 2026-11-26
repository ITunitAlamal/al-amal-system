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
