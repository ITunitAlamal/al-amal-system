<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompensatoryLeaveService
{
    public function __construct(private AttendanceCalendar $calendar) {}

    public function eligibleHoliday(int $employeeId, string $date): bool
    {
        if (DB::table('leave_holidays')->where('holiday_date', $date)->exists()) {
            return true;
        }
        $employee = DB::table('employees')->where('id', $employeeId)->whereNull('deleted_at')->first(['department_id']);
        if (! $employee) {
            return false;
        }
        if (DB::table('attendance_emergency_holidays')->where('holiday_date', $date)->where('status', 'approved')
            ->where(function ($q) use ($employeeId, $employee) {
                $q->where('employee_id', $employeeId)
                    ->orWhere(function ($q) use ($employee) {
                        $q->whereNull('employee_id')->where('department_id', $employee->department_id);
                    })
                    ->orWhere(function ($q) {
                        $q->whereNull('employee_id')->whereNull('department_id');
                    });
            })->exists()) {
            return true;
        }
        $slot = $this->calendar->expected($employeeId, $date);

        return ($slot['status'] ?? null) === 'off';
    }

    public function credit(int $workId, int $actorId): void
    {
        DB::transaction(function () use ($workId, $actorId) {
            $work = DB::table('attendance_compensatory_work')->where('id', $workId)->lockForUpdate()->first();
            if (! $work) {
                abort(404);
            }
            if ($work->status === 'credited') {
                return;
            } // idempotent
            if ($work->status !== 'approved') {
                throw ValidationException::withMessages(['approval' => 'يجب اعتماد تكليف العمل أولًا.']);
            }
            if (! $this->eligibleHoliday((int) $work->employee_id, (string) $work->work_date)) {
                throw ValidationException::withMessages(['work_date' => 'هذا اليوم ليس عطلة معتمدة للموظف.']);
            }
            // Confirm actual completed work, not an unpaired device punch.
            $start = CarbonImmutable::parse($work->work_date.' 00:00:00', config('app.timezone', 'Asia/Gaza'))->utc();
            $end = CarbonImmutable::parse($work->work_date.' 00:00:00', config('app.timezone', 'Asia/Gaza'))->addDay()->addHours(12)->utc();
            $punches = DB::table('attendance_punches as p')
                ->leftJoinSub(DB::table('attendance_punch_corrections')->where('status', 'approved')->select(['punch_id', 'corrected_direction']), 'correction', function ($join) {
                    $join->on('correction.punch_id', '=', 'p.id');
                })
                ->where('p.employee_id', $work->employee_id)
                ->where(function ($q) {
                    $q->whereNull('p.raw_payload')->orWhereIn('p.match_status', ['matched', 'reviewed']);
                })
                ->where('p.occurred_at', '>=', $start->format('Y-m-d H:i:sP'))
                ->where('p.occurred_at', '<', $end->format('Y-m-d H:i:sP'))
                ->orderBy('p.occurred_at')
                ->get([DB::raw('COALESCE(correction.corrected_direction, p.direction) as direction'), 'p.occurred_at']);
            $entry = $punches->first(fn ($p) => $p->direction === 'in' && CarbonImmutable::parse($p->occurred_at, 'UTC')->setTimezone(config('app.timezone', 'Asia/Gaza'))->toDateString() === $work->work_date);
            $exit = $punches->filter(fn ($p) => $p->direction === 'out' && $entry && $p->occurred_at > $entry->occurred_at)->first();
            if (! $entry || ! $exit) {
                throw ValidationException::withMessages(['punches' => 'يلزم حضور وانصراف مثبتان؛ أو إتمام التصحيح اليدوي الموثّق أولًا.']);
            }
            $type = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'leave_types')->where('v.code', 'compensatory_leave')->where('v.is_active', true)->where('t.is_active', true)->value('v.id');
            if (! $type) {
                throw ValidationException::withMessages(['leave_type' => 'أضف نوع إجازة تعويضية فعّالًا برمز compensatory_leave إلى ثوابت أنواع الإجازات.']);
            }
            $year = (int) substr((string) $work->work_date, 0, 4);
            DB::table('employee_leave_accounts')->insertOrIgnore(['employee_id' => $work->employee_id, 'leave_type_value_id' => $type, 'balance_year' => $year, 'created_at' => now(), 'updated_at' => now()]);
            $account = DB::table('employee_leave_accounts')->where('employee_id', $work->employee_id)->where('leave_type_value_id', $type)->where('balance_year', $year)->lockForUpdate()->value('id');
            $key = "compensatory:{$work->employee_id}:{$work->work_date}";
            $tx = DB::table('employee_leave_transactions')->where('external_key', $key)->first();
            if (! $tx) {
                $txId = DB::table('employee_leave_transactions')->insertGetId(['account_id' => $account, 'amount_days' => 1, 'kind' => 'compensatory_grant', 'external_key' => $key,
                    'reason' => 'يوم تعويضي كامل مقابل عمل معتمد في عطلة بتاريخ '.$work->work_date, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $txId = $tx->id;
                if ((int) $tx->account_id !== (int) $account || (float) $tx->amount_days !== 1.0) {
                    throw new \RuntimeException('Compensatory transaction conflict');
                }
            }
            DB::table('attendance_compensatory_work')->where('id', $workId)->update(['status' => 'credited', 'credited_at' => now(), 'leave_transaction_id' => $txId, 'updated_at' => now()]);
        }, 3);
    }
}
