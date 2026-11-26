<?php

namespace App\Services\Kpi\Sources;

use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Active rental contracts ending within the definition's window, one row per contract. */
final class RentalContractsSource extends KpiSource
{
    /** A renewal is under way when a successor contract or a renewal case is in one of these states. */
    public const RENEWAL_CONTRACT = ['draft', 'pending_approval', 'returned', 'approved', 'active'];

    public const RENEWAL_CASE = ['draft', 'in_review', 'returned', 'approved'];

    public function key(): string
    {
        return 'rental_contracts';
    }

    public function label(): string
    {
        return 'عقود الإيجار القريبة من الانتهاء';
    }

    public function basis(): string
    {
        return 'عقود حالتها «فعال» تنتهي من اليوم حتى نهاية نافذة الأيام المحددة في تعريف المؤشر. «دون إجراء تجديد» = لا يوجد لها عقد تجديد (مسودة أو بانتظار الاعتماد أو معتمد أو فعال) ولا معاملة تجديد قائمة أو معتمدة. '
            .'انتهاء العقد لا يعني وجوب تجديده؛ المؤشر ينبّه ولا يقيس الالتزام بمهلة.';
    }

    public function lineage(): array
    {
        return ['aims_rental_contracts: status, ends_on, parent_contract_id, number, auto_renew', 'aims_rental_cases: contract_id, kind=renewal, status'];
    }

    public function measures(): array
    {
        return [
            'expiring' => ['label' => 'عقود تنتهي خلال النافذة', 'kind' => 'count', 'snapshot' => true],
            'expiring_without_renewal' => ['label' => 'عقود تنتهي خلال النافذة دون إجراء تجديد', 'kind' => 'count', 'snapshot' => true],
        ];
    }

    public function ratios(): array
    {
        return [['expiring_without_renewal', 'expiring']];
    }

    public function params(): array
    {
        return ['window_days' => ['label' => 'نافذة الأيام قبل نهاية العقد', 'min' => 1, 'max' => 365]];
    }

    public function sliceable(): bool
    {
        return false;
    }

    public function problem(array $filters, array $params, array $measures = []): ?string
    {
        $days = $params['window_days'] ?? null;

        return is_int($days) && $days >= 1 && $days <= 365 ? null : 'حدد في تعريف المؤشر نافذة أيام بين 1 و365.';
    }

    public function columns(): array
    {
        return ['reference' => 'العقد', 'starts_on' => 'البداية', 'ends_on' => 'النهاية', 'days_left' => 'أيام متبقية', 'auto_renew' => 'تجديد تلقائي في العقد'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $today = now()->startOfDay();
        $query = DB::table('aims_rental_contracts as c')->where('c.status', 'active')
            ->where('c.ends_on', '>=', $today->toDateString())->where('c.ends_on', '<=', $today->copy()->addDays((int) $params['window_days'])->toDateString());
        if ($measure === 'expiring_without_renewal') {
            $query->whereNotExists(fn (Builder $renewal) => $renewal->from('aims_rental_contracts as n')->whereColumn('n.parent_contract_id', 'c.id')->whereIn('n.status', self::RENEWAL_CONTRACT))
                ->whereNotExists(fn (Builder $case) => $case->from('aims_rental_cases as k')->whereColumn('k.contract_id', 'c.id')->where('k.kind', 'renewal')->whereIn('k.status', self::RENEWAL_CASE));
        }

        return $query->select(['c.id', 'c.number as reference', 'c.starts_on', 'c.ends_on', 'c.auto_renew'])
            ->selectRaw('CAST(NULL AS bigint) AS department_id')
            ->selectRaw('(c.ends_on - CAST(? AS date)) AS days_left', [$today->toDateString()])
            ->selectRaw('1 AS contribution');
    }

    public function present(object $row): array
    {
        return ['reference' => $row->reference, 'starts_on' => $row->starts_on, 'ends_on' => $row->ends_on, 'days_left' => $row->days_left, 'auto_renew' => $row->auto_renew ? 'نعم' : 'لا'];
    }
}
