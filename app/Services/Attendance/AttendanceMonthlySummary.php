<?php

namespace App\Services\Attendance;

use App\Models\User;
use App\Services\Departments\DepartmentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «دوامي الشهري» — one line per employee for a month, from the same ledger rows as the HR register (AttendanceLedger),
 * so the numbers never differ from the daily detail.
 *
 * Who sees whom: the employee their own month; a department head their departments' staff; the HR head, the executive
 * director and the system administrator everyone. It informs; it never decides a sanction or a pay effect.
 */
final class AttendanceMonthlySummary
{
    public function __construct(private readonly AttendanceLedger $ledger, private readonly DepartmentWorkspace $workspace, private readonly AttendanceAccess $access) {}

    /** Employee ids this user may see, or null for everyone. @return list<int>|null */
    public function scope(User $user): ?array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($user->isSystemAdmin() || $user->isHrDepartmentHead() || $this->access->isExecutive($user)) {
            return null;
        }
        $ids = [];
        if ($own = $this->access->ownEmployee($user)) {
            $ids[] = (int) $own->id;
        }
        $departments = $this->workspace->departments($user);
        if ($departments !== []) {
            $ids = [...$ids, ...DB::table('employees')->whereIn('department_id', $departments)->whereNull('deleted_at')->pluck('id')->map(fn ($id): int => (int) $id)->all()];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>|null  $employeeIds  null = all active employees
     * @return list<array<string, mixed>>
     */
    public function lines(?array $employeeIds, string $month, ?int $departmentId = null): array
    {
        [$from, $to] = $this->range($month);
        $employees = DB::table('employees as e')->leftJoin('departments as d', 'd.id', '=', 'e.department_id')->whereNull('e.deleted_at')
            ->where(fn ($q) => $q->where('e.employment_status', 'active')->orWhereExists(fn ($r) => $r->from('attendance_daily_results as r')->whereColumn('r.employee_id', 'e.id')->whereBetween('r.work_date', [$from, $to])))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('e.id', $employeeIds ?: [0]))
            ->when($departmentId, fn ($q) => $q->where('e.department_id', $departmentId))
            ->orderBy('d.name')->orderBy('e.first_name_ar')->get(['e.id', 'e.employee_number', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar', 'd.name as department']);
        $rows = $this->ledger->rows(['from' => $from, 'to' => $to, 'employee_ids' => $employees->pluck('id')->map(fn ($id): int => (int) $id)->all()])->groupBy('employee_id');

        return $employees->map(fn ($e): array => ['employee_id' => (int) $e->id, 'number' => $e->employee_number, 'name' => AttendanceLedger::fullName($e), 'department' => $e->department]
            + $this->totals($rows->get($e->id, collect())))->values()->all();
    }

    /** The month's days of one employee (for the detail under a line). */
    public function days(int $employeeId, string $month): array
    {
        [$from, $to] = $this->range($month);

        return $this->ledger->rows(['from' => $from, 'to' => $to, 'employee_ids' => [$employeeId]])->sortBy('work_date')
            ->map(fn (array $r): array => collect($r)->only(['work_date', 'expected_in', 'expected_out', 'actual_in', 'actual_out', 'day_state', 'day_label', 'punctuality', 'punctuality_label',
                'chargeable_late_minutes', 'early_minutes', 'net_minutes', 'attendance_minutes', 'permissions', 'shift_source'])->all())->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    public function totals(Collection $rows): array
    {
        $count = fn (callable $test): int => $rows->filter($test)->count();
        $sum = fn (string $key): int => (int) $rows->sum(fn (array $r): int => (int) ($r[$key] ?? 0));
        $worked = $rows->filter(fn (array $r): bool => in_array($r['day_state'], ['complete', 'working', 'awaiting_exit', 'incomplete', 'review'], true));

        return [
            'scheduled' => $count(fn (array $r): bool => ! in_array($r['day_state'], ['leave', 'holiday', 'rest_day', 'no_duty', 'not_started'], true)),
            'complete' => $count(fn (array $r): bool => $r['day_state'] === 'complete'),
            'absent' => $count(fn (array $r): bool => $r['day_state'] === 'absent'),
            'incomplete' => $count(fn (array $r): bool => $r['day_state'] === 'incomplete'),
            'late_days' => $count(fn (array $r): bool => $r['punctuality'] === 'late'),
            'late_minutes' => (int) $rows->sum(fn (array $r): int => $r['punctuality'] === 'late' ? (int) ($r['chargeable_late_minutes'] ?? 0) : 0),
            'early_days' => $count(fn (array $r): bool => ($r['early_minutes'] ?? 0) > 0),
            'early_minutes' => $sum('early_minutes'),
            'excused_days' => $count(fn (array $r): bool => $r['punctuality'] === 'excused' || ($r['permissions'] ?? []) !== []),
            'leave_days' => $count(fn (array $r): bool => in_array($r['day_state'], ['leave', 'holiday'], true)),
            'rest_days' => $count(fn (array $r): bool => $r['day_state'] === 'rest_day'),
            'review' => $count(fn (array $r): bool => (bool) $r['needs_review']),
            'not_enrolled' => $count(fn (array $r): bool => $r['day_state'] === 'not_enrolled'),
            'net_hours' => round($worked->sum(fn (array $r): int => (int) ($r['net_minutes'] ?? $r['attendance_minutes'] ?? 0)) / 60, 1),
        ];
    }

    /** @return array{0: string, 1: string} */
    public function range(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', config('app.timezone'));
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        return [$start->toDateString(), min($start->endOfMonth()->toDateString(), $today)];
    }
}
