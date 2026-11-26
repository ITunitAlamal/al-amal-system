<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Hr\EmployeeAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «الحضور والانصراف» in the employee file: the same AttendanceLedger rows as the HR ledger, limited to one employee
 * and to viewers whose file access includes the attendance section (EmployeeAccess). Audit trails and original
 * directions are for HR management and the executive director only.
 */
final class EmployeeAttendanceController extends Controller
{
    public function __construct(private EmployeeAccess $access, private AttendanceLedger $ledger) {}

    public function index(Request $request, int $employee): JsonResponse
    {
        $record = $this->authorizedEmployee($request, $employee);
        $range = $this->range($request);
        $rows = $this->ledger->rows(['from' => $range['from'], 'to' => $range['to'], 'employee_ids' => [$record->id]], $this->isHr($request));
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $todayRow = $range['from'] <= $today && $range['to'] >= $today ? $rows->firstWhere('work_date', $today)
            : $this->ledger->rows(['from' => $today, 'to' => $today, 'employee_ids' => [$record->id]])->first();

        return response()->json([
            'data' => $this->ledger->filter($rows, $range['filter'] ?? null)->values(),
            'summary' => $this->ledger->summary($rows) + [
                'late_minutes' => (int) $rows->sum('chargeable_late_minutes'),
                'grace_minutes_used' => (int) $rows->sum('grace_used_minutes'),
                'early_minutes' => (int) $rows->sum('early_minutes'),
            ],
            'today' => $todayRow,
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function day(Request $request, int $employee, string $date): JsonResponse
    {
        $record = $this->authorizedEmployee($request, $employee);
        abort_unless(CarbonImmutable::createFromFormat('!Y-m-d', $date) !== false, 404);
        $hr = $this->isHr($request);
        $day = $this->ledger->day($record->id, $date, $this->access->canManage($request->user()));
        if (! $hr) {
            // Employees and department heads see times and notes, not correction internals.
            $day['punches'] = array_map(fn (array $punch): array => array_diff_key($punch, array_flip(['original_direction', 'original_label', 'device_code', 'match_status', 'auto', 'manual'])), $day['punches']);
        }

        return response()->json($day + ['can' => ['request_correction' => false, 'decide_correction' => false, 'revert_auto' => false]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request, int $employee): Response
    {
        $record = $this->authorizedEmployee($request, $employee);
        $range = $this->range($request);
        $hr = $this->isHr($request);
        $rows = $this->ledger->filter($this->ledger->rows(['from' => $range['from'], 'to' => $range['to'], 'employee_ids' => [$record->id]], $hr), $range['filter'] ?? null);

        return AttendanceEmployeeLedgerController::workbookResponse($rows->all(), 'الحضور والانصراف — '.AttendanceLedger::fullName($record).' — من '.$range['from'].' إلى '.$range['to'],
            'AIMS_Attendance_'.$record->employee_number.'_'.$range['from'].'_to_'.$range['to'].'.xlsx', $hr);
    }

    private function authorizedEmployee(Request $request, int $employee): Employee
    {
        $record = Employee::query()->findOrFail($employee);
        abort_unless($request->user() && $this->access->canView($request->user(), $record) && ($this->access->sections($request->user(), $record)['attendance'] ?? false), 403);

        return $record;
    }

    private function isHr(Request $request): bool
    {
        return $this->access->canManage($request->user()) || $this->access->isExecutive($request->user());
    }

    /**
     * @return array{from: string, to: string, filter?: ?string}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'filter' => ['nullable', 'string', 'in:'.implode(',', AttendanceLedger::FILTERS)],
        ]);
        $today = CarbonImmutable::now(config('app.timezone'));
        $from = $data['from'] ?? $today->startOfMonth()->toDateString();
        $to = $data['to'] ?? $today->toDateString();
        abort_if($from > $to, 422, 'تاريخ البداية بعد تاريخ النهاية.');
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92, 422, 'الحد الأقصى للفترة 93 يومًا.');

        return ['from' => $from, 'to' => $to, 'filter' => $data['filter'] ?? null];
    }
}
