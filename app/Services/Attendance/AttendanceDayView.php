<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/**
 * Presents one stored daily result as three independent readings, recomputed from the stored facts at read time:
 *
 * - Arrival punctuality (from the verified entry only): on_time (green), grace (yellow), late (red), unknown (grey);
 *   excused (blue: the lateness beyond the grace is covered by an approved permission); flexible (no fixed start).
 * - Day state (time-dependent): working, awaiting_exit, complete, incomplete, absent, leave, holiday, rest_day, review,
 *   not_started, no_duty («لا يوجد دوام مقرر»), exempt («معفى من البصمة»), not_enrolled («غير مسجل على جهاز البصمة»).
 * - Early departure in minutes, independent of punctuality.
 *
 * Minutes are whole minutes: an entry at 08:10:59 for an 08:00 start is 10 minutes late. Gross lateness is what was
 * punched; the excused part (approved permission) and the grace are shown separately from the chargeable minutes.
 * "notes" need a review; "info" only explain the day (shift, swap, exemption, permission).
 */
final class AttendanceDayView
{
    public const PUNCTUALITY = ['on_time' => 'ملتزم', 'grace' => 'ضمن السماح', 'late' => 'متأخر', 'excused' => 'تأخير بإذن', 'flexible' => 'دوام مرن', 'unknown' => 'غير محسوم'];

    public const DAY_STATES = [
        'working' => 'قيد العمل', 'awaiting_exit' => 'بانتظار الخروج', 'complete' => 'دوام مكتمل', 'incomplete' => 'بصمة ناقصة',
        'absent' => 'غائب', 'leave' => 'إجازة', 'holiday' => 'عطلة رسمية', 'rest_day' => 'يوم راحة', 'review' => 'تحتاج مراجعة',
        'not_started' => 'لم يبدأ الدوام', 'no_duty' => 'لا يوجد دوام مقرر', 'exempt' => 'معفى من البصمة', 'not_enrolled' => 'غير مسجل على جهاز البصمة',
    ];

    /** Arabic labels of the stored status codes (exports, dashboards). */
    public const STATUS_LABELS = [
        'present' => 'حضور منتظم', 'late' => 'تأخير بعد مهلة السماح', 'early' => 'خروج مبكر', 'in_progress' => 'قيد العمل',
        'not_started' => 'لم يبدأ الدوام', 'missing_punches' => 'بلا بصمات', 'incomplete_punches' => 'بصمات ناقصة',
        'off' => 'يوم راحة', 'off_day_punch' => 'بصمة في يوم بلا دوام', 'official_holiday' => 'عطلة رسمية', 'emergency_holiday' => 'عطلة طارئة',
        'approved_leave' => 'إجازة معتمدة', 'holiday_or_leave_work_review' => 'عمل في إجازة أو عطلة', 'schedule_missing' => 'لا يوجد دوام مقرر',
        'exception' => 'استثناء دوام', 'invalid_schedule' => 'جدول دوام غير صالح', 'grace_unapproved' => 'فترة السماح غير معتمدة',
        'no_duty' => 'معفى من الدوام', 'exempt_punch' => 'معفى من البصمة', 'not_enrolled' => 'غير مسجل على جهاز البصمة',
    ];

    private const REVIEW_NOTES = [
        'holiday_or_leave_work_review' => 'بصمات في يوم إجازة أو عطلة.', 'off_day_punch' => 'بصمات في يوم بلا دوام مقرر.',
        'exception' => 'استثناء دوام بلا أوقات محددة.', 'invalid_schedule' => 'جدول الدوام غير صالح.', 'grace_unapproved' => 'فترة السماح غير معتمدة في الإعدادات.',
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
        $shift = $explanation['shift'] ?? null;
        $flexible = ($shift['kind'] ?? 'fixed') === 'flexible';
        $windowEnd = $at($explanation['window'][1] ?? null) ?? $expectedOut?->addHours(AttendanceDailyService::WINDOW_HOURS);
        $open = $expectedOut && $windowEnd && $now->lessThan($windowEnd);
        $ordered = $in && $out && $out->greaterThan($in);

        $misordered = $in && $out && ! $ordered;
        // Exit before entry means both buttons are in doubt: the entry time is not a verified arrival.
        $actualLate = $in && $expectedIn && ! $misordered ? ($flexible ? 0 : max(0, $this->minutes($expectedIn, $in))) : null;
        $excusedLate = (int) ($explanation['excused_late'] ?? 0);
        $chargeable = $actualLate !== null && $grace !== null ? max(0, $actualLate - $excusedLate - $grace) : null;
        $punctuality = match (true) {
            $actualLate === null || $grace === null => 'unknown',
            $flexible => 'flexible',
            $excusedLate > 0 && $chargeable === 0 && $actualLate > $grace => 'excused',
            default => $chargeable > 0 ? 'late' : $this->classify($actualLate, $grace),
        };
        $provisional = null;
        if ($punctuality === 'unknown' && $potentialEntry && $expectedIn && $grace !== null && ! $flexible) {
            $provisional = 'مبدئيًا: '.self::PUNCTUALITY[$this->classify(max(0, $this->minutes($expectedIn, $potentialEntry)), $grace)];
        }
        $early = property_exists($row, 'early_minutes') && $row->early_minutes !== null ? (int) $row->early_minutes
            : ($out && $expectedOut && (! $in || $ordered) ? max(0, $this->minutes($out, $expectedOut)) : null);

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
        $info = $this->info($explanation);

        [$state, $hint] = $this->dayState($status, $now, $expectedIn, $expectedOut, $in, $out, $open, (bool) ($potentialEntry || $potentialExit || $misordered), $explanation);
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
        $attendance = $ordered ? $this->minutes($in, $out) : null;
        $unpaidBreak = $shift && ! ($shift['break_paid'] ?? true) ? (int) ($shift['break_minutes'] ?? 0) : 0;

        return [
            'work_date' => (string) $row->work_date,
            'expected_in' => $expectedIn?->format('H:i'),
            'expected_out' => $expectedOut ? $expectedOut->format('H:i').($expectedOut->toDateString() !== (string) $row->work_date ? ' (+1)' : '') : null,
            'actual_in' => $in?->format('H:i'),
            'actual_out' => $out ? $out->format('H:i').($out->toDateString() !== (string) $row->work_date ? ' (+1)' : '') : null,
            'attendance_minutes' => $attendance,
            'net_minutes' => $attendance !== null && $unpaidBreak > 0 ? max(0, $attendance - $unpaidBreak) : null,
            'elapsed_minutes' => $in && ! $out && in_array($state, ['working', 'awaiting_exit'], true) ? max(0, $this->minutes($in, $now)) : null,
            'actual_late_minutes' => $actualLate,
            'grace_minutes' => $grace,
            'grace_used_minutes' => $actualLate !== null && $grace !== null && $actualLate - $excusedLate > 0 ? min($actualLate - $excusedLate, $grace) : null,
            'excused_late_minutes' => $excusedLate ?: null,
            'excused_early_minutes' => ((int) ($explanation['excused_early'] ?? 0)) ?: null,
            'chargeable_late_minutes' => $chargeable,
            'early_minutes' => $early,
            'punctuality' => $punctuality,
            'punctuality_label' => self::PUNCTUALITY[$punctuality],
            'punctuality_provisional' => $provisional,
            'day_state' => $state,
            'day_label' => $label,
            'day_hint' => $hint,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status] ?? $status,
            'shift_name' => $shift['name'] ?? null,
            'shift_kind' => $shift['kind'] ?? null,
            'shift_source' => isset($explanation['tier']) ? (AttendanceCalendar::TIER_LABELS[$explanation['tier']] ?? null) : null,
            'swap' => ($explanation['override']['swap_id'] ?? null) !== null,
            'exemption' => in_array($explanation['override']['effect'] ?? null, ['no_duty', 'no_punch'], true) ? ($explanation['override']['reason_label'] ?? $explanation['override']['label'] ?? null) : null,
            'permissions' => array_map(fn (array $permission): string => $permission['label'].' '.$at($permission['from'])->format('H:i').'–'.$at($permission['to'])->format('H:i'),
                $explanation['permissions'] ?? []),
            'notes' => $notes,
            'info' => $info,
            'needs_review' => $state === 'review' || $notes !== [],
        ];
    }

    /**
     * Explanatory lines that do not need a review.
     *
     * @param  array<string, mixed>  $explanation
     * @return list<string>
     */
    private function info(array $explanation): array
    {
        $lines = [];
        $override = $explanation['override'] ?? null;
        if (($override['swap_id'] ?? null) !== null) {
            $lines[] = 'تبديل مناوبة معتمد ('.$override['label'].').';
        } elseif (($override['effect'] ?? null) === 'no_punch') {
            $lines[] = 'معفى من البصمة: '.($override['reason_label'] ?? $override['label']).'.';
        } elseif (($override['effect'] ?? null) === 'no_duty') {
            $lines[] = 'إعفاء من الدوام: '.($override['reason_label'] ?? $override['label']).'.';
        } elseif ($override) {
            $lines[] = 'استثناء معتمد: '.$override['label'].'.';
        }
        foreach ($explanation['permissions'] ?? [] as $permission) {
            $from = CarbonImmutable::parse($permission['from'])->setTimezone(config('app.timezone'));
            $to = CarbonImmutable::parse($permission['to'])->setTimezone(config('app.timezone'));
            $lines[] = $permission['label'].' معتمد '.$from->format('H:i').'–'.$to->format('H:i').' ('.$permission['minutes'].' د).';
        }
        if (($explanation['dst'] ?? null) !== null) {
            $lines[] = 'تغيّر التوقيت خلال هذه الوردية؛ الدقائق محسوبة بالوقت الفعلي.';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $explanation
     * @return array{0: string, 1: ?string}
     */
    private function dayState(string $status, CarbonImmutable $now, ?CarbonImmutable $expectedIn, ?CarbonImmutable $expectedOut, ?CarbonImmutable $in, ?CarbonImmutable $out, bool $open, bool $suspect, array $explanation): array
    {
        $running = fn (): array => $expectedOut && $now->lessThan($expectedOut) ? ['working', null] : ['awaiting_exit', 'انتهى الدوام المقرر ولم تُسجل بصمة خروج بعد.'];
        $reason = $explanation['override']['reason_label'] ?? $explanation['override']['label'] ?? null;

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
            'schedule_missing' => ['no_duty', 'لا يوجد جدول دوام معتمد يلزم الموظف بالحضور.'],
            'no_duty' => ['no_duty', $reason],
            'exempt_punch' => ['exempt', $reason],
            'not_enrolled' => ['not_enrolled', 'لا توجد بصمات والموظف غير مسجل على جهاز البصمة؛ راجع تسجيله.'],
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
