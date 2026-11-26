<?php

namespace App\Services\Kpi;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** A local-calendar half-open interval [from, until) in the application time zone. */
final class KpiPeriod
{
    public const MAX_DAYS = 366;

    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $until) {}

    /** The viewer's inclusive dates; the default is the current month up to today. */
    public static function fromInput(?string $from, ?string $to): self
    {
        $today = self::now()->startOfDay();
        $start = $from ? self::parse($from, 'from') : $today->startOfMonth();
        $end = $to ? self::parse($to, 'to') : $today;
        if ($end->greaterThan($today)) {
            throw ValidationException::withMessages(['to' => 'نهاية الفترة لا تتجاوز تاريخ اليوم.']);
        }
        if ($start->greaterThan($end)) {
            throw ValidationException::withMessages(['from' => 'بداية الفترة تقع بعد نهايتها.']);
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'أقصى طول للفترة '.self::MAX_DAYS.' يومًا.']);
        }

        return new self($start, $end->addDay());
    }

    /** The whole calendar month containing the given day. */
    public static function month(CarbonImmutable $day): self
    {
        $start = $day->setTimezone(config('app.timezone'))->startOfMonth();

        return new self($start, $start->addMonth());
    }

    public static function today(): self
    {
        $today = self::now()->startOfDay();

        return new self($today, $today->addDay());
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
    }

    public function includesNow(): bool
    {
        $now = self::now();

        return $this->from->lessThanOrEqualTo($now) && $this->until->greaterThan($now);
    }

    /** The moment balances are read at: the end of the period, or now while it runs. */
    public function asOf(): CarbonImmutable
    {
        $now = self::now();

        return $this->until->lessThan($now) ? $this->until : $now;
    }

    public function lastDay(): string
    {
        return $this->until->subDay()->toDateString();
    }

    /** The day whose KPI definition applies: the period's last day, or today while it runs. */
    public function definitionDate(): string
    {
        return min($this->lastDay(), self::now()->toDateString());
    }

    /** @return array{from: string, to: string, timezone: string} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->lastDay(), 'timezone' => (string) config('app.timezone')];
    }

    public function key(): string
    {
        return $this->from->toDateString().'/'.$this->lastDay();
    }

    private static function parse(string $value, string $field): CarbonImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw ValidationException::withMessages([$field => 'صيغة التاريخ غير صحيحة.']);
        }

        return CarbonImmutable::create((int) $parts[1], (int) $parts[2], (int) $parts[3], 0, 0, 0, config('app.timezone'));
    }
}
