<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceDailyService;
use App\Services\Attendance\AttendanceDestinationPolicy;
use App\Services\Attendance\AttendancePolicy;
use App\Services\Attendance\AttendanceRawImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttendanceOperationsController extends Controller
{
    private function staff(Request $r): void
    {
        abort_unless($r->user()?->is_active && ($r->user()->isSystemAdmin() || $r->user()->isHrDepartmentHead()), 403);
    }

    private function admin(Request $r): void
    {
        abort_unless($r->user()?->is_active && $r->user()->isSystemAdmin(), 403);
    }

    private function audit(Request $r, string $entity, int $id, string $action, array $detail): void
    {
        DB::table('attendance_audit_events')->insert(['entity_type' => $entity, 'entity_id' => $id, 'action' => $action, 'actor_id' => $r->user()->id, 'details' => json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function activateDevice(Request $r, int $device)
    {
        $this->admin($r);
        $v = $r->validate(['enabled' => ['required', 'boolean'], 'sync_mode' => ['required', Rule::in(['disabled', 'pull'])]]);
        DB::transaction(function () use ($r, $device, $v) {
            $row = DB::table('attendance_devices')->where('id', $device)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($v['enabled']) {
                if (! app(AttendanceDestinationPolicy::class)->allows($row)) {
                    throw ValidationException::withMessages(['enabled' => 'IP الجهاز غير مدرج في قائمة الخادم المسموحة.']);
                }
                if (! in_array($row->protocol, ['tcp', 'udp', 'auto'], true)) {
                    throw ValidationException::withMessages(['enabled' => 'بروتوكول غير مدعوم.']);
                }
            }
            $updates = [
                'is_active' => (bool) $v['enabled'],
                'sync_mode' => $v['enabled'] ? $v['sync_mode'] : 'disabled',
                'updated_at' => now(),
            ];
            if (! $v['enabled']) {
                $updates['probe_requested_at'] = null;
            }
            DB::table('attendance_devices')->where('id', $device)->update($updates);
            $this->audit($r, 'device', $device, 'connection_updated', ['before' => ['active' => $row->is_active, 'mode' => $row->sync_mode], 'after' => $v]);
        });

        return back()->with('success', 'تم تعديل تفعيل الجهاز.');
    }

    public function syncDevice(Request $r, int $device): RedirectResponse
    {
        $this->admin($r);
        abort_unless(Schema::hasColumn('attendance_devices', 'import_approved_at'), 409, 'Safe import migration required.');
        $v = $r->validate(['mode' => ['sometimes', Rule::in(['preview', 'import'])]]);
        abort_unless(config('attendance.zk_sync_enabled'), 409, 'Synchronization disabled.');
        DB::transaction(function () use ($r, $device, $v): void {
            $row = DB::table('attendance_devices')->where('id', $device)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->is_active && $row->sync_mode === 'pull', 409);
            abort_unless(app(AttendanceDestinationPolicy::class)->allows($row), 422);
            $mode = $v['mode'] ?? ($row->import_approved_at ? 'import' : 'preview');
            abort_if($mode === 'import' && ! $row->import_approved_at, 409, 'Approve a successful preview first.');
            if (! $row->sync_requested_at) {
                DB::table('attendance_devices')->where('id', $device)->update(['sync_requested_at' => now(), 'sync_request_mode' => $mode, 'updated_at' => now()]);
                $this->audit($r, 'device', $device, 'sync_requested', ['mode' => $mode]);
            }
        });

        return back()->with('success', 'سُجّل الطلب؛ ينفذه الوكيل المحلي، وتظهر الأعداد في صفحة العمليات.');
    }

    public function approveImport(Request $r, int $device): RedirectResponse
    {
        $this->admin($r);
        $v = $r->validate(['run_id' => 'required|integer', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($r, $device, $v): void {
            $row = DB::table('attendance_devices')->where('id', $device)->lockForUpdate()->first();
            abort_unless($row, 404);
            $run = DB::table('attendance_sync_runs')->where('id', $v['run_id'])->where('device_id', $device)->first();
            abort_unless($run && $run->dry_run && $run->status === 'success' && $run->failed === 0
                && $run->configuration_digest === app(AttendanceRawImporter::class)->configurationDigest($row)
                && $run->ended_at && CarbonImmutable::parse($run->ended_at)->greaterThan(now()->subDay()), 409, 'A current successful preview is required.');
            abort_unless($row->is_active && $row->sync_mode === 'pull', 409);
            DB::table('attendance_devices')->where('id', $device)->update(['import_approved_at' => now(), 'import_approved_by' => $r->user()->id, 'updated_at' => now()]);
            $this->audit($r, 'device', $device, 'raw_import_approved', ['run_id' => (int) $run->id, 'received' => $run->received, 'unmatched' => $run->unmatched]);
        });

        return back()->with('success', 'اعتمد استيراد الحركات الخام فقط؛ لا يجيز هذا القرار احتساب الرواتب أو التأخير.');
    }

    public function resolveUnmatched(Request $r, int $punch): RedirectResponse
    {
        $this->staff($r);
        $v = $r->validate(['reason' => 'required|string|min:5|max:1000']);
        DB::transaction(function () use ($r, $punch, $v): void {
            $row = DB::table('attendance_punches')->where('id', $punch)->lockForUpdate()->first();
            abort_unless($row && $row->raw_payload && $row->match_status === 'unmatched', 409);
            $matches = DB::table('employees')->whereNull('deleted_at')->where('employee_number', $row->raw_user_id)->lockForUpdate()->get(['id']);
            abort_unless($matches->count() === 1, 409, 'Exactly one canonical employee number must match.');
            $employee = (int) $matches->first()->id;
            $mapping = DB::table('attendance_employee_device_ids')->where('device_id', $row->device_id)->where('device_user_id', $row->raw_user_id)->first();
            abort_if($mapping && (int) $mapping->employee_id !== $employee, 409, 'Resolve conflicting device mapping first.');
            DB::table('attendance_punches')->where('id', $punch)->update(['employee_id' => $employee, 'match_status' => 'reviewed', 'updated_at' => now()]);
            $this->audit($r, 'punch', $punch, 'canonical_match_reviewed', ['employee_id' => $employee, 'reason' => $v['reason']]);
        });

        return back()->with('success', 'تم ربط الحركة بالرقم الوظيفي المطابق مع حفظ أصلها وسجل المراجعة.');
    }

    public function setDefaultSchedule(Request $r, int $schedule)
    {
        $this->admin($r);
        DB::transaction(function () use ($r, $schedule) {
            $row = DB::table('attendance_schedules')->where('id', $schedule)->lockForUpdate()->first();
            abort_unless($row && $row->is_active, 404);
            DB::table('attendance_schedules')->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);
            DB::table('attendance_schedules')->where('id', $schedule)->update(['is_default' => true, 'updated_at' => now()]);
            $this->audit($r, 'schedule', $schedule, 'set_default', []);
            app(AttendanceDailyService::class)->refreshExisting();
        });

        return back()->with('success', 'تم تعيين قالب الدوام الافتراضي.');
    }

    public function mapping(Request $r)
    {
        $this->staff($r);
        $v = $r->validate(['device_id' => 'required|integer|exists:attendance_devices,id', 'employee_id' => 'required|integer|exists:employees,id', 'device_user_id' => 'required|string|max:100']);
        if (! DB::table('employees')->where('id', $v['employee_id'])->whereNull('deleted_at')->where('employment_status', 'active')->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'يجب اختيار موظف فعال.']);
        }
        if (DB::table('employees')->where('id', $v['employee_id'])->value('employee_number') !== $v['device_user_id']) {
            throw ValidationException::withMessages(['device_user_id' => 'يلزم تطابق معرّف الجهاز مع الرقم الوظيفي حرفيًا، مع حفظ الأصفار.']);
        }
        DB::transaction(function () use ($r, $v) {
            if (DB::table('attendance_employee_device_ids')->where('device_id', $v['device_id'])->where(function ($q) use ($v) {
                $q->where('employee_id', $v['employee_id'])->orWhere('device_user_id', $v['device_user_id']);
            })->exists()) {
                throw ValidationException::withMessages(['device_user_id' => 'يوجد ربط سابق لهذا الموظف أو المعرّف على الجهاز.']);
            }
            $id = DB::table('attendance_employee_device_ids')->insertGetId($v);
            $this->audit($r, 'device_mapping', $id, 'created', $v);
        });

        return back()->with('success', 'تم ربط معرّف الجهاز بالموظف.');
    }

    public function overview(Request $r): Response|JsonResponse
    {
        $this->staff($r);

        $data = ['sync_runs' => DB::table('attendance_sync_runs')->orderByDesc('id')->limit(30)->get(),
            'mappings' => DB::table('attendance_employee_device_ids')->orderBy('device_id')->get(),
            'grace_minutes' => app(AttendancePolicy::class)->graceMinutes(),
            'canApproveImport' => $r->user()->isSystemAdmin(),
            'unmatched' => Schema::hasColumn('attendance_punches', 'raw_payload') ? DB::table('attendance_punches')->where('match_status', 'unmatched')->orderBy('id')->limit(100)->get(['id', 'device_id', 'raw_user_id', 'device_local_time', 'device_timezone']) : [],
            'daily' => DB::table('attendance_daily_results')->orderByDesc('work_date')->limit(100)->get(),
            'correction_punches' => DB::table('attendance_punches as p')->join('employees as e', 'e.id', '=', 'p.employee_id')
                ->where('p.source', 'device')->whereNotNull('p.raw_payload')->whereNotNull('p.employee_id')
                ->orderByDesc('p.occurred_at')->limit(100)
                ->get(['p.id', 'p.direction', 'p.occurred_at', 'p.raw_user_id', 'e.employee_number', 'e.first_name_ar', 'e.family_name_ar']),
            'corrections' => DB::table('attendance_punch_corrections as c')->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')
                ->orderByDesc('c.id')->limit(100)->get(['c.id', 'c.punch_id', 'c.original_direction', 'c.corrected_direction', 'c.reason', 'c.status', 'c.requested_by', 'c.reviewed_by', 'c.review_note', 'c.created_at', 'p.raw_user_id', 'p.occurred_at']),
        ];

        if ($r->expectsJson() && ! $r->header('X-Inertia')) {
            return response()->json($data);
        }

        return Inertia::render('Admin/Attendance/Operations', $data);
    }

    public function report(Request $r): StreamedResponse
    {
        $this->staff($r);
        $v = $r->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'employee_id' => 'nullable|integer|exists:employees,id']);
        if (CarbonImmutable::parse($v['from'])->diffInDays(CarbonImmutable::parse($v['to'])) > 93) {
            throw ValidationException::withMessages(['to' => 'الحد الأقصى 93 يومًا في كل تصدير.']);
        }
        $query = DB::table('attendance_daily_results as d')->join('employees as e', 'e.id', '=', 'd.employee_id')
            ->whereBetween('d.work_date', [$v['from'], $v['to']])->orderBy('d.work_date')->orderBy('d.employee_id')
            ->select(['e.employee_number', 'd.work_date', 'd.status', 'd.expected_in', 'd.expected_out', 'd.actual_in', 'd.actual_out', 'd.late_minutes', 'd.early_minutes']);
        if (! empty($v['employee_id'])) {
            $query->where('d.employee_id', $v['employee_id']);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['employee_number', 'date', 'status', 'expected_in_UTC', 'expected_out_UTC', 'actual_in_UTC', 'actual_out_UTC', 'late_minutes', 'early_minutes']);
            foreach ($query->cursor() as $row) {
                $cells = array_values((array) $row);
                foreach ($cells as &$cell) {
                    $cell = (string) ($cell ?? '');
                    if (preg_match('/^[=+@\-]/', $cell) && ! is_numeric($cell)) {
                        $cell = "'".$cell;
                    }
                }unset($cell);
                fputcsv($out, $cells);
            }
            fclose($out);
        }, 'attendance_report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
