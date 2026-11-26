<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceAudit;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceDailyService;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendanceRoster;
use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «الورديات والإسناد» inside /admin/attendance: the roster grid, previews, bulk assignments, exemptions and emergency
 * changes. Assignments and exemptions are sent for the existing approval (executive director or system administrator);
 * an emergency change takes effect at once only when its author holds that approval authority.
 */
final class AttendanceRosterController extends Controller
{
    private const PER_PAGE = 60;

    public function __construct(private AttendanceAccess $access, private AttendanceRoster $roster) {}

    public function grid(Request $request): JsonResponse
    {
        abort_unless($this->access->canViewLedger($request->user()), 403);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'], 'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 30, 422, 'الحد الأقصى لعرض الجدول 31 يومًا.');
        $ids = $this->employeeIds($data['department_id'] ?? null, $data['q'] ?? null);
        $lastPage = max(1, (int) ceil(count($ids) / self::PER_PAGE));
        $page = min((int) ($data['page'] ?? 1), $lastPage);

        return response()->json($this->roster->grid(array_slice($ids, ($page - 1) * self::PER_PAGE, self::PER_PAGE), $data['from'], $data['to'])
            + ['current_page' => $page, 'last_page' => $lastPage, 'total' => count($ids)])->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request): JsonResponse
    {
        abort_unless($this->access->isHrOperator($request->user()), 403);
        $data = $this->validateScope($request, ['kind' => ['required', Rule::in(['assignment', 'exemption'])],
            'schedule_id' => ['nullable', 'required_if:kind,assignment', 'integer', 'exists:attendance_schedules,id']]);
        $ids = $this->scopeEmployees($data);
        if ($ids === []) {
            throw ValidationException::withMessages(['employee_ids' => 'لا يوجد موظفون نشطون في النطاق المحدد.']);
        }

        return response()->json($this->roster->preview($ids, $data['starts_on'], $data['ends_on'] ?? CarbonImmutable::parse($data['starts_on'])->addDays(30)->toDateString(),
            ['kind' => $data['kind'], 'schedule_id' => $data['schedule_id'] ?? null]));
    }

    /**
     * One pending assignment per selected employee, or one for the whole department.
     */
    public function bulkAssign(Request $request): RedirectResponse
    {
        abort_unless($this->access->isHrOperator($request->user()), 403);
        $data = $this->validateScope($request, ['schedule_id' => ['required', 'integer', Rule::exists('attendance_schedules', 'id')->where('is_active', true)]]);
        $targets = ! empty($data['employee_ids']) ? array_map(fn (int $id): array => ['employee_id' => $id, 'department_id' => null], $this->scopeEmployees($data))
            : [['employee_id' => null, 'department_id' => $data['department_id'] ?? null]];
        $count = DB::transaction(function () use ($request, $data, $targets): int {
            foreach ($targets as $target) {
                $id = DB::table('attendance_assignments')->insertGetId($target + ['schedule_id' => $data['schedule_id'], 'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'] ?? null, 'status' => 'pending', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                AttendanceAudit::record($request->user()->id, 'assignment', $id, 'submitted', ['bulk' => count($targets) > 1] + $target);
            }

            return count($targets);
        });

        return back()->with('success', "أُرسلت {$count} تكليفات دوام لاعتماد المدير التنفيذي.");
    }

    /**
     * «إعفاء من الدوام» (no duty) or «معفى من البصمة» (works without punching), with a reason from system constants.
     */
    public function exemption(Request $request): RedirectResponse
    {
        abort_unless($this->access->isHrOperator($request->user()), 403);
        abort_unless(AttendanceLedger::hasTable('attendance_shift_swaps'), 409, 'يلزم تنفيذ ترحيل الحضور المعتمد أولًا.');
        $data = $this->validateScope($request, [
            'reason_value_id' => ['required', 'integer'], 'effect' => ['nullable', Rule::in(['no_duty', 'no_punch'])],
            'note' => ['required', 'string', 'min:5', 'max:2000'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        abort_if(CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) > 365, 422, 'الحد الأقصى للإعفاء سنة واحدة.');
        $reason = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'attendance_exemption_reasons')
            ->where('v.id', $data['reason_value_id'])->where('v.is_active', true)->first(['v.id', 'v.name_ar', 'v.metadata']);
        if (! $reason) {
            throw ValidationException::withMessages(['reason_value_id' => 'سبب الإعفاء غير متاح في ثوابت النظام.']);
        }
        $effect = $data['effect'] ?? ((json_decode((string) $reason->metadata, true) ?: [])['effect'] ?? 'no_duty');
        $targets = ! empty($data['employee_ids']) ? array_map(fn (int $id): array => ['employee_id' => $id, 'department_id' => null], $this->scopeEmployees($data))
            : [['employee_id' => null, 'department_id' => $data['department_id'] ?? null]];
        DB::transaction(function () use ($request, $data, $targets, $reason, $effect): void {
            foreach ($targets as $target) {
                $id = DB::table('attendance_overrides')->insertGetId($target + [
                    'label' => $reason->name_ar, 'kind' => 'exemption', 'effect' => $effect, 'reason_value_id' => $reason->id,
                    'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'status' => 'pending', 'reason' => $data['note'],
                    'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                AttendanceAudit::record($request->user()->id, 'override', $id, 'submitted', ['kind' => 'exemption', 'effect' => $effect] + $target);
            }
        });

        return back()->with('success', 'أُرسل الإعفاء للاعتماد ('.count($targets).').');
    }

    /**
     * A manager-initiated change of one employee's shift on given days, with a stated reason and before/after snapshots.
     */
    public function emergency(Request $request, AttendanceCalendar $calendar, AttendanceDailyService $daily, NotificationCenter $notifications): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->isHrOperator($user) || $this->access->canApproveRoster($user), 403);
        abort_unless(AttendanceLedger::hasTable('attendance_shift_swaps'), 409, 'يلزم تنفيذ ترحيل الحضور المعتمد أولًا.');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'effect' => ['required', Rule::in(['schedule', 'off'])],
            'start_time' => ['nullable', 'required_if:effect,schedule', 'date_format:H:i'], 'end_time' => ['nullable', 'required_if:effect,schedule', 'date_format:H:i'],
            'ends_next_day' => ['sometimes', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        abort_if(CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) > 13, 422, 'التغيير الطارئ لأسبوعين كحد أقصى؛ استخدم التكليف للفترات الأطول.');
        if ($data['effect'] === 'schedule' && $data['start_time'] === $data['end_time']) {
            throw ValidationException::withMessages(['end_time' => 'يجب أن يختلف وقت البداية عن النهاية.']);
        }
        $approved = $this->access->canApproveRoster($user);
        $before = collect($calendar->resolve([(int) $data['employee_id']], $data['starts_on'], $data['ends_on'])[(int) $data['employee_id']] ?? [])
            ->map(fn (?array $slot): string => isset($slot['in'], $slot['out']) ? $slot['in']->format('H:i').'–'.$slot['out']->format('H:i') : ($slot['status'] ?? 'none'))->all();
        DB::transaction(function () use ($user, $data, $approved, $before, $daily, $notifications): void {
            $id = DB::table('attendance_overrides')->insertGetId([
                'label' => 'تغيير طارئ: '.mb_substr($data['reason'], 0, 100), 'kind' => 'emergency_change', 'effect' => $data['effect'], 'employee_id' => $data['employee_id'],
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'start_time' => $data['effect'] === 'schedule' ? $data['start_time'] : null,
                'end_time' => $data['effect'] === 'schedule' ? $data['end_time'] : null, 'ends_next_day' => (bool) ($data['ends_next_day'] ?? false),
                'status' => $approved ? 'approved' : 'pending', 'reason' => $data['reason'], 'created_by' => $user->id,
                'approved_by' => $approved ? $user->id : null, 'approved_at' => $approved ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            AttendanceAudit::record($user->id, 'override', $id, $approved ? 'emergency_applied' : 'submitted', ['before' => $before,
                'after' => $data['effect'] === 'schedule' ? $data['start_time'].'–'.$data['end_time'] : 'off', 'reason' => $data['reason']]);
            if ($approved) {
                $daily->refreshDays((int) $data['employee_id'], $data['starts_on'], $data['ends_on']);
                $this->notifyEmployee($notifications, (int) $data['employee_id'], 'تغيير طارئ على جدول دوامك',
                    'من '.$data['starts_on'].' إلى '.$data['ends_on'].': '.$data['reason'], 'emergency:'.$id);
            }
        });

        return back()->with('success', $approved ? 'طُبق التغيير الطارئ وأُبلغ الموظف.' : 'أُرسل التغيير الطارئ للاعتماد.');
    }

    /**
     * Withdraws an approved exception or exemption with a reason (kept for history); swaps are reversed from the swap itself.
     */
    public function revoke(Request $request, int $override, AttendanceDailyService $daily, NotificationCenter $notifications): RedirectResponse
    {
        abort_unless($this->access->canApproveRoster($request->user()), 403);
        abort_unless(AttendanceLedger::hasTable('attendance_shift_swaps'), 409, 'يلزم تنفيذ ترحيل الحضور المعتمد أولًا.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        DB::transaction(function () use ($request, $override, $data, $daily, $notifications): void {
            $row = DB::table('attendance_overrides')->where('id', $override)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'approved' || $row->revoked_at !== null || $row->swap_id !== null) {
                throw ValidationException::withMessages(['reason' => 'يُسحب الاستثناء المعتمد غير المرتبط بتبديل مناوبة فقط.']);
            }
            DB::table('attendance_overrides')->where('id', $row->id)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $request->user()->id,
                'revocation_reason' => $data['reason'], 'updated_at' => now()]);
            AttendanceAudit::record($request->user()->id, 'override', (int) $row->id, 'revoked', ['reason' => $data['reason']]);
            $daily->refreshExisting($row->employee_id ? (int) $row->employee_id : null, (string) $row->starts_on, (string) $row->ends_on);
            if ($row->employee_id) {
                $this->notifyEmployee($notifications, (int) $row->employee_id, 'سُحب استثناء من جدول دوامك', $row->label.': '.$data['reason'], 'revoked:'.$row->id);
            }
        });

        return back()->with('success', 'سُحب الاستثناء وأُعيد احتساب الأيام المتأثرة.');
    }

    private function notifyEmployee(NotificationCenter $notifications, int $employeeId, string $title, string $body, string $key): void
    {
        $userId = DB::table('employees')->where('id', $employeeId)->value('user_id');
        if ($userId) {
            $notifications->notify((int) $userId, 'attendance_schedule_changed', ['title' => $title, 'body' => $body, 'url' => '/my/profile?tab=attendance', 'dedupe' => 'attendance-schedule:'.$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validateScope(Request $request, array $extra): array
    {
        $data = $request->validate($extra + [
            'employee_ids' => ['nullable', 'array', 'max:200'], 'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => $extra['ends_on'] ?? ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        if (! empty($data['employee_ids']) && ! empty($data['department_id'])) {
            throw ValidationException::withMessages(['employee_ids' => 'اختر موظفين أو قسمًا، وليس كليهما.']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function scopeEmployees(array $data): array
    {
        $query = DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active');
        if (! empty($data['employee_ids'])) {
            $query->whereIn('id', $data['employee_ids']);
        } elseif (! empty($data['department_id'])) {
            $query->where('department_id', $data['department_id']);
        }

        return $query->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    private function employeeIds(?int $departmentId, ?string $search): array
    {
        $ids = DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active')
            ->when($departmentId, fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('first_name_ar')->orderBy('family_name_ar')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($search !== null && trim($search) !== '') {
            $named = app(AttendanceLedger::class)->employeeIdsByName($search);
            $ids = array_values(array_intersect($ids, $named));
        }

        return $ids;
    }
}
