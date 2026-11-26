<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/**
 * Presents one stored daily result as three independent readings, recomputed from the stored facts at read time:
 *
 * - Arrival punctuality (from the verified entry only): on_time (green), grace (yellow), late (red), unknown (grey).
 * - Day state (time-dependent): working, awaiting_exit, complete, incomplete, absent, leave, holiday, rest_day, review, not_started.
 * - Early departure in minutes, independent of punctuality.
 *
 * Minutes are whole minutes: an entry at 08:10:59 for an 08:00 start is 10 minutes late.
 */
final class AttendanceDayView
{
    public const PUNCTUALITY = ['on_time' => 'ملتزم', 'grace' => 'ضمن السماح', 'late' => 'متأخر', 'unknown' => 'غير محسوم'];

    public const DAY_STATES = [
        'working' => 'قيد العمل', 'awaiting_exit' => 'بانتظار الخروج', 'complete' => 'دوام مكتمل', 'incomplete' => 'بصمة ناقصة',
        'absent' => 'غائب', 'leave' => 'إجازة', 'holiday' => 'عطلة رسمية', 'rest_day' => 'يوم راحة', 'review' => 'تحتاج مراجعة',
        'not_started' => 'لم يبدأ الدوام',
    ];

    /** Arabic labels of the stored status codes (exports, dashboards). */
    public const STATUS_LABELS = [
        'present' => 'حضور منتظم', 'late' => 'تأخير بعد مهلة السماح', 'early' => 'خروج مبكر', 'in_progress' => 'قيد العمل',
        'not_started' => 'لم يبدأ الدوام', 'missing_punches' => 'بلا بصمات', 'incomplete_punches' => 'بصمات ناقصة',
        'off' => 'يوم راحة', 'off_day_punch' => 'بصمة في يوم راحة', 'official_holiday' => 'عطلة رسمية', 'emergency_holiday' => 'عطلة طارئة',
        'approved_leave' => 'إجازة معتمدة', 'holiday_or_leave_work_review' => 'عمل في إجازة أو عطلة', 'schedule_missing' => 'بدون جدول دوام',
        'exception' => 'استثناء دوام', 'invalid_schedule' => 'جدول دوام غير صالح', 'grace_unapproved' => 'فترة السماح غير معتمدة',
    ];

    private const REVIEW_NOTES = [
        'holiday_or_leave_work_review' => 'بصمات في يوم إجازة أو عطلة.', 'off_day_punch' => 'بصمات في يوم راحة.',
        'schedule_missing' => 'لا يوجد جدول دوام معتمد للموظف.', 'exception' => 'استثناء دوام بلا أوقات محددة.',
        'invalid_schedule' => 'جدول الدوام غير صالح.', 'grace_unapproved' => 'فترة السماح غير معتمدة في الإعدادات.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function present(object $row, ?CarbonImmutable $now = null): array
    {
        $tz = config('app.timezone');
        $now ??= CarbonImmutable::now($tz);
        $at = fn (mixed $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value)->setTimezone($tz) : null;
        $expectedIn = $at($row->expected_in ?? null);
        $expectedOut = $at($row->expected_out ?? null);
        $in = $at($row->actual_in ?? null);
        $out = $at($row->actual_out ?? null);
        $grace = ($row->grace_minutes ?? null) === null ? null : (int) $row->grace_minutes;
        $explanation = is_string($row->explanation ?? null) ? (json_decode($row->explanation, true) ?: []) : (array) ($row->explanation ?? []);
        $potentialEntry = $at($explanation['potential_entry']['at'] ?? null);
        $potentialExit = $at($explanation['potential_exit']['at'] ?? null);
        $status = (string) ($row->status ?? '');
        $open = $expectedOut && $now->lessThan($expectedOut->addHours(AttendanceDailyService::WINDOW_HOURS));
        $ordered = $in && $out && $out->greaterThan($in);

        $misordered = $in && $out && ! $ordered;
        // Exit before entry means both buttons are in doubt: the entry time is not a verified arrival.
        $actualLate = $in && $expectedIn && ! $misordered ? max(0, $this->minutes($expectedIn, $in)) : null;
        $punctuality = $actualLate !== null && $grace !== null ? $this->classify($actualLate, $grace) : 'unknown';
        $provisional = null;
        if ($punctuality === 'unknown' && $potentialEntry && $expectedIn && $grace !== null) {
            $provisional = 'مبدئيًا: '.self::PUNCTUALITY[$this->classify(max(0, $this->minutes($expectedIn, $potentialEntry)), $grace)];
        }
        $early = $out && $expectedOut && (! $in || $ordered) ? max(0, $this->minutes($out, $expectedOut)) : null;

        $notes = [];
        if ($potentialEntry) {
            $notes[] = 'دخول محتمل — يحتاج مراجعة: بصمة «خروج» الساعة '.$potentialEntry->format('H:i').' قرب بداية الدوام.';
        }
        if ($potentialExit) {
            $notes[] = 'خروج محتمل — يحتاج مراجعة: بصمة «دخول» الساعة '.$potentialExit->format('H:i').' قرب نهاية الدوام.';
        }
        if ($misordered) {
            $notes[] = 'ترتيب البصمات غير منطقي: الخروج قبل الدخول؛ يُرجح عكس الزرين.';
        }
        if (($explanation['unknown_punches'] ?? 0) > 0) {
            $notes[] = $explanation['unknown_punches'].' بصمة برمز غير معروف لم تُحتسب.';
        }
        if (isset(self::REVIEW_NOTES[$status])) {
            $notes[] = self::REVIEW_NOTES[$status];
        }

        [$state, $hint] = $this->dayState($status, $now, $expectedIn, $expectedOut, $in, $out, $open, (bool) ($potentialEntry || $potentialExit || $misordered));
        $label = self::DAY_STATES[$state];
        if ($state === 'review' && $potentialEntry) {
            $label = 'دخول محتمل — يحتاج مراجعة';
        } elseif ($state === 'review' && $potentialExit) {
            $label = 'خروج محتمل — يحتاج مراجعة';
        } elseif ($state === 'holiday' && $status === 'emergency_holiday') {
            $label = 'عطلة طارئة';
        }
        if (in_array($state, ['leave', 'holiday'], true) && ! empty($explanation['absence']['label'])) {
            $hint = (string) $explanation['absence']['label'];
        }

        return [
            'work_date' => (string) $row->work_date,
            'expected_in' => $expectedIn?->format('H:i'),
            'expected_out' => $expectedOut ? $expectedOut->format('H:i').($expectedOut->toDateString() !== (string) $row->work_date ? ' (+1)' : '') : null,
            'actual_in' => $in?->format('H:i'),
            'actual_out' => $out ? $out->format('H:i').($out->toDateString() !== (string) $row->work_date ? ' (+1)' : '') : null,
            'attendance_minutes' => $ordered ? $this->minutes($in, $out) : null,
            'elapsed_minutes' => $in && ! $out && in_array($state, ['working', 'awaiting_exit'], true) ? max(0, $this->minutes($in, $now)) : null,
            'actual_late_minutes' => $actualLate,
            'grace_minutes' => $grace,
            'grace_used_minutes' => $actualLate !== null && $grace !== null && $actualLate > 0 ? min($actualLate, $grace) : null,
            'chargeable_late_minutes' => $actualLate !== null && $grace !== null ? max(0, $actualLate - $grace) : null,
            'early_minutes' => $early,
            'punctuality' => $punctuality,
            'punctuality_label' => self::PUNCTUALITY[$punctuality],
            'punctuality_provisional' => $provisional,
            'day_state' => $state,
            'day_label' => $label,
            'day_hint' => $hint,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? $status,
            'notes' => $notes,
            'needs_review' => $state === 'review' || $notes !== [],
        ];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function dayState(string $status, CarbonImmutable $now, ?CarbonImmutable $expectedIn, ?CarbonImmutable $expectedOut, ?CarbonImmutable $in, ?CarbonImmutable $out, bool $open, bool $suspect): array
    {
        $running = fn (): array => $expectedOut && $now->lessThan($expectedOut) ? ['working', null] : ['awaiting_exit', 'انتهى الدوام المقرر ولم تُسجل بصمة خروج بعد.'];

        return match ($status) {
            'present', 'late', 'early' => ['complete', null],
            'in_progress' => $open ? $running() : ['incomplete', 'لا توجد بصمة خروج.'],
            'incomplete_punches' => $suspect ? ['review', null]
                : ($in && ! $out && $open ? $running() : ['incomplete', $in && ! $out ? 'لا توجد بصمة خروج.' : (! $in ? 'لا توجد بصمة دخول.' : null)]),
            'missing_punches', 'not_started' => $expectedIn && $now->lessThan($expectedIn) ? ['not_started', null]
                : ['absent', $open ? 'لم يسجل حضورًا حتى الآن.' : 'لا توجد بصمات في يوم عمل.'],
            'approved_leave' => ['leave', null],
            'official_holiday', 'emergency_holiday' => ['holiday', null],
            'off' => ['rest_day', null],
            default => ['review', null],
        };
    }

    private function classify(int $lateMinutes, int $grace): string
    {
        return $lateMinutes === 0 ? 'on_time' : ($lateMinutes <= $grace ? 'grace' : 'late');
    }

    private function minutes(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}
