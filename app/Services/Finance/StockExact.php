<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Signed stock ledger arithmetic with exact integer scales (quantity 3dp, unit cost 4dp). */
final class StockExact
{
    public static function scaled(string|int $value, int $places): int
    {
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,'.$places.'}))?$/D', trim((string) $value), $m)) {
            throw ValidationException::withMessages(['quantity' => 'كمية أو تكلفة غير صالحة']);
        }
        $base = 10 ** $places;
        if ((int) $m[1] > intdiv(PHP_INT_MAX, $base)) {
            throw ValidationException::withMessages(['amount' => 'رقم خارج النطاق الآمن']);
        }

        return (int) $m[1] * $base + (int) str_pad($m[2] ?? '', $places, '0');
    }

    public static function decimal(int $value, int $places): string
    {
        return intdiv($value, 10 ** $places).'.'.str_pad((string) ($value % 10 ** $places), $places, '0', STR_PAD_LEFT);
    }

    public static function balance(int $warehouse, int $item): array
    {
        $qty = 0;
        $value = 0;
        foreach (DB::table('finance_stock_movements')->where('warehouse_id', $warehouse)->where('stock_item_id', $item)->orderBy('id')->get() as $m) {
            $q = self::scaled((string) $m->quantity, 3);
            $c = self::scaled((string) $m->unit_cost, 4);
            $in = in_array($m->kind, ['receipt', 'return_in', 'adjust_in'], true);
            if ($in) {
                $qty += $q;
                $value += $q * $c;
            } else {
                $qty -= $q;
                $value -= $q * $c;
            }
            if ($qty < 0) {
                throw ValidationException::withMessages(['stock' => 'تسلسل الحركات تسبب في رصيد سالب']);
            }
            if ($qty === 0) {
                $value = 0;
            }
        }

        return ['qty' => $qty, 'value' => $value, 'average' => $qty ? intdiv($value + intdiv($qty, 2), $qty) : 0];
    }

    public static function movement(array $d, int $actor): int
    {
        return DB::transaction(function () use ($d, $actor) {
            $warehouse = DB::table('finance_warehouses')->where('id', $d['warehouse_id'])->lockForUpdate()->first();
            $item = DB::table('finance_stock_items')->where('id', $d['stock_item_id'])->first();
            if (! $warehouse || ! $warehouse->is_active || ! $item || ! $item->is_active) {
                throw ValidationException::withMessages(['item' => 'المخزن أو الصنف غير فعال']);
            }
            $b = self::balance($d['warehouse_id'], $d['stock_item_id']);
            $q = self::scaled((string) $d['quantity'], 3);
            if ($q <= 0) {
                throw ValidationException::withMessages(['quantity' => 'الكمية غير صالحة']);
            }
            $incoming = in_array($d['kind'], ['receipt', 'return_in', 'adjust_in'], true);
            if (! $incoming && $q > $b['qty']) {
                throw ValidationException::withMessages(['quantity' => 'الرصيد غير كاف']);
            }
            $cost = $incoming ? self::scaled((string) ($d['unit_cost'] ?? 0), 4) : $b['average'];
            if ($q > 100000000 || $cost > 1000000000) {
                throw ValidationException::withMessages(['amount' => 'قيمة المخزون خارج الحدود الآمنة']);
            }
            if (! $incoming && $b['qty'] <= 0) {
                throw ValidationException::withMessages(['stock' => 'لا يمكن الصرف من رصيد صفر']);
            }

            return DB::table('finance_stock_movements')->insertGetId([
                'warehouse_id' => $d['warehouse_id'], 'stock_item_id' => $d['stock_item_id'], 'kind' => $d['kind'],
                'quantity' => self::decimal($q, 3), 'unit_cost' => self::decimal($cost, 4),
                'reference_type' => $d['reference_type'] ?? null, 'reference_id' => $d['reference_id'] ?? null,
                'created_by' => $actor, 'note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
