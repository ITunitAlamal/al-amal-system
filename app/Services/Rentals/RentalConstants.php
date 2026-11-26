<?php

namespace App\Services\Rentals;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The reference lists the rentals file reads from the central «الثوابت» (never its own copy): asset kinds,
 * ownership, unit types and usages, services, document types, settlement types, termination reasons and
 * payment methods.
 */
final class RentalConstants
{
    public const TYPES = ['asset_types', 'property_ownership_types', 'rental_unit_types', 'rental_unit_usages', 'rental_services',
        'rental_document_types', 'rental_settlement_types', 'rental_termination_reasons', 'payment_methods'];

    /**
     * Active values per list, for the forms.
     *
     * @return array<string, list<array{id: int, code: string, name: string}>>
     */
    public function options(): array
    {
        $rows = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->whereIn('t.code', self::TYPES)->where('t.is_active', true)->where('v.is_active', true)
            ->orderBy('v.sort_order')->orderBy('v.name_ar')->get(['t.code as type', 'v.id', 'v.code', 'v.name_ar']);
        $options = array_fill_keys(self::TYPES, []);
        foreach ($rows as $row) {
            $options[$row->type][] = ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name_ar];
        }

        return $options;
    }

    /** Throws a field error when the value is not an active value of the list. */
    public function assert(string $type, ?int $valueId, string $field, bool $required = false): void
    {
        if ($valueId === null) {
            if ($required) {
                throw ValidationException::withMessages([$field => 'اختر قيمة من القائمة.']);
            }

            return;
        }
        $valid = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('v.id', $valueId)->where('t.code', $type)->where('v.is_active', true)->where('t.is_active', true)->exists();
        if (! $valid) {
            throw ValidationException::withMessages([$field => 'القيمة المختارة ليست من الثوابت الفعالة لهذه القائمة.']);
        }
    }

    public function idFor(string $type, string $code): ?int
    {
        $id = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
            ->where('t.code', $type)->where('v.code', $code)->value('v.id');

        return $id ? (int) $id : null;
    }
}
