<?php

namespace App\Services\Configuration;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for reading selectable reference values.
 *
 * A value is selectable when it, its type and its group are active and the
 * given date falls inside its effective window. Inactive or expired values
 * stay readable for historical records; they are only hidden from new input.
 */
final class MasterDataLookup
{
    /**
     * @return Collection<int, object{id: int, code: string, name_ar: string, name_en: ?string, parent_id: ?int}>
     */
    public function options(int $typeId, ?CarbonInterface $on = null): Collection
    {
        return $this->selectableQuery($typeId, $on)
            ->orderBy('v.sort_order')
            ->orderBy('v.id')
            ->get(['v.id', 'v.code', 'v.name_ar', 'v.name_en', 'v.parent_id']);
    }

    public function isSelectable(int $valueId, int $typeId, ?CarbonInterface $on = null): bool
    {
        return $this->selectableQuery($typeId, $on)->where('v.id', $valueId)->exists();
    }

    public function label(?int $valueId): ?string
    {
        if ($valueId === null) {
            return null;
        }

        return DB::table('master_data_values')->where('id', $valueId)->value('name_ar');
    }

    private function selectableQuery(int $typeId, ?CarbonInterface $on): Builder
    {
        $date = ($on ?? now())->toDateString();

        return DB::table('master_data_values as v')
            ->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->join('master_data_groups as g', 'g.id', '=', 't.group_id')
            ->where('v.type_id', $typeId)
            ->where('v.is_active', true)
            ->where('t.is_active', true)
            ->where('g.is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('v.effective_from')->orWhere('v.effective_from', '<=', $date))
            ->where(fn (Builder $query) => $query->whereNull('v.effective_to')->orWhere('v.effective_to', '>=', $date));
    }
}
