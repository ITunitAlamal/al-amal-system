<?php

namespace App\Services\Kpi;

use App\Models\User;

/**
 * The few KPIs placed on the personal home page: those marked for the executive dashboard for
 * institution-wide viewers, those marked for department heads otherwise (their own domain first).
 * Placement only selects among KPIs the viewer may already see.
 */
final class KpiHome
{
    public const EXECUTIVE_LIMIT = 6;

    public const DEPARTMENT_LIMIT = 5;

    public function __construct(private readonly KpiEngine $engine, private readonly KpiAccess $access) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(User $user, ?int $limit = null): ?array
    {
        $scope = KpiScope::resolve($user);
        if (! $scope) {
            return null;
        }
        $placement = $scope->institutional ? 'on_executive_home' : 'on_department_home';
        $definitions = $this->engine->definitions($scope)->filter(fn (object $definition): bool => (bool) $definition->{$placement})
            ->sortBy(fn (object $definition): array => [$scope->institutional || $this->access->ownerView($definition, $scope) ? 0 : 1, (int) $definition->sort_order])
            ->take($limit ?? ($scope->institutional ? self::EXECUTIVE_LIMIT : self::DEPARTMENT_LIMIT));
        $period = KpiPeriod::fromInput(null, null);
        $names = $this->engine->departmentNames();

        return [
            'title' => $scope->institutional ? 'مؤشرات أداء الجمعية' : 'مؤشرات أداء قسمي',
            'scope' => $scope->institutional ? 'المؤسسة كلها' : implode('، ', array_map(fn (int $id): string => $names[$id] ?? '', $scope->departments)),
            'period' => $period->toArray(),
            'url' => route('kpi.index', [], false),
            'cards' => $definitions->map(fn (object $definition): array => array_intersect_key($this->engine->evaluate($definition, $scope, $period),
                array_flip(['id', 'code', 'title', 'value', 'numerator', 'denominator', 'sample_size', 'status', 'reason', 'unit', 'unit_code', 'currency',
                    'target', 'target_met', 'threshold_status', 'polarity', 'snapshot', 'numerator_label', 'denominator_label', 'computed_at', 'scope_label'])))->values()->all(),
        ];
    }
}
