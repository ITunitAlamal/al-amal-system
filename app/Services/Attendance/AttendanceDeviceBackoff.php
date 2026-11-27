<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use Illuminate\Support\Facades\Schema;

/**
 * How long an unreachable attendance device is left alone before the next automatic attempt (TRANSFORM §21).
 * Each failure in a row doubles the wait, from the base interval up to 15 minutes; a success clears it.
 * attendance:sync and attendance:probe-devices log a device's failure once when it starts or its error changes,
 * and once when it recovers, instead of on every scheduler run.
 */
final class AttendanceDeviceBackoff
{
    public const MAX_DELAY_SECONDS = 900;

    private static ?bool $ready = null;

    /** The retry columns exist (migration 2026_11_30_000029); without them the commands keep their old behaviour. */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('attendance_devices', 'sync_retry_at')
            && Schema::hasColumn('attendance_devices', 'probe_retry_at')
            && Schema::hasColumn('attendance_sync_runs', 'attempts');
    }

    /** Seconds to wait after the given number of failures in a row. */
    public static function delay(int $failures, int $baseSeconds): int
    {
        $base = max(30, $baseSeconds);

        return (int) min(self::MAX_DELAY_SECONDS, $base * (2 ** min(max(0, $failures - 1), 10)));
    }
}
