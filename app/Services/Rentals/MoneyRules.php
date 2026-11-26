<?php

namespace App\Services\Rentals;

use InvalidArgumentException;

/** Pure money helpers. Amounts are represented as integer minor units. */
final class MoneyRules
{
    public static function toMinor(string|int|float $value): int
    {
        $v = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $v)) {
            throw new InvalidArgumentException('Invalid money value');
        }
        [$whole,$fraction] = array_pad(explode('.', $v, 2), 2, '');
        if (strlen($whole) > 14) {
            throw new InvalidArgumentException('Money exceeds supported range');
        }

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function balance(int $dueMinor, int $adjustmentMinor, int $postedMinor): int
    {
        return max(0, $dueMinor + $adjustmentMinor - $postedMinor);
    }

    public static function assertAllocatable(int $balanceMinor, int $allocationMinor): void
    {
        if ($allocationMinor <= 0 || $allocationMinor > $balanceMinor) {
            throw new InvalidArgumentException('Invalid allocation');
        }
    }
}
