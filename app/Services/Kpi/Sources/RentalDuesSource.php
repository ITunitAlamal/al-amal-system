<?php

namespace App\Services\Kpi\Sources;

use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rent dues registered with Accounts, one row per due. The collectible amount is the receivable Accounts
 * recorded (net of the approved adjustment); collections are allocations of executed, unreversed receipts.
 */
final class RentalDuesSource extends KpiSource
{
    public function key(): string
    {
        return 'rental_dues';
    }

    public function label(): string
    {
        return 'استحقاقات الإيجار وتحصيلها';
    }

    public function basis(): string
    {
        return 'العينة استحقاقات إيجار وخدمات مسجلة ذممًا في الحسابات وحلّ موعدها داخل الفترة وحتى اليوم، بعملة واحدة، عدا الملغاة. صافي المستحق = مبلغ الذمة المسجلة في الحسابات ± التسويات المرحّلة قبل نهاية الفترة. '
            .'المحصّل = القبض الذي سجلته الحسابات من البرنامج المحاسبي الخارجي بتاريخ سند قبل نهاية الفترة وغير معكوس، مضافًا إليه تخصيصات سندات القبض المنفذة في AIMS (الوضع المحاسبي الكامل). الإعفاء أو التسوية ليس قبضًا. '
            .'المتبقي = صافي المستحق − المحصّل (لا يقل عن صفر؛ الزيادة رصيد دائن للمستأجر في الحسابات). متأخرات الفترات السابقة تُعرض منفصلة عن مستحقات الفترة. التصحيح اللاحق (عكس قبض) يُطبق على الفترات السابقة؛ القراءات المحفوظة تبقى كما سُجلت.';
    }

    public function lineage(): array
    {
        return [
            'aims_rental_dues: id, contract_id, due_on, amount, adjustment, status',
            'aims_rental_receipt_allocations: due_id, amount, accounting_posted_at, reversed_at (القبض المسجل من البرنامج المحاسبي)',
            'aims_rental_settlements: due_id, effect, amount, status=posted, posted_at',
            'finance_receivables: source_module=rentals, source_reference=due id, amount, currency',
            'finance_payment_allocations: receivable_module=finance, receivable_id, amount, reversed_by_transaction_id, created_at',
            'finance_transactions: kind=receipt, state=executed, executed_at, reversal_of_id',
            'aims_rental_contracts: number',
        ];
    }

    public function measures(): array
    {
        return [
            'dues_count' => ['label' => 'استحقاقات حلّت في الفترة', 'kind' => 'count', 'snapshot' => false],
            'due_net' => ['label' => 'صافي المستحق في الفترة', 'kind' => 'money', 'snapshot' => false],
            'collected' => ['label' => 'المحصّل من مستحقات الفترة', 'kind' => 'money', 'snapshot' => false],
            'outstanding' => ['label' => 'المتبقي من مستحقات الفترة', 'kind' => 'money', 'snapshot' => false],
            'adjustments' => ['label' => 'تسويات مستحقات الفترة (موجبة أو سالبة)', 'kind' => 'money', 'snapshot' => false],
            'arrears_prior' => ['label' => 'متأخرات من فترات سابقة', 'kind' => 'money', 'snapshot' => false],
        ];
    }

    public function ratios(): array
    {
        return [['collected', 'due_net'], ['outstanding', 'due_net']];
    }

    public function viewerFilters(): array
    {
        return ['currency'];
    }

    public function filters(): array
    {
        return ['currency' => 'العملة'];
    }

    public function defaults(): array
    {
        $base = DB::table('aims_currency_settings')->orderBy('id')->value('base_currency_code');

        return $base ? ['currency' => trim($base)] : [];
    }

    public function sliceable(): bool
    {
        return false;
    }

    public function problem(array $filters, array $params, array $measures = []): ?string
    {
        if (empty($filters['currency'])) {
            return 'اختر عملة واحدة؛ لا تُجمع مبالغ بعملات مختلفة.';
        }

        return DB::table('currencies')->where('code', $filters['currency'])->where('is_active', true)->exists()
            ? null : 'العملة المختارة غير فعالة في الثوابت المالية.';
    }

    public function columns(): array
    {
        return ['reference' => 'العقد', 'due_on' => 'الاستحقاق', 'currency' => 'العملة', 'gross' => 'أصل القسط', 'adjustment' => 'التسوية',
            'due_net' => 'صافي المستحق', 'collected' => 'المحصّل', 'outstanding' => 'المتبقي', 'note' => 'ملاحظة'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $asOf = $this->naive($period->asOf());
        $collected = DB::table('finance_payment_allocations as a')
            ->join('finance_transactions as t', 't.id', '=', 'a.transaction_id')
            ->where('a.receivable_module', 'finance')->whereNull('a.reversed_by_transaction_id')->where('a.created_at', '<', $asOf)
            ->where('t.kind', 'receipt')->where('t.state', 'executed')->whereNull('t.reversal_of_id')
            ->whereNotNull('t.executed_at')->where('t.executed_at', '<', $asOf)
            ->whereNotExists(fn (Builder $reversal) => $reversal->from('finance_transactions as x')->whereColumn('x.reversal_of_id', 't.id')->where('x.state', 'executed'))
            ->groupBy('a.receivable_id')->select('a.receivable_id')->selectRaw('SUM(a.amount) AS collected');
        $external = DB::table('aims_rental_receipt_allocations')->whereNull('reversed_at')->where('accounting_posted_at', '<', $asOf)
            ->groupBy('due_id')->select('due_id')->selectRaw('SUM(amount) AS collected');
        $settled = DB::table('aims_rental_settlements')->where('status', 'posted')->whereNotNull('due_id')->where('posted_at', '<', $asOf)->groupBy('due_id')->select('due_id')
            ->selectRaw("SUM(CASE WHEN effect = 'increase' THEN amount ELSE -amount END) AS change");
        $net = '(r.amount + COALESCE(st.change, 0))';
        $paid = '(COALESCE(p.collected, 0) + COALESCE(x.collected, 0))';

        $base = DB::table('aims_rental_dues as d')
            ->join('finance_receivables as r', fn ($join) => $join->on('r.source_reference', '=', DB::raw('CAST(d.id AS VARCHAR)'))->where('r.source_module', '=', 'rentals'))
            ->join('aims_rental_contracts as c', 'c.id', '=', 'd.contract_id')
            ->leftJoinSub($collected, 'p', 'p.receivable_id', '=', DB::raw('CAST(r.id AS VARCHAR)'))
            ->leftJoinSub($external, 'x', 'x.due_id', '=', 'd.id')
            ->leftJoinSub($settled, 'st', 'st.due_id', '=', 'd.id')
            ->where('r.currency', $filters['currency'])->where('d.status', '<>', 'cancelled')
            ->select(['d.id', 'c.number as reference', 'd.due_on', 'r.currency', 'd.amount as gross'])
            ->selectRaw("{$net} AS due_net")
            ->selectRaw('COALESCE(d.adjustment, 0) + COALESCE(st.change, 0) AS adjustment')
            ->selectRaw('CAST(NULL AS bigint) AS department_id')
            ->selectRaw("{$paid} AS collected")
            ->selectRaw("GREATEST({$net} - {$paid}, 0) AS outstanding")
            ->selectRaw('(r.amount <> d.amount + COALESCE(d.adjustment, 0)) AS mismatch');
        $from = $period->from->toDateString();
        if ($measure === 'arrears_prior') {
            $base->where('d.due_on', '<', $from);
        } else {
            $base->where('d.due_on', '>=', $from)->where('d.due_on', '<', $period->until->toDateString())->where('d.due_on', '<=', now()->toDateString());
        }

        $query = DB::query()->fromSub($base, 'q');
        if ($measure === 'arrears_prior') {
            $query->where('q.outstanding', '>', 0);
        }
        $contribution = match ($measure) {
            'dues_count' => '1',
            'due_net' => 'q.due_net',
            'collected' => 'q.collected',
            'outstanding', 'arrears_prior' => 'q.outstanding',
            'adjustments' => 'q.adjustment',
        };

        return $query->select('q.*')->selectRaw($contribution.' AS contribution');
    }

    public function present(object $row): array
    {
        return ['reference' => $row->reference, 'due_on' => $row->due_on, 'currency' => $row->currency, 'gross' => $row->gross, 'adjustment' => $row->adjustment,
            'due_net' => $row->due_net, 'collected' => $row->collected, 'outstanding' => $row->outstanding,
            'note' => $row->mismatch ? 'مبلغ الذمة في الحسابات يختلف عن القسط وتسويته في الإيجارات؛ المعتمد مبلغ الحسابات.' : null];
    }
}
