<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendancePermissionService;
use App\Services\Attendance\AttendanceRoster;
use App\Services\Attendance\AttendanceSettings;
use App\Services\Attendance\HrActionService;
use App\Services\Attendance\ShiftSwapService;
use App\Services\AuditService;
use App\Services\Hr\EmployeeAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Today's shift, the next 14 days, the employee's permission and swap requests, and administrative actions:
     * issued ones for the employee themself, the whole register only for its confidential viewers.
     */
    public function overview(Request $request, int $employee, AttendanceRoster $roster, AttendanceAccess $attendance,
        AttendancePermissionService $permissions, ShiftSwapService $swaps, HrActionService $actions): JsonResponse
    {
        $record = $this->authorizedEmployee($request, $employee);
        $user = $request->user();
        $self = $this->access->isSelf($user, $record);
        $tz = config('app.timezone');
        $local = fn (mixed $value, string $format = 'H:i'): ?string => $value ? CarbonImmutable::parse($value)->setTimezone($tz)->format($format) : null;
        $upcoming = $roster->upcoming((int) $record->id, 14);

        $permissionRows = $permissions->available() ? DB::table('attendance_permission_requests as r')->join('master_data_values as t', 't.id', '=', 'r.type_value_id')
            ->where('r.employee_id', $record->id)->orderByDesc('r.work_date')->orderByDesc('r.id')->limit(60)
            ->get(['r.*', 't.name_ar as type_name'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'type' => $row->type_name, 'work_date' => (string) $row->work_date,
                'from' => $local($row->starts_at), 'to' => $local($row->ends_at), 'minutes' => (int) $row->minutes, 'status' => $row->status,
                'status_label' => AttendancePermissionService::STATUS[$row->status] ?? $row->status, 'reason' => $row->reason, 'source' => $row->source,
                'decision_note' => $row->decision_note, 'revocation_reason' => $row->revocation_reason, 'has_attachment' => $row->attachment_path !== null,
                'url' => AttendancePermissionService::url((int) $row->id),
                'can_cancel' => $row->status === 'submitted' && (int) $row->requested_by === (int) $user->id])->all() : [];

        $swapRows = $swaps->available() ? DB::table('attendance_shift_swaps as s')->join('employees as a', 'a.id', '=', 's.requester_employee_id')
            ->join('employees as b', 'b.id', '=', 's.counterpart_employee_id')
            ->where(fn ($query) => $query->where('s.requester_employee_id', $record->id)->orWhere('s.counterpart_employee_id', $record->id))
            ->orderByDesc('s.id')->limit(30)
            ->get(['s.*', 'a.first_name_ar', 'a.father_name_ar', 'a.family_name_ar', 'b.first_name_ar as b_first', 'b.father_name_ar as b_father', 'b.family_name_ar as b_family'])
            ->map(function (object $row) use ($record, $user, $self): array {
                $mine = (int) $row->requester_employee_id === (int) $record->id;
                $other = $mine ? trim($row->b_first.' '.$row->b_father.' '.$row->b_family) : AttendanceLedger::fullName($row);

                return ['id' => (int) $row->id, 'role' => $mine ? 'requester' : 'counterpart', 'other' => $other, 'status' => $row->status,
                    'status_label' => ShiftSwapService::STATUS[$row->status] ?? $row->status, 'reason' => $row->reason, 'counterpart_note' => $row->counterpart_note,
                    'decision_note' => $row->decision_note, 'reversal_reason' => $row->reversal_reason, 'url' => ShiftSwapService::url((int) $row->id),
                    'days' => array_map(fn (array $day): array => ['date' => $day['date'],
                        'mine' => $mine ? $day['requester_before']['label'] : $day['counterpart_before']['label'],
                        'theirs' => $mine ? $day['counterpart_before']['label'] : $day['requester_before']['label']], json_decode((string) $row->days, true) ?: []),
                    'can_respond' => $self && ! $mine && $row->status === 'awaiting_counterpart',
                    'can_cancel' => in_array($row->status, ShiftSwapService::OPEN, true) && (int) $row->requested_by === (int) $user->id];
            })->all() : [];

        $hrActionRows = ! $actions->available() ? [] : ($self ? $actions->list((int) $record->id, true)
            : ($attendance->canViewHrActions($user) ? $actions->list((int) $record->id, false) : []));

        return response()->json([
            'employee' => ['id' => (int) $record->id, 'name' => AttendanceLedger::fullName($record), 'self' => $self],
            'today' => $upcoming[0] ?? null,
            'roster' => $upcoming,
            'permissions' => $permissionRows,
            'swaps' => $swapRows,
            'hr_actions' => $hrActionRows,
            'permission_types' => $self && $permissions->available() ? array_map(fn (array $type): array => $type + ['effect_label' => AttendancePermissionService::EFFECTS[$type['rules']['effect']] ?? null],
                $permissions->types()) : [],
            'swap_candidates' => $self && $swaps->available() ? $attendance->swapCandidates($record) : [],
            'swap_rules' => ['max_days' => app(AttendanceSettings::class)->get('swap_max_days'), 'min_rest_hours' => app(AttendanceSettings::class)->get('swap_min_rest_hours'),
                'max_hours_per_day' => app(AttendanceSettings::class)->get('swap_max_hours_per_day'), 'same_department' => app(AttendanceSettings::class)->get('swap_same_department')],
            'can' => [
                'request_permission' => $self && $permissions->available(),
                'request_swap' => $self && $swaps->available(),
                'respond_actions' => $self,
            ],
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

        $response = AttendanceEmployeeLedgerController::workbookResponse($rows->all(), 'الحضور والانصراف — '.AttendanceLedger::fullName($record).' — من '.$range['from'].' إلى '.$range['to'],
            'AIMS_Attendance_'.$record->employee_number.'_'.$range['from'].'_to_'.$range['to'].'.xlsx', $hr);
        if ($response->isSuccessful()) {
            app(AuditService::class)->event('attendance.employee_ledger.exported', ['subject_type' => 'employees', 'subject_id' => $record->id, 'sensitivity' => 'personal',
                'ip' => $request->ip(), 'metadata' => ['from' => $range['from'], 'to' => $range['to'], 'rows' => $rows->count(), 'format' => 'xlsx']]);
        }

        return $response;
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
