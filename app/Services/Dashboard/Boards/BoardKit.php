<?php

namespace App\Services\Dashboard\Boards;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Building blocks of a role dashboard ("board"). Every number comes from a scoped query; a part without a real source
 * carries an Arabic `unavailable` reason instead of a value, and no comparison is shown when none exists.
 *
 * Board: key, title, decision, scope, hero, kpis[], trend, bars, table, actions[], definitions{}.
 */
final class BoardKit
{
    public const WEEKS = 12;

    /**
     * @param  'up'|'down'|null  $better  which direction is favourable (null = neutral, e.g. head counts)
     * @param  array{previous: int, current?: int, label: string}|null  $comparison
     */
    public static function metric(string $label, ?int $value, ?string $better = null, ?array $comparison = null, ?string $detail = null, ?string $url = null, ?string $unavailable = null, ?string $unit = null): array
    {
        return compact('label', 'value', 'better', 'comparison', 'detail', 'url', 'unavailable', 'unit');
    }

    public static function unavailable(string $label, string $reason): array
    {
        return self::metric($label, null, unavailable: $reason);
    }

    /** The last 7 days against the 7 days before (same length, so comparable). */
    public static function weekOverWeek(Builder $query, string $column, string $label = 'خلال آخر 7 أيام'): array
    {
        $now = now();
        $current = (clone $query)->where($column, '>', $now->copy()->subDays(7))->where($column, '<=', $now)->count();
        $previous = (clone $query)->where($column, '>', $now->copy()->subDays(14))->where($column, '<=', $now->copy()->subDays(7))->count();

        return ['current' => $current, 'previous' => $previous, 'label' => $label];
    }

    /**
     * Rolling 7-day windows ending now, oldest first. Zero is a real value (the window had nothing).
     *
     * @return list<int>
     */
    public static function weekly(Builder $query, string $column, int $weeks = self::WEEKS, ?string $sum = null): array
    {
        $end = now();
        $rows = (clone $query)->where($column, '>', $end->copy()->subDays(7 * $weeks))->where($column, '<=', $end)
            ->selectRaw('floor(extract(epoch from (CAST(? AS timestamptz) - CAST('.$column.' AS timestamptz))) / 604800)::int AS w, '.($sum ? 'COALESCE(SUM('.$sum.'), 0)' : 'count(*)').' AS n', [$end->toIso8601String()])
            ->groupBy('w')->pluck('n', 'w');
        $values = [];
        for ($w = $weeks - 1; $w >= 0; $w--) {
            $values[] = (int) round((float) ($rows[$w] ?? 0));
        }

        return $values;
    }

    /** End date of each weekly window, oldest first (for the axis labels). @return list<string> */
    public static function weekEnds(int $weeks = self::WEEKS): array
    {
        return array_map(fn (int $w): string => now()->subDays(7 * $w)->toDateString(), range($weeks - 1, 0));
    }

    /**
     * @param  list<array{label: string, values: list<int>}>  $series
     */
    public static function trend(string $title, string $definition, array $series, ?string $unavailable = null, string $unit = ''): array
    {
        $empty = $series === [] || collect($series)->every(fn (array $s): bool => array_sum($s['values']) === 0);

        return ['title' => $title, 'definition' => $definition, 'weeks' => self::weekEnds(), 'series' => $series, 'unit' => $unit,
            'unavailable' => $unavailable ?? ($empty ? 'لا توجد حركة مسجلة خلال آخر 12 أسبوعًا لعرض اتجاه.' : null)];
    }

    /**
     * @param  list<array{label: string, value: int, url?: string|null}>  $items
     */
    public static function bars(string $title, string $definition, array $items, ?string $unavailable = null): array
    {
        $items = array_values(array_filter($items, fn (array $i): bool => (int) $i['value'] > 0));
        usort($items, fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return ['title' => $title, 'definition' => $definition, 'items' => array_slice($items, 0, 12),
            'unavailable' => $unavailable ?? ($items === [] ? 'لا توجد بيانات لهذا التوزيع ضمن نطاقك.' : null)];
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array{cells: array<string, string|null>, status?: array{label: string, tone: string}|null, url?: string|null, action?: string, overdue?: bool}>  $rows
     */
    public static function table(string $title, array $columns, array $rows, string $empty, ?string $more = null): array
    {
        return compact('title', 'columns', 'rows', 'empty', 'more');
    }

    public static function due(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /** Age in whole days, for "عمر الطلب". */
    public static function age(?string $since): ?string
    {
        if (! $since) {
            return null;
        }
        $days = (int) Carbon::parse($since)->startOfDay()->diffInDays(today());

        return $days === 0 ? 'اليوم' : ($days === 1 ? 'يوم' : $days.' يوم');
    }
}
