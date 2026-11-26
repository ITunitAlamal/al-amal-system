<?php

namespace App\Services\Kpi\Sources;

use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Open control items in Accounts now: unmatched cash reconciliations and unsettled advances. */
final class FinanceControlsSource extends KpiSource
{
    public function key(): string
    {
        return 'finance_controls';
    }

    public function label(): string
    {
        return 'بنود رقابة مالية مفتوحة';
    }

    public function basis(): string
    {
        return 'قراءة لحالة اليوم. فروقات المطابقة = مطابقات صناديق حالتها «مفتوح» لوجود فرق بين المتوقع والفعلي. السلف غير المسوّاة = سلف حالتها «مصروفة» ولم تُسوَّ؛ المتأخرة منها تجاوزت تاريخ استحقاقها.';
    }

    public function lineage(): array
    {
        return ['finance_reconciliations: state, as_of, expected, actual', 'finance_advances: state, due_on, currency, amount'];
    }

    public function measures(): array
    {
        return [
            'open_reconciliations' => ['label' => 'مطابقات بفرق غير مغلق', 'kind' => 'count', 'snapshot' => true],
            'unsettled_advances' => ['label' => 'سلف مصروفة غير مسوّاة', 'kind' => 'count', 'snapshot' => true],
            'overdue_advances' => ['label' => 'سلف غير مسوّاة تجاوزت استحقاقها', 'kind' => 'count', 'snapshot' => true],
        ];
    }

    public function ratios(): array
    {
        return [['overdue_advances', 'unsettled_advances']];
    }

    public function sliceable(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return ['reference' => 'البند', 'date' => 'التاريخ', 'currency' => 'العملة', 'amount' => 'المبلغ'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        if ($measure === 'open_reconciliations') {
            return DB::table('finance_reconciliations as r')->where('r.state', 'open')
                ->select(['r.id', 'r.as_of as date'])
                ->selectRaw("CONCAT('مطابقة #', r.id) AS reference")->selectRaw('CAST(NULL AS varchar) AS currency')
                ->selectRaw('(r.actual - r.expected) AS amount')->selectRaw('CAST(NULL AS bigint) AS department_id')->selectRaw('1 AS contribution');
        }
        $query = DB::table('finance_advances as a')->where('a.state', 'disbursed');
        if ($measure === 'overdue_advances') {
            $query->whereNotNull('a.due_on')->where('a.due_on', '<', now()->toDateString());
        }

        return $query->select(['a.id', 'a.due_on as date', 'a.currency', 'a.amount'])
            ->selectRaw("CONCAT('سلفة #', a.id) AS reference")->selectRaw('CAST(NULL AS bigint) AS department_id')->selectRaw('1 AS contribution');
    }
}
