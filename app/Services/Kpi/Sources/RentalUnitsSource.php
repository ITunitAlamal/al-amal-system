<?php

namespace App\Services\Kpi\Sources;

use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Rentable units now, one row per unit. The rentals portfolio belongs to its owning department as a whole. */
final class RentalUnitsSource extends KpiSource
{
    private const EXCLUDED = ['out_of_service', 'maintenance'];

    private const AVAILABILITY = ['vacant' => 'شاغرة', 'rented' => 'مؤجرة', 'reserved' => 'محجوزة'];

    public function key(): string
    {
        return 'rental_units';
    }

    public function label(): string
    {
        return 'الأعيان القابلة للتأجير';
    }

    public function basis(): string
    {
        return 'المقام: الأعيان المعلّمة «قابلة للتأجير» في عقارات فعالة، باستثناء ما هو خارج الخدمة أو تحت الصيانة. البسط: منها ما يرتبط اليوم بعقد حالته «فعال» ويشمل تاريخ اليوم بين بدايته ونهايته. '
            .'قراءة لحالة اليوم؛ لا يرتبط العقار بقسم في المخطط، فالمؤشر للقسم المالك والإدارة العليا.';
    }

    public function lineage(): array
    {
        return ['aims_property_units: rentable, availability, code', 'aims_properties: is_active', 'aims_rental_contract_units: unit_id, contract_id', 'aims_rental_contracts: status, starts_on, ends_on'];
    }

    public function measures(): array
    {
        return [
            'rentable' => ['label' => 'أعيان قابلة للتأجير', 'kind' => 'count', 'snapshot' => true],
            'occupied' => ['label' => 'أعيان مشغولة بعقد فعال', 'kind' => 'count', 'snapshot' => true],
        ];
    }

    public function ratios(): array
    {
        return [['occupied', 'rentable']];
    }

    public function sliceable(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return ['reference' => 'رمز العين', 'name' => 'الاسم', 'property' => 'العقار', 'status' => 'الحالة المسجلة', 'occupied' => 'عقد فعال اليوم'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $today = now()->toDateString();
        $active = fn (Builder $contract) => $contract->from('aims_rental_contract_units as cu')->join('aims_rental_contracts as c', 'c.id', '=', 'cu.contract_id')
            ->whereColumn('cu.unit_id', 'u.id')->where('c.status', 'active')->where('c.starts_on', '<=', $today)->where('c.ends_on', '>=', $today);
        $query = DB::table('aims_property_units as u')->leftJoin('aims_properties as p', 'p.id', '=', 'u.property_id')
            ->where('u.rentable', true)->whereNotIn('u.availability', self::EXCLUDED)
            ->where(fn (Builder $q) => $q->whereNull('p.id')->orWhere('p.is_active', true));
        if ($measure === 'occupied') {
            $query->whereExists($active);
        }

        return $query->select(['u.id', 'u.code as reference', 'u.name', 'p.name as property', 'u.availability'])
            ->selectRaw('CAST(NULL AS bigint) AS department_id')
            ->selectSub(fn (Builder $contract) => $active($contract)->selectRaw('COUNT(*) > 0'), 'occupied')
            ->selectRaw('1 AS contribution');
    }

    public function present(object $row): array
    {
        return ['reference' => $row->reference, 'name' => $row->name, 'property' => $row->property,
            'status' => self::AVAILABILITY[$row->availability] ?? $row->availability, 'occupied' => $row->occupied ? 'نعم' : 'لا'];
    }
}
