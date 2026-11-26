<?php

namespace App\Services\Rentals;

use Carbon\CarbonImmutable;

final class RentalSchedule
{
    /** @return list<string> */
    public function dates(string $startsOn, string $endsOn, int $months): array
    {
        if ($months < 1) {
            throw new \InvalidArgumentException('The installment interval must be positive.');
        }
        $anchor = CarbonImmutable::parse($startsOn);
        $end = CarbonImmutable::parse($endsOn);
        $dates = [];
        for ($offset = 0; ; $offset += $months) {
            $date = $anchor->addMonthsNoOverflow($offset);
            if ($date->greaterThan($end)) {
                break;
            }
            $dates[] = $date->toDateString();
        }

        return $dates;
    }
}
