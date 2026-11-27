<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;

/**
 * The last result of each scheduled command (routes/console.php): when it last succeeded and last failed, and how
 * many runs in a row failed. Kept in the cache store; SystemReadiness lists the commands whose latest run failed.
 * A run skipped because the previous one is still going records nothing.
 */
final class SchedulerHeartbeat
{
    private const PREFIX = 'aims:scheduler:';

    private const INDEX = 'aims:scheduler:index';

    /** Records the outcome of every scheduled command event. */
    public static function watch(iterable $events): void
    {
        foreach ($events as $event) {
            if (! $event instanceof Event || ($name = self::nameOf($event)) === null) {
                continue;
            }
            $event->onSuccess(fn () => self::record($name, true))->onFailure(fn () => self::record($name, false));
        }
    }

    public static function record(string $command, bool $succeeded): void
    {
        try {
            $state = Cache::get(self::PREFIX.$command, []);
            $now = now()->toIso8601String();
            $state = $succeeded
                ? [...$state, 'last_success_at' => $now, 'consecutive_failures' => 0]
                : [...$state, 'last_failure_at' => $now, 'consecutive_failures' => (int) ($state['consecutive_failures'] ?? 0) + 1];
            Cache::forever(self::PREFIX.$command, $state);
            $index = Cache::get(self::INDEX, []);
            if (! in_array($command, $index, true)) {
                Cache::forever(self::INDEX, [...$index, $command]);
            }
        } catch (\Throwable) {
            // The heartbeat never makes a scheduled command fail.
        }
    }

    /**
     * Commands whose latest recorded run failed.
     *
     * @return list<array{command: string, last_failure_at: string, last_success_at: ?string, consecutive_failures: int}>
     */
    public static function failing(): array
    {
        $failing = [];
        foreach (Cache::get(self::INDEX, []) as $command) {
            $state = Cache::get(self::PREFIX.$command, []);
            if (($state['consecutive_failures'] ?? 0) > 0 && isset($state['last_failure_at'])) {
                $failing[] = ['command' => (string) $command, 'last_failure_at' => (string) $state['last_failure_at'],
                    'last_success_at' => $state['last_success_at'] ?? null, 'consecutive_failures' => (int) $state['consecutive_failures']];
            }
        }

        return $failing;
    }

    /** The last successful run of one command (e.g. "audit:seal --verify"), or null. */
    public static function lastSuccess(string $command): ?string
    {
        try {
            return Cache::get(self::PREFIX.$command, [])['last_success_at'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** "attendance:sync", "audit:seal --verify": the artisan command and its options, without the PHP binary. */
    private static function nameOf(Event $event): ?string
    {
        if (! is_string($event->command) || preg_match("/artisan['\"]?\\s+(.+)$/", $event->command, $match) !== 1) {
            return null;
        }
        $name = trim(preg_replace('/\\s+/', ' ', str_replace(["'", '"'], '', $match[1])) ?? '');

        return $name === '' ? null : mb_substr($name, 0, 120);
    }
}
