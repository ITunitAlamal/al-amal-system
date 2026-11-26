<?php

namespace App\Services\Kpi;

/**
 * The records a viewer may aggregate for one KPI: every record of the source (null),
 * or the records attributed to the listed departments.
 */
final class KpiDataScope
{
    /** @param  list<int>|null  $departments */
    public function __construct(public readonly ?array $departments, public readonly string $label) {}

    public function key(): string
    {
        return $this->departments === null ? 'all' : 'departments:'.implode(',', $this->departments);
    }

    /** PostgreSQL array literal of the departments, for overlap checks. */
    public function arrayLiteral(): string
    {
        return '{'.implode(',', array_map('intval', $this->departments ?? [])).'}';
    }
}
