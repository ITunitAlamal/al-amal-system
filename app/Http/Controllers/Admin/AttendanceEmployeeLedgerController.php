<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendancePermissionService;
use App\Services\Attendance\HrActionService;
use App\Services\AuditService;
use App\Services\Organization\EffectiveAssignments;
use App\Support\Attendance\AttendanceLedgerXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * «سجل دوام الموظفين»: the HR ledger of all employees (system administrator, HR head, executive director).
 * Every value comes from AttendanceLedger, the same source as the employee file and the Excel export.
 */
final class AttendanceEmployeeLedgerController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, AttendanceLedger $ledger): JsonResponse
    {
        $this->authorizeViewer($request);
        $filters = $this->filters($request, 31);
        $rows = $ledger->rows($this->scope($filters), $this->canSeeDirections($request->user()), app(AttendanceAccess::class)->canViewHrActions($request->user()));
        $matching = $ledger->filter($rows, $filters['filter'] ?? null);
        $lastPage = max(1, (int) ceil($matching->count() / self::PER_PAGE));
        $page = min((int) ($filters['page'] ?? 1), $lastPage);

        return response()->json([
            'data' => $matching->forPage($page, self::PER_PAGE)->values(),
            'summary' => $ledger->summary($rows),
            'current_page' => $page, 'last_page' => $lastPage, 'total' => $matching->count(),
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Punches behind one day, with corrections and (for HR) the audit trail. */
    public function day(Request $request, int $employee, string $date, AttendanceLedger $ledger): JsonResponse
    {
        $this->authorizeViewer($request);
        abort_unless(CarbonImmutable::createFromFormat('!Y-m-d', $date) !== false && DB::table('employees')->where('id', $employee)->exists(), 404);
        $user = $request->user();
        $access = app(AttendanceAccess::class);
        $registerPermission = $access->canDecideHr($user) && app(AttendancePermissionService::class)->available();
        $createAction = $access->canManageHrActions($user) && app(HrActionService::class)->available();

        $day = $ledger->day($employee, $date, true, $access->canViewHrActions($user));
        // The pending manual corrections of the day this user decides (owner decision 8: the HR head, or the executive director).
        $pending = collect($day['punches'])->flatMap(fn (array $punch): array => array_column($punch['manual'], 'id'))->all();

        return response()->json($day + [
            'permission_types' => $registerPermission ? app(AttendancePermissionService::class)->types() : [],
            'hr_action_types' => $createAction ? app(HrActionService::class)->types() : [],
            'can' => [
                'request_correction' => $user->isSystemAdmin() || $user->isHrDepartmentHead(),
                'decide_correction_ids' => $pending === [] ? [] : ($access->correctionsToDecide((int) $user->id)?->whereIn('c.id', $pending)
                    ->pluck('c.id')->map(fn ($id): int => (int) $id)->all() ?? []),
                'revert_auto' => $user->isSystemAdmin() || $user->isHrDepartmentHead(),
                'register_permission' => $registerPermission,
                'create_hr_action' => $createAction,
            ]])->header('Cache-Control', 'no-store, private');
    }

    /** All rows matching the current filters (not only the visible page), as a real .xlsx workbook. */
    public function exportXlsx(Request $request, AttendanceLedger $ledger): Response
    {
        $this->authorizeViewer($request);
        $filters = $this->filters($request, 93);
        $withDirections = $this->canSeeDirections($request->user());
        // The confidential administrative-action column only for the register's own viewers, never in general exports.
        $withHrActions = app(AttendanceAccess::class)->canViewHrActions($request->user()) && $request->boolean('with_actions');
        $rows = $ledger->filter($ledger->rows($this->scope($filters), $withDirections, $withHrActions), $filters['filter'] ?? null);

        $response = self::workbookResponse($rows->all(), 'سجل دوام الموظفين — من '.$filters['from'].' إلى '.$filters['to'], 'AIMS_Attendance_'.$filters['from'].'_to_'.$filters['to'].'.xlsx', $withDirections, $withHrActions);
        if ($response->isSuccessful()) {
            app(AuditService::class)->event('attendance.ledger.exported', ['subject_type' => 'attendance_daily_results', 'sensitivity' => $withHrActions ? 'restricted' : 'personal',
                'ip' => $request->ip(), 'metadata' => ['from' => $filters['from'], 'to' => $filters['to'], 'employee_id' => $filters['employee_id'] ?? null,
                    'name' => $filters['q'] ?? null, 'filter' => $filters['filter'] ?? null, 'rows' => $rows->count(), 'with_hr_actions' => $withHrActions, 'format' => 'xlsx']]);
        }

        return $response;
    }

    /**
     * Shared by the HR ledger and the employee file: an explicit error instead of an empty or broken workbook.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function workbookResponse(array $rows, string $title, string $filename, bool $withDirections, bool $withHrActions = false): Response
    {
        if ($rows === []) {
            return response()->json(['message' => 'لا توجد سجلات مطابقة للفلاتر الحالية؛ لم يُنشأ ملف Excel.'], 422);
        }
        if (count($rows) > 20000) {
            return response()->json(['message' => 'عدد السجلات أكبر من 20,000؛ ضيّق الفترة أو اختر موظفًا.'], 422);
        }
        try {
            $path = AttendanceLedgerXlsx::create($rows, $title, $withDirections, $withHrActions);
        } catch (Throwable $exception) {
            Log::error('Attendance XLSX export failed', ['reason' => $exception->getMessage()]);

            return response()->json(['message' => 'تعذّر إنشاء ملف Excel ('.$exception->getMessage().'). لم يُنزّل أي ملف.'], 500);
        }

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{from: string, to: string, employee_id?: ?int, q?: ?string, filter?: ?string, page?: ?int}
     */
    private function filters(Request $request, int $maxDays): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'min:1', 'exists:employees,id'],
            'q' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'string', Rule::in(AttendanceLedger::FILTERS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'with_actions' => ['nullable', 'boolean'],
        ]);
        abort_if(CarbonImmutable::createFromFormat('!Y-m-d', $data['from'])->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $data['to'])) > $maxDays - 1, 422,
            'الحد الأقصى للفترة '.$maxDays.' يومًا.');

        return $data;
    }

    /**
     * @return array{from: string, to: string, employee_ids: ?list<int>, name: ?string}
     */
    private function scope(array $filters): array
    {
        return ['from' => $filters['from'], 'to' => $filters['to'],
            'employee_ids' => ! empty($filters['employee_id']) ? [(int) $filters['employee_id']] : null, 'name' => $filters['q'] ?? null];
    }

    private function authorizeViewer(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ($user->isSystemAdmin() || $user->isHrDepartmentHead() || self::isExecutive((int) $user->id)), 403);
    }

    private function canSeeDirections(User $user): bool
    {
        return $user->isSystemAdmin() || $user->isHrDepartmentHead() || self::isExecutive((int) $user->id);
    }

    public static function isExecutive(int $id): bool
    {
        return DB::table('department_user_roles as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.user_id', $id)->where('r.code', 'executive_director')
            ->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))->exists();
    }
}
