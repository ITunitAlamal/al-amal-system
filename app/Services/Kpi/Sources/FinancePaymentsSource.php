<?php

namespace App\Services\Kpi\Sources;

use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Executed payment vouchers, one row per voucher. */
final class FinancePaymentsSource extends KpiSource
{
    public function key(): string
    {
        return 'finance_payments';
    }

    public function label(): string
    {
        return 'سندات الصرف المنفذة';
    }

    public function basis(): string
    {
        return 'سندات صرف حالتها «منفذ» وتاريخ تنفيذها داخل الفترة. المدة من إنشاء السند حتى تنفيذه وتشمل المراجعة والاعتماد. مستبعد: التحويلات الداخلية بين الصناديق، وسندات العكس، والسندات التي عُكست بسند منفذ.';
    }

    public function lineage(): array
    {
        return ['finance_transactions: kind=payment, state=executed, created_at, executed_at, reversal_of_id, document_no', 'finance_internal_transfers: out_transaction_id'];
    }

    public function measures(): array
    {
        return [
            'executed' => ['label' => 'سندات صرف نُفذت في الفترة', 'kind' => 'count', 'snapshot' => false],
            'processing_hours' => ['label' => 'ساعات من إنشاء السند حتى تنفيذه', 'kind' => 'hours', 'snapshot' => false],
        ];
    }

    public function sliceable(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return ['reference' => 'رقم السند', 'currency' => 'العملة', 'amount' => 'المبلغ', 'created_at' => 'أُنشئ', 'executed_at' => 'نُفذ'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        return DB::table('finance_transactions as t')
            ->where('t.kind', 'payment')->where('t.state', 'executed')->whereNull('t.reversal_of_id')->whereNotNull('t.executed_at')
            ->where('t.executed_at', '>=', $this->naive($period->from))->where('t.executed_at', '<', $this->naive($period->until))
            ->where(fn (Builder $document) => $document->whereNull('t.document_no')->orWhere('t.document_no', 'not like', 'TR-%'))
            ->whereNotExists(fn (Builder $transfer) => $transfer->from('finance_internal_transfers as it')->whereColumn('it.out_transaction_id', 't.id'))
            ->whereNotExists(fn (Builder $reversal) => $reversal->from('finance_transactions as x')->whereColumn('x.reversal_of_id', 't.id')->where('x.state', 'executed'))
            ->select(['t.id', 't.document_no as reference', 't.currency', 't.amount', 't.created_at', 't.executed_at'])
            ->selectRaw('CAST(NULL AS bigint) AS department_id')
            ->selectRaw(($measure === 'processing_hours' ? 'EXTRACT(EPOCH FROM (t.executed_at - t.created_at)) / 3600.0' : '1').' AS contribution');
    }
}
