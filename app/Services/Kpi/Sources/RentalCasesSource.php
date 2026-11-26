<?php

namespace App\Services\Kpi\Sources;

use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Rental cases (renewal, eviction, settlement…), one row per case, decided administratively. */
final class RentalCasesSource extends KpiSource
{
    public const KINDS = ['renewal' => 'تجديد', 'adjustment' => 'تعديل', 'settlement' => 'تسوية', 'eviction' => 'إخلاء', 'maintenance' => 'صيانة', 'inspection' => 'معاينة'];

    private const STATUS = ['draft' => 'مسودة', 'in_review' => 'قيد الفحص والمخالصة والقرار', 'returned' => 'مُعادة', 'approved' => 'معتمدة بانتظار الاستلام',
        'completed' => 'مكتملة', 'rejected' => 'مرفوضة', 'cancelled' => 'ملغاة'];

    public function key(): string
    {
        return 'rental_cases';
    }

    public function label(): string
    {
        return 'معاملات الإيجار (تجديد، إخلاء، تسوية…)';
    }

    public function basis(): string
    {
        return 'معاملة الإيجار سجل واحد. «مبتوت فيها» = موافق عليها أو مرفوضة وتاريخ قرارها داخل الفترة. زمن البت من فتح المعاملة حتى القرار الإداري؛ وحدة الإيجارات لا تسجل بعد تاريخ التنفيذ الفعلي للتسليم أو الإخلاء.';
    }

    public function lineage(): array
    {
        return ['aims_rental_cases: kind, status, created_at, decided_at, contract_id', 'aims_rental_contracts: number'];
    }

    public function measures(): array
    {
        return [
            'awaiting' => ['label' => 'معاملات بانتظار القرار', 'kind' => 'count', 'snapshot' => true],
            'decided' => ['label' => 'معاملات بُت فيها في الفترة', 'kind' => 'count', 'snapshot' => false],
            'decision_hours' => ['label' => 'ساعات من فتح المعاملة حتى القرار', 'kind' => 'hours', 'snapshot' => false],
        ];
    }

    public function filters(): array
    {
        return ['case_kind' => 'نوع معاملة الإيجار'];
    }

    public function sliceable(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return ['reference' => 'العقد', 'kind' => 'النوع', 'status' => 'الحالة', 'created_at' => 'فُتحت', 'decided_at' => 'القرار'];
    }

    public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder
    {
        $query = DB::table('aims_rental_cases as k')->leftJoin('aims_rental_contracts as c', 'c.id', '=', 'k.contract_id');
        if (! empty($filters['case_kind'])) {
            $query->where('k.kind', $filters['case_kind']);
        }
        if ($measure === 'awaiting') {
            $query->where('k.status', 'in_review');
        } else {
            $query->whereIn('k.status', ['approved', 'completed', 'rejected'])->whereNotNull('k.decided_at')
                ->where('k.decided_at', '>=', $this->naive($period->from))->where('k.decided_at', '<', $this->naive($period->until));
        }

        return $query->select(['k.id', 'c.number as reference', 'k.kind', 'k.status', 'k.created_at', 'k.decided_at'])
            ->selectRaw('CAST(NULL AS bigint) AS department_id')
            ->selectRaw(($measure === 'decision_hours' ? 'EXTRACT(EPOCH FROM (k.decided_at - k.created_at)) / 3600.0' : '1').' AS contribution');
    }

    public function present(object $row): array
    {
        return ['reference' => $row->reference ?? 'معاملة #'.$row->id, 'kind' => self::KINDS[$row->kind] ?? $row->kind, 'status' => self::STATUS[$row->status] ?? $row->status,
            'created_at' => $row->created_at, 'decided_at' => $row->decided_at];
    }
}
