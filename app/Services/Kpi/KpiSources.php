<?php

namespace App\Services\Kpi;

use App\Services\Kpi\Sources\AttendanceDaysSource;
use App\Services\Kpi\Sources\EmployeeProfilesSource;
use App\Services\Kpi\Sources\FinanceControlsSource;
use App\Services\Kpi\Sources\FinancePaymentsSource;
use App\Services\Kpi\Sources\KpiSource;
use App\Services\Kpi\Sources\LeaveRequestsSource;
use App\Services\Kpi\Sources\RentalCasesSource;
use App\Services\Kpi\Sources\RentalContractsSource;
use App\Services\Kpi\Sources\RentalDuesSource;
use App\Services\Kpi\Sources\RentalUnitsSource;
use App\Services\Kpi\Sources\WorkflowRequestsSource;
use App\Services\Kpi\Sources\WorkflowTasksSource;

/**
 * Registry of approved KPI sources and formulas. Only code adds a source; administrators choose among them.
 */
final class KpiSources
{
    public const UNAVAILABLE = 'unavailable';

    /** Formula => [label, measure kinds it accepts]. */
    public const FORMULAS = [
        'count' => ['عدد السجلات', ['count']],
        'sum' => ['مجموع المبالغ', ['money']],
        'average' => ['متوسط المدة', ['hours']],
        'median' => ['وسيط المدة', ['hours']],
        'ratio' => ['نسبة (بسط ÷ مقام)', ['count', 'money']],
        'percentage' => ['نسبة مئوية', ['count', 'money']],
    ];

    /** Unit code each formula and measure kind may be shown in. */
    public const UNITS = [
        'count' => ['count'],
        'sum' => ['money'],
        'average' => ['hours', 'days'],
        'median' => ['hours', 'days'],
        'ratio' => ['count'],
        'percentage' => ['percent'],
    ];

    private const CLASSES = [
        WorkflowTasksSource::class, WorkflowRequestsSource::class, EmployeeProfilesSource::class, LeaveRequestsSource::class,
        AttendanceDaysSource::class, RentalUnitsSource::class, RentalDuesSource::class, RentalContractsSource::class,
        RentalCasesSource::class, FinancePaymentsSource::class, FinanceControlsSource::class,
    ];

    /** @var array<string, KpiSource>|null */
    private ?array $sources = null;

    /** @return array<string, KpiSource> */
    public function all(): array
    {
        if ($this->sources === null) {
            $this->sources = [];
            foreach (self::CLASSES as $class) {
                $source = app($class);
                $this->sources[$source->key()] = $source;
            }
        }

        return $this->sources;
    }

    public function get(string $key): ?KpiSource
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Everything the administration screen needs to offer valid choices.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalog(): array
    {
        $catalog = [];
        foreach ($this->all() as $key => $source) {
            $catalog[$key] = [
                'label' => $source->label(), 'basis' => $source->basis(), 'lineage' => $source->lineage(),
                'measures' => $source->measures(), 'ratios' => $source->ratios(), 'filters' => $source->filters(),
                'viewer_filters' => $source->viewerFilters(), 'params' => $source->params(), 'sliceable' => $source->sliceable(),
                'attribution' => $source->attribution(),
            ];
        }
        $catalog[self::UNAVAILABLE] = ['label' => 'غير متاح بعد (تسجيل فقط)', 'basis' => 'لا يُحسب رقم قبل توفر مصدر رسمي للبيانات.', 'lineage' => [],
            'measures' => [], 'ratios' => [], 'filters' => [], 'viewer_filters' => [], 'params' => [], 'sliceable' => true, 'attribution' => ''];

        return $catalog;
    }
}
