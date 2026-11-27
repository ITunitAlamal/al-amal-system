<?php

declare(strict_types=1);

namespace App\Services\Organization;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * A role assignment (department_user_roles) counts only within its appointment period: starts_on and ends_on
 * are inclusive dates, and null on either side means open. An assignment in a department also needs that
 * department to be active and not archived (an organization-wide assignment has no department). Every authority
 * check that reads an assignment uses this one rule, so an ended or future-dated appointment, or one left in a
 * stopped or archived department, grants nothing.
 */
final class EffectiveAssignments
{
    public static function apply(QueryBuilder|EloquentBuilder $q, string $alias = 'department_user_roles'): mixed
    {
        return $q->whereRaw(self::sql($alias));
    }

    public static function sql(string $alias = 'department_user_roles'): string
    {
        $alias = self::alias($alias);

        return "(({$alias}.starts_on is null or {$alias}.starts_on <= current_date) and ({$alias}.ends_on is null or {$alias}.ends_on >= current_date)"
            ." and ({$alias}.department_id is null or exists (select 1 from departments ea_department where ea_department.id = {$alias}.department_id"
            .' and ea_department.is_active and ea_department.deleted_at is null)))';
    }

    private static function alias(string $alias): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid table alias.');
        }

        return $alias;
    }
}
