<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AttendanceAutoCorrectionService
{
    public const RULE_CODE = 'two_same_direction_v2';

    public const RULE_VERSION = '2';

    /** The shift must have ended this long ago, so no further punch of the day is expected. */
    public const SETTLE_HOURS = 2;

    /**
     * Conservative rule: exactly two same-direction punches on a non-overnight work day, one near the scheduled start
     * and one near the scheduled end, at least two hours apart, with no other punch, no absence and no manual request,
     * evaluated only once the shift ended SETTLE_HOURS ago. A punch that ever had an automatic correction (even a
     * reverted one) is never proposed again.
     */
    public function suggestions(int $days = 7, int $limit = 100): array
    {
        $days = max(1, min(90, $days));
        $limit = max(1, min(300, $limit));
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();
        $start = $today->subDays($days)->utc()->format('Y-m-d H:i:sP');
        $end = $now->utc()->format('Y-m-d H:i:sP');
        $rows = DB::table('attendance_punches as p')
            ->leftJoin('attendance_auto_corrections as a', function ($join) {
                $join->on('a.punch_id', '=', 'p.id');
            })
            ->leftJoin('attendance_punch_corrections as m', function ($join) {
                $join->on('m.punch_id', '=', 'p.id')->whereIn('m.status', ['pending', 'approved']);
            })
            ->whereNotNull('p.employee_id')->where('p.source', 'device')
            ->whereNotNull('p.raw_payload')->whereIn('p.match_status', ['matched', 'reviewed'])
            ->where('p.occurred_at', '>=', $start)->where('p.occurred_at', '<', $end)
            ->whereNull('a.id')->whereNull('m.id')
            ->orderBy('p.employee_id')->orderBy('p.occurred_at')
            ->limit(20000)->get(['p.id', 'p.employee_id', 'p.direction', 'p.occurred_at']);
        $groups = [];
        foreach ($rows as $row) {
            $local = CarbonImmutable::parse($row->occurred_at)->setTimezone(config('app.timezone'));
            $key = $row->employee_id.'|'.$local->toDateString();
            $groups[$key][] = $row;
        }
        $result = [];
        $calendar = app(AttendanceCalendar::class);
        foreach ($groups as $key => $items) {
            // Do not infer from incomplete pairs or multiple punches.
            if (count($items) !== 2) {
                continue;
            }
            // Cheap test first: only a same-direction pair can be a wrong-button case.
            if ($items[0]->direction !== $items[1]->direction || ! in_array($items[0]->direction, ['in', 'out'], true)) {
                continue;
            }
            [$employeeId,$date] = explode('|', $key);
            $slot = $calendar->expected((int) $employeeId, $date);
            if (! $slot || ($slot['status'] ?? null) !== 'work') {
                continue;
            }
            $scheduledIn = $slot['in'];
            $scheduledOut = $slot['out'];
            if (! $scheduledIn || ! $scheduledOut || $scheduledOut->toDateString() !== $scheduledIn->toDateString()) {
                continue;
            }
            if ($scheduledOut->lessThanOrEqualTo($scheduledIn) || $scheduledOut->getTimestamp() - $scheduledIn->getTimestamp() > 12 * 3600) {
                continue;
            }
            if ($now->lessThan($scheduledOut->addHours(self::SETTLE_HOURS))) {
                continue;
            }
            if (app(AttendanceAbsenceResolver::class)->resolve((int) $employeeId, $date)) {
                continue;
            }
            $first = CarbonImmutable::parse($items[0]->occurred_at);
            $last = CarbonImmutable::parse($items[1]->occurred_at);
            if (abs($scheduledIn->diffInMinutes($first, false)) > 75) {
                continue;
            }
            if (abs($scheduledOut->diffInMinutes($last, false)) > 75) {
                continue;
            }
            if ($first->diffInMinutes($last) < 120) {
                continue;
            }
            if ($items[0]->direction !== $items[1]->direction || ! in_array($items[0]->direction, ['in', 'out'], true)) {
                continue;
            }
            $correctFirst = $items[0]->direction === 'out';
            $target = $correctFirst ? $items[0] : $items[1];
            // Block any other punch on this workday; no automatic edits if there is a manual request/decision.
            $dayStart = CarbonImmutable::parse($date.' 00:00:00', config('app.timezone'));
            $allCount = DB::table('attendance_punches')->where('employee_id', $employeeId)
                ->where('occurred_at', '>=', $dayStart->format('Y-m-d H:i:sP'))
                ->where('occurred_at', '<', $dayStart->addDay()->format('Y-m-d H:i:sP'))->count();
            if ($allCount !== 2) {
                continue;
            }
            $blocked = DB::table('attendance_punch_corrections as c')
                ->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')
                ->where('p.employee_id', $employeeId)->whereIn('c.status', ['pending', 'approved'])->whereIn('p.id', [$items[0]->id, $items[1]->id])->exists();
            if ($blocked) {
                continue;
            }
            $already = DB::table('attendance_auto_corrections as a')->whereIn('a.punch_id', [$items[0]->id, $items[1]->id])->exists();
            if ($already) {
                continue;
            }
            $corrected = $correctFirst ? 'in' : 'out';
            $other = $correctFirst ? $last : $first;
            $originalAr = $correctFirst ? 'خروج' : 'دخول';
            $newAr = $correctFirst ? 'دخول' : 'خروج';
            $reason = "تصحيح تلقائي: يُرجح أن الموظف اختار زر {$originalAr} بدل {$newAr}؛ وقت الحركة ".($correctFirst ? $first : $last)->setTimezone(config('app.timezone'))->format('H:i').' متوافق مع الوردية وتدعمه بصمة أخرى الساعة '.$other->setTimezone(config('app.timezone'))->format('H:i').'.';
            $result[] = ['punch_id' => (int) $target->id, 'employee_id' => (int) $employeeId, 'date' => $date, 'original_direction' => $target->direction, 'corrected_direction' => $corrected, 'reason_ar' => $reason, 'rule_code' => self::RULE_CODE, 'evidence' => ['first_punch_id' => (int) $items[0]->id, 'second_punch_id' => (int) $items[1]->id, 'first_at' => $first->format('c'), 'second_at' => $last->format('c'), 'scheduled_in' => $scheduledIn->format('c'), 'scheduled_out' => $scheduledOut->format('c'), 'evaluated_at' => $now->format('c')]];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function apply(array $proposal): bool
    {
        return DB::transaction(function () use ($proposal): bool {
            $punch = DB::table('attendance_punches')->where('id', $proposal['punch_id'])->lockForUpdate()->first();
            if (! $punch || ! $punch->employee_id || (int) $punch->employee_id !== (int) $proposal['employee_id'] || $punch->direction !== $proposal['original_direction']) {
                return false;
            }
            if (DB::table('attendance_auto_corrections')->where('punch_id', $punch->id)->exists()) {
                return false;
            }
            if (DB::table('attendance_punch_corrections')->where('punch_id', $punch->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                return false;
            }
            DB::table('attendance_auto_corrections')->insert([
                'punch_id' => $punch->id, 'employee_id' => $punch->employee_id, 'original_direction' => $punch->direction,
                'corrected_direction' => $proposal['corrected_direction'], 'status' => 'applied', 'rule_code' => $proposal['rule_code'],
                'rule_version' => self::RULE_VERSION, 'reason_ar' => $proposal['reason_ar'],
                'evidence' => json_encode($proposal['evidence'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'applied_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AttendanceDailyService::class)->recalculateAroundPunch((int) $punch->employee_id, CarbonImmutable::parse($punch->occurred_at));

            return true;
        });
    }

    public function revert(int $correctionId, int $userId, string $reason): void
    {
        DB::transaction(function () use ($correctionId, $userId, $reason): void {
            $row = DB::table('attendance_auto_corrections')->where('id', $correctionId)->lockForUpdate()->first();
            if (! $row || $row->status !== 'applied') {
                throw new RuntimeException('Correction already reverted or missing');
            }
            $punch = DB::table('attendance_punches')->where('id', $row->punch_id)->first();
            if (! $punch) {
                throw new RuntimeException('Correction already reverted or missing');
            }
            DB::table('attendance_auto_corrections')->where('id', $correctionId)->update([
                'status' => 'reverted', 'reverted_by' => $userId, 'reversion_reason' => $reason, 'reverted_at' => now(), 'updated_at' => now(),
            ]);
            // The reverted row stays: suggestions() skips any punch with a correction history, so it is never re-applied.
            DB::table('attendance_audit_events')->insert([
                'entity_type' => 'punch', 'entity_id' => $punch->id, 'action' => 'auto_direction_reverted', 'actor_id' => $userId,
                'details' => json_encode(['correction_id' => $correctionId, 'original_direction' => $row->original_direction,
                    'corrected_direction' => $row->corrected_direction, 'rule' => $row->rule_code.' v'.$row->rule_version, 'reason' => $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            app(AttendanceDailyService::class)->recalculateAroundPunch((int) $row->employee_id, CarbonImmutable::parse($punch->occurred_at));
        });
    }
}
