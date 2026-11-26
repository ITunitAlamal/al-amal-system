<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeaveDayCounter
{
    public function days(string $from, string $until, object $settings): float
    {
        $start = CarbonImmutable::parse($from, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($until, 'UTC')->startOfDay();
        $span = $start->diffInDays($end, false);
        if ($span < 0 || $span > 365) {
            throw ValidationException::withMessages(['end_date' => 'يجب أن تكون النهاية بعد البداية وألا يتجاوز الطلب سنة.']);
        }
        if (! $settings->counting_mode) {
            throw ValidationException::withMessages(['start_date' => 'لم تعتمد الإدارة طريقة احتساب أيام الإجازة بعد.']);
        }
        if ($settings->counting_mode === 'calendar') {
            return (float) ($span + 1);
        }
        if ($settings->counting_mode !== 'working') {
            throw ValidationException::withMessages(['start_date' => 'طريقة احتساب الأيام غير معتمدة.']);
        }
        $weekdays = is_string($settings->working_weekdays)
            ? json_decode($settings->working_weekdays, true) : $settings->working_weekdays;
        if (! is_array($weekdays) || ! $weekdays) {
            throw ValidationException::withMessages(['start_date' => 'يجب تحديد أيام العمل الأسبوعية.']);
        }
        $holidays = DB::table('leave_holidays')->whereBetween('holiday_date', [$from, $until])
            ->pluck('holiday_date')->map(fn ($d) => (string) $d)->all();
        $skip = array_fill_keys($holidays, true);
        $total = 0;
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            if (in_array($d->dayOfWeekIso, $weekdays, false) && ! isset($skip[$d->toDateString()])) {
                $total++;
            }
        }
        if ($total === 0) {
            throw ValidationException::withMessages(['start_date' => 'المدة لا تتضمن أي يوم إجازة محسوب.']);
        }

        return (float) $total;
    }
}
