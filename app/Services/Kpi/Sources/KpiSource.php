<?php

namespace App\Services\Kpi\Sources;

use App\Models\User;
use App\Services\Kpi\KpiDataScope;
use App\Services\Kpi\KpiPeriod;
use Illuminate\Database\Query\Builder;

/**
 * A registered KPI data source. Tables, columns and SQL live only in these classes: a definition picks
 * a source key, measure keys and whitelisted filter values, never an expression or an identifier.
 *
 * Every rows() query returns one row per counted entity with at least `id`, `department_id`
 * (null when the record is not attributable to a department) and a numeric `contribution`
 * (1 for counts, an amount for money, hours for durations).
 */
abstract class KpiSource
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** Inclusion, exclusion, time basis and attribution, in Arabic. */
    abstract public function basis(): string;

    /** @return list<string> tables and columns read */
    abstract public function lineage(): array;

    /** @return array<string, array{label: string, kind: string, snapshot: bool}> kind: count|money|hours */
    abstract public function measures(): array;

    /** @return array<string, string> drill-down column => Arabic label */
    abstract public function columns(): array;

    abstract public function rows(string $measure, KpiDataScope $scope, KpiPeriod $period, array $filters, array $params): Builder;

    /** @return list<array{0: string, 1: string}> meaningful numerator/denominator pairs */
    public function ratios(): array
    {
        return [];
    }

    /** @return array<string, string> filters a definition may fix: key => label */
    public function filters(): array
    {
        return [];
    }

    /** @return list<string> filter keys a viewer may set on the dashboard */
    public function viewerFilters(): array
    {
        return [];
    }

    /** @return array<string, array{label: string, min: int, max: int}> */
    public function params(): array
    {
        return [];
    }

    /** @return array<string, mixed> viewer filter defaults, e.g. the base currency */
    public function defaults(): array
    {
        return [];
    }

    /** Whether rows carry the department they belong to. */
    public function sliceable(): bool
    {
        return true;
    }

    /** What a row's department means. */
    public function attribution(): string
    {
        return '';
    }

    /**
     * Why the source cannot compute these measures with these filters and parameters, or null.
     *
     * @param  list<string>  $measures
     */
    public function problem(array $filters, array $params, array $measures = []): ?string
    {
        return null;
    }

    /**
     * Display values for one drill-down row, keyed by columns().
     *
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        return array_intersect_key((array) $row, $this->columns());
    }

    /** A page the viewer is already allowed to open for this row, or null. */
    public function link(object $row, User $viewer): ?string
    {
        return null;
    }

    /** Whether the given measure describes the present moment rather than a period. */
    public function isSnapshot(string $measure): bool
    {
        return (bool) ($this->measures()[$measure]['snapshot'] ?? false);
    }

    protected function naive(\DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    protected function restrictTo(Builder $query, string $column, KpiDataScope $scope): Builder
    {
        return $scope->departments === null ? $query : $query->whereIn($column, $scope->departments === [] ? [0] : $scope->departments);
    }
}
