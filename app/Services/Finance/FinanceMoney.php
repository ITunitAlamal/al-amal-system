<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

/** Integer minor-unit money arithmetic; no binary floating-point in decisions. */
final class FinanceMoney
{
    public static function cents(string|int $amount): int
    {
        $raw = trim((string) $amount);
        if (! preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D', $raw, $m)) {
            throw ValidationException::withMessages(['amount' => 'قيمة مالية غير صالحة (بحد أقصى منزلتان عشريتان)']);
        }
        $whole = (int) $m[2];
        $fraction = (int) str_pad($m[3] ?? '', 2, '0');

        return ($m[1] === '-' ? -1 : 1) * ($whole * 100 + $fraction);
    }

    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Quantity scale 3, unit-price scale 4, round each extended line to 2 decimals. */
    public static function lineCents(string|int $quantity, string|int $unitPrice): int
    {
        $q = self::scaled((string) $quantity, 3, 'quantity');
        $p = self::scaled((string) $unitPrice, 4, 'unit_price');
        if ($q <= 0 || $p < 0 || $q > 100000000 || $p > 1000000000) {
            throw ValidationException::withMessages(['items' => 'كمية أو سعر خارج الحدود المسموحة']);
        }

        // Scales: 1000 * 10000 = 10^7. Cent rounding uses divisor 10^5.
        return intdiv($q * $p + 50000, 100000);
    }

    private static function scaled(string $value, int $places, string $field): int
    {
        $value = trim($value);
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,'.$places.'}))?$/D', $value, $m)) {
            throw ValidationException::withMessages([$field => 'تنسيق عددي غير صالح']);
        }

        return ((int) $m[1] * (10 ** $places)) + (int) str_pad($m[2] ?? '', $places, '0');
    }
}
