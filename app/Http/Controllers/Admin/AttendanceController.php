<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceDailyService;
use App\Services\Attendance\AttendanceDestinationPolicy;
use App\Services\Attendance\AttendanceDeviceBackoff;
use App\Services\Attendance\AttendanceLedger;
use App\Services\Attendance\AttendancePermissionService;
use App\Services\Attendance\AttendancePolicy;
use App\Services\Attendance\AttendanceSettings;
use App\Services\Attendance\PunchTimeReview;
use App\Services\Notifications\NotificationCenter;
use App\Services\Organization\EffectiveAssignments;
use App\Services\Progress\ApprovalProgress;
use App\Support\Attendance\ScheduleSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    /** HR operations: the system administrator or the HR head. The page's capability flags use this same check. */
    private function isStaff(?User $user): bool
    {
        return (bool) ($user?->is_active && ($user->isSystemAdmin() || $user->isHrDepartmentHead()));
    }

    private function staff(Request $request): void
    {
        abort_unless($this->isStaff($request->user()), 403);
    }

    private function executive(int $id): bool
    {
        return DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.user_id', $id)->where('r.code', 'executive_director')->where('u.is_active', true)->whereRaw(EffectiveAssignments::sql('a'))->exists();
    }

    /** Approvals of assignments and exceptions: the executive director only (the technical administrator takes no business decision). */
    private function isApprover(?User $user): bool
    {
        return (bool) ($user?->is_active && $this->executive((int) $user->id));
    }

    private function approver(Request $request): void
    {
        abort_unless($this->isApprover($request->user()), 403);
    }

    private function audit(Request $r, string $type, int $id, string $action, array $details = []): void
    {
        DB::table('attendance_audit_events')->insert(['entity_type' => $type, 'entity_id' => $id, 'action' => $action, 'actor_id' => $r->user()->id, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function configuredProbeIntervalSeconds(): int
    {
        $interval = max(30, min(3600, (int) config('attendance.health_probe_interval_seconds', 30)));

        return (int) (ceil($interval / 30) * 30);
    }

    public function index(Request $r)
    {
        abort_unless($r->user()?->is_active && ($r->user()->isSystemAdmin() || $r->user()->isHrDepartmentHead() || $this->executive((int) $r->user()->id)), 403);
        $progress = app(ApprovalProgress::class);
        $hr = $this->isStaff($r->user());
        $approver = $this->isApprover($r->user());
        $access = app(AttendanceAccess::class);
        $completion = AttendanceLedger::hasTable('attendance_shift_swaps');
        try {
            $grace = app(AttendancePolicy::class)->graceMinutes();
        } catch (\RuntimeException) {
            $grace = null;
        }

        return Inertia::render('Admin/Attendance/Index', [
            'username' => $r->user()->name, 'navigation' => [],
            'initialTab' => (string) $r->query('tab', 'today'),
            'graceMinutes' => $grace,
            'can' => [
                'sync' => $hr && config('attendance.zk_sync_enabled', false) === true,
                'requestCorrection' => $hr,
                'decideCorrection' => AttendancePunchCorrectionController::mayDecide($r->user()),
                'revertAuto' => $hr,
                'manage' => $hr,
                // One flag per endpoint guard: the page shows a form or a button only when its endpoint accepts this
                // user. $hr is staff() here, in AttendanceOperationsController and in
                // AttendanceScheduleManageController::requestChange; the executive director decides the approvals.
                'createSchedule' => $hr,
                'requestScheduleChange' => $hr,
                'createAssignment' => $hr,
                'createOverride' => $hr,
                'manualPunch' => $hr,
                'manageDevices' => $hr,
                'operations' => $hr,
                'report' => $hr,
                'userId' => (int) $r->user()->id,
                'approveRoster' => $access->canApproveRoster($r->user()),
                'decideHr' => $access->canDecideHr($r->user()),
                'viewHrActions' => $completion && $access->canViewHrActions($r->user()),
                'manageHrActions' => $completion && $access->canManageHrActions($r->user()),
                'decideHrActions' => $completion && $access->canDecideHrActions($r->user()),
            ],
            'completion' => $completion,
            'constants' => [
                'kinds' => AttendanceCalendar::KIND_LABELS, 'effects' => AttendanceCalendar::EFFECT_LABELS,
                'permissionEffects' => AttendancePermissionService::EFFECTS, 'attachmentRules' => AttendancePermissionService::ATTACHMENT_RULES,
                'permissionTypes' => $completion ? app(AttendancePermissionService::class)->types(true) : [],
                'exemptionReasons' => $completion ? DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                    ->where('t.code', 'attendance_exemption_reasons')->orderBy('v.sort_order')->get(['v.id', 'v.code', 'v.name_ar', 'v.is_active', 'v.metadata'])
                    ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->name_ar, 'is_active' => (bool) $row->is_active,
                        'effect' => (json_decode((string) $row->metadata, true) ?: [])['effect'] ?? 'no_duty'])->all() : [],
                'swapSettings' => app(AttendanceSettings::class)->all(),
            ],
            'autoCorrection' => [
                'enabled' => (bool) config('attendance.auto_direction_enabled', false),
                'recent' => DB::table('attendance_auto_corrections as c')->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')
                    ->join('employees as e', 'e.id', '=', 'c.employee_id')->leftJoin('users as u', 'u.id', '=', 'c.reverted_by')
                    ->orderByDesc('c.id')->limit(100)
                    ->get(['c.id', 'c.punch_id', 'c.employee_id', 'p.occurred_at', 'c.original_direction', 'c.corrected_direction', 'c.reason_ar', 'c.status',
                        'c.rule_code', 'c.rule_version', 'c.applied_at', 'c.reverted_at', 'c.reversion_reason', 'u.name as reverted_by_name',
                        'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar']),
                'pending' => DB::table('attendance_punch_corrections as c')->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')
                    ->join('employees as e', 'e.id', '=', 'p.employee_id')->leftJoin('users as u', 'u.id', '=', 'c.requested_by')
                    ->where('c.status', 'pending')->orderBy('c.id')
                    ->get(['c.id', 'c.punch_id', 'p.employee_id', 'p.occurred_at', 'c.original_direction', 'c.corrected_direction', 'c.reason', 'c.requested_by',
                        'c.created_at', 'u.name as requested_by_name', 'e.first_name_ar', 'e.father_name_ar', 'e.family_name_ar'])
                    // Which of them this user decides (AttendanceAccess::correctionsToDecide, the endpoint's own rule).
                    ->pipe(function ($rows) use ($r, $access) {
                        $mine = $access->correctionsToDecide((int) $r->user()->id)?->whereIn('c.id', $rows->pluck('id'))->pluck('c.id')->map(fn ($id): int => (int) $id)->all() ?? [];

                        return $rows->map(fn (object $row): object => (object) [...(array) $row, 'can_decide' => in_array((int) $row->id, $mine, true)]);
                    }),
            ],
            // Daylight-saving punches this user confirms (HR-20); the executive director's share has no other screen.
            'timeReview' => app(PunchTimeReview::class)->queue($r->user(), true),
            'devices' => DB::table('attendance_devices')->orderBy('id')->get([
                'id', 'name', 'location', 'host', 'port', 'protocol', 'is_active', 'sync_mode', 'last_synced_at',
                'probe_enabled', 'probe_interval_seconds', 'assigned_agent',
                ...(Schema::hasColumn('attendance_devices', 'driver') ? ['driver', 'timezone', 'connection_timeout', 'sync_interval_seconds', 'import_approved_at', 'firmware', 'platform', 'clock_drift_seconds'] : []),
            ]),
            'safeImportReady' => Schema::hasColumn('attendance_devices', 'driver'),
            'agentId' => (string) config('attendance.agent_id', 'local'),
            'probeIntervalSeconds' => $this->configuredProbeIntervalSeconds(),
            'syncEnabled' => config('attendance.zk_sync_enabled', false) === true,
            'schedules' => DB::table('attendance_schedules')->get(),
            'days' => DB::table('attendance_schedule_days')->get(),
            'assignments' => DB::table('attendance_assignments')->orderByDesc('id')->limit(60)->get()
                ->map(fn (object $row): object => (object) [...(array) $row, 'steps' => $progress->steps($row->status, [
                    'requestLabel' => 'إعداد التكليف', 'requestedBy' => $row->created_by ? (int) $row->created_by : null, 'requestedAt' => $row->created_at,
                    'decisionLabel' => 'اعتماد التكليف', 'decidedBy' => $row->approved_by ? (int) $row->approved_by : null, 'decidedAt' => $row->approved_at,
                    'decisionOwner' => 'المدير التنفيذي',
                ])]),
            'overrides' => DB::table('attendance_overrides')->orderByDesc('id')->limit(60)->get(),
            'punches' => DB::table('attendance_punches as p')->leftJoin('employees as e', 'e.id', '=', 'p.employee_id')->orderByDesc('p.occurred_at')->limit(100)->get(['p.*', 'e.employee_number', 'e.first_name_ar', 'e.family_name_ar']),
            'employees' => DB::table('employees')->whereNull('deleted_at')->where('employment_status', 'active')->orderBy('first_name_ar')
                ->get(['id', 'employee_number', 'first_name_ar', 'father_name_ar', 'family_name_ar', 'department_id']),
            'departments' => DB::table('departments')->whereNull('deleted_at')->where('is_active', true)->get(['id', 'name']),
            'canApprove' => $approver,
            'canManageDeviceConnections' => $r->user()->isSystemAdmin(),
            'scheduleChangeRequests' => DB::table('attendance_schedule_change_requests')->orderByDesc('id')->limit(100)->get()
                ->map(fn (object $row): object => (object) [...(array) $row, 'steps' => $progress->steps($row->status, [
                    'requestLabel' => 'طلب تعديل الدوام', 'requestedBy' => $row->requested_by ? (int) $row->requested_by : null, 'requestedAt' => $row->created_at,
                    'decisionLabel' => 'قرار الاعتماد', 'decidedBy' => $row->decided_by ? (int) $row->decided_by : null, 'decidedAt' => $row->decided_at,
                    'decisionOwner' => 'المدير التنفيذي',
                    'execution' => ['label' => 'تطبيق التعديل على القالب', 'done' => $row->status === 'approved', 'at' => $row->decided_at, 'owner' => 'تلقائي عند الاعتماد'],
                ])]),
        ]);
    }

    public function deviceStatuses(Request $r)
    {
        abort_unless($r->user()?->is_active && ($r->user()->isSystemAdmin() || $r->user()->isHrDepartmentHead() || $this->executive((int) $r->user()->id)), 403);

        $probeTtlBase = max(30, (int) config('attendance.health_probe_freshness_seconds', 90));
        $agentTtl = max(15, (int) config('attendance.agent_freshness_seconds', 45));
        $syncTtl = max(60, (int) config('attendance.sync_freshness_seconds', 900));
        $backoff = AttendanceDeviceBackoff::ready();
        $devices = DB::table('attendance_devices')->orderBy('id')->get([
            'id', 'name', 'is_active', 'sync_mode', 'assigned_agent', 'probe_enabled', 'last_probe_at',
            'last_success_at', 'probe_latency_ms', 'probe_result', 'probe_error_code',
            'agent_last_seen_at', 'last_sync_success_at', 'last_synced_at', 'probe_started_at', 'probe_interval_seconds',
            ...(Schema::hasColumn('attendance_devices', 'driver') ? ['firmware', 'platform', 'clock_drift_seconds', 'last_failure_at', 'last_failure_code', 'sync_requested_at'] : []),
            ...($backoff ? ['sync_failure_count', 'sync_retry_at', 'probe_failure_count', 'probe_retry_at'] : []),
        ]);
        $latestRuns = $devices->isEmpty()
            ? collect()
            : DB::table('attendance_sync_runs as runs')
                ->joinSub(
                    DB::table('attendance_sync_runs')
                        ->whereIn('device_id', $devices->pluck('id'))
                        ->select('device_id', DB::raw('MAX(id) as latest_id'))
                        ->groupBy('device_id'),
                    'latest',
                    'latest.latest_id',
                    '=',
                    'runs.id',
                )
                ->get(['runs.device_id', 'runs.status', 'runs.error', 'runs.started_at', 'runs.ended_at', ...(Schema::hasColumn('attendance_sync_runs', 'dry_run') ? ['runs.received', 'runs.inserted', 'runs.duplicates', 'runs.unmatched', 'runs.failed', 'runs.dry_run'] : []),
                    ...($backoff ? ['runs.attempts'] : [])])
                ->keyBy('device_id');

        $statuses = $devices->map(function (object $device) use ($agentTtl, $latestRuns, $probeTtlBase, $syncTtl): array {
            $now = CarbonImmutable::now();
            $probeInterval = (int) (ceil(max(30, min(3600, (int) $device->probe_interval_seconds)) / 30) * 30);
            $probeTtl = max($probeTtlBase, $probeInterval * 3);
            $lastProbeAt = $device->last_probe_at ? CarbonImmutable::parse($device->last_probe_at) : null;
            $agentLastSeenAt = $device->agent_last_seen_at ? CarbonImmutable::parse($device->agent_last_seen_at) : null;
            $syncSuccessAt = $device->last_sync_success_at ?? $device->last_synced_at;
            $lastSyncSuccessAt = $syncSuccessAt ? CarbonImmutable::parse($syncSuccessAt) : null;
            $latestRun = $latestRuns->get($device->id);

            // A sync holds the device for a few seconds; a run still "running" after the device lock (180 s) expired was interrupted.
            $syncRunning = $latestRun?->status === 'running' && $latestRun->started_at
                && CarbonImmutable::parse($latestRun->started_at)->greaterThan($now->subSeconds(180));

            if (! $device->is_active || ! $device->probe_enabled) {
                $deviceState = 'disabled';
            } elseif ($syncRunning) {
                $deviceState = 'syncing';
            } elseif ($device->probe_started_at && CarbonImmutable::parse($device->probe_started_at)->greaterThan($now->subSeconds(max(15, min(20, (int) config('attendance.health_probe_timeout_seconds', 10) + 5))))) {
                $deviceState = 'checking';
            } elseif (! $lastProbeAt) {
                $deviceState = 'unknown';
            } elseif ($lastProbeAt->lessThan($now->subSeconds($probeTtl))
                && ! (($device->probe_retry_at ?? null) && CarbonImmutable::parse($device->probe_retry_at)->greaterThan($now->subSeconds($probeTtlBase)))) {
                // No fresh probe: the last reading is old, which is not proof that the device is disconnected. A device the
                // agent is deliberately waiting on after failed probes (AttendanceDeviceBackoff) keeps its last reading.
                $deviceState = 'stale';
            } elseif ($device->probe_error_code === 'device_busy') {
                $deviceState = 'syncing';
            } else {
                $deviceState = $device->probe_result === 'connected' ? 'connected' : 'disconnected';
            }

            // The agent is this machine's scheduler: it reports every probe cycle (30 s).
            $agentState = ! $agentLastSeenAt
                ? 'unknown'
                : ($agentLastSeenAt->lessThan($now->subSeconds($agentTtl)) ? 'stopped' : 'connected');

            if ($device->sync_mode !== 'pull') {
                $syncState = 'disabled';
            } elseif ($latestRun?->status === 'running') {
                $syncState = $syncRunning ? 'running' : 'stale';
            } elseif ($latestRun?->status === 'failed' && (! $lastSyncSuccessAt || ! $latestRun->ended_at || CarbonImmutable::parse($latestRun->ended_at)->greaterThan($lastSyncSuccessAt))) {
                $syncState = 'failed';
            } elseif ($device->sync_requested_at ?? null) {
                $syncState = 'queued';
            } elseif (! $lastSyncSuccessAt) {
                $syncState = 'never';
            } elseif ($lastSyncSuccessAt->lessThan($now->subSeconds($syncTtl))) {
                $syncState = 'stale';
            } else {
                $syncState = 'successful';
            }

            return [
                'id' => (int) $device->id,
                'firmware' => $device->firmware ?? null,
                'platform' => $device->platform ?? null,
                'clock_drift_seconds' => $device->clock_drift_seconds ?? null,
                'last_failure_at' => $device->last_failure_at ?? null,
                'last_failure_code' => $this->safeProbeErrorCode($device->last_failure_code ?? null),
                'latest_run' => $latestRun,
                'sync_error_label' => $latestRun?->status === 'failed' ? $this->syncErrorLabel((string) ($latestRun->error ?? '')) : null,
                'sync_requested_at' => $device->sync_requested_at ?? null,
                'device_state' => $deviceState,
                'agent_state' => $agentState,
                'sync_state' => $syncState,
                'last_probe_at' => $device->last_probe_at,
                'last_success_at' => $device->last_success_at,
                'probe_latency_ms' => $device->probe_latency_ms === null ? null : (int) $device->probe_latency_ms,
                'probe_error_code' => $this->safeProbeErrorCode($device->probe_error_code),
                'agent_last_seen_at' => $device->agent_last_seen_at,
                'last_sync_success_at' => $syncSuccessAt,
                'probe_interval_seconds' => $probeInterval,
                'probe_freshness_seconds' => $probeTtl,
                // The punches of an active device are not current: the ledger may show missing punches or absences that
                // are only not imported yet (the page warns about it).
                'data_stale' => (bool) $device->is_active && in_array($syncState, ['failed', 'stale'], true),
                'sync_failure_count' => (int) ($device->sync_failure_count ?? 0),
                'sync_retry_at' => $device->sync_retry_at ?? null,
            ];
        })->values();

        return response()->json(['devices' => $statuses])->header('Cache-Control', 'no-store, private');
    }

    public function requestDeviceProbe(Request $r, int $device)
    {
        abort_unless($r->user()?->is_active && $r->user()->isSystemAdmin(), 403);

        $row = DB::table('attendance_devices')->where('id', $device)->first();
        abort_unless($row, 404);
        abort_unless($row->is_active && $row->probe_enabled, 409, 'Device probing is not enabled.');
        abort_unless($row->assigned_agent === (string) config('attendance.agent_id', 'local'), 409, 'Device is assigned to another agent.');

        $rateLimitKeys = [
            'attendance-device-probe:device:'.$device,
            'attendance-device-probe:user:'.$r->user()->id.':'.$device,
        ];
        foreach ($rateLimitKeys as $key) {
            abort_if(RateLimiter::tooManyAttempts($key, 1), 429, 'A probe was recently requested.');
        }
        foreach ($rateLimitKeys as $key) {
            RateLimiter::hit($key, 60);
        }

        DB::transaction(function () use ($r, $row, $device): void {
            $updated = DB::table('attendance_devices')
                ->where('id', $device)
                ->where('is_active', true)
                ->where('probe_enabled', true)
                ->where('assigned_agent', $row->assigned_agent)
                ->update(['probe_requested_at' => now(), 'updated_at' => now()]);
            abort_unless($updated === 1, 409, 'Device configuration changed. Retry the request.');
            $this->audit($r, 'device', $device, 'probe_requested');
        });

        return back()->with('success', 'أُدرج طلب الفحص؛ سينفذه الوكيل المحلي في دورته التالية.');
    }

    private function syncErrorLabel(string $code): string
    {
        return match ($code) {
            'reader_failed', 'sync_failed' => 'تعذّر الاتصال بجهاز البصمة',
            'probe_timeout' => 'انتهت مهلة الاتصال بالجهاز',
            'pyzk_unavailable', 'probe_runtime_unavailable' => 'برنامج الاتصال بالجهاز غير متاح على الخادم',
            'host_not_approved' => 'عنوان الجهاز غير معتمد على الخادم',
            'import_not_authorized' => 'تغيّرت إعدادات الجهاز؛ يلزم اعتماد الاستيراد من جديد',
            'retry_buffer_unavailable' => 'تعذّر حفظ نسخة الاستعادة المؤقتة',
            default => 'فشلت المزامنة',
        };
    }

    private function safeProbeErrorCode(?string $errorCode): ?string
    {
        $allowedCodes = [
            'host_not_approved',
            'invalid_configuration',
            'probe_runtime_unavailable',
            'probe_timeout',
            'probe_dependency_unavailable',
            'connection_refused',
            'connection_failed',
            'probe_failed',
            'invalid_probe_response',
            'device_busy',
        ];

        if ($errorCode !== null && ! in_array($errorCode, $allowedCodes, true)) {
            Log::warning('Attendance probe returned an unrecognized status code.');

            return 'probe_failed';
        }

        return $errorCode;
    }

    public function device(Request $r)
    {
        $this->staff($r);
        $v = $r->validate([
            'name' => 'required|string|max:150', 'location' => 'nullable|string|max:150',
            'host' => 'required|ipv4',
            'port' => 'required|integer|between:1,65535', 'protocol' => ['required', Rule::in(['auto', 'tcp', 'udp'])],
            'is_active' => 'sometimes|boolean', 'probe_enabled' => 'sometimes|boolean',
            'probe_interval_seconds' => ['sometimes', 'integer', Rule::in(range(30, 3600, 30))],
            'assigned_agent' => 'sometimes|required|string|alpha_dash:ascii|max:100',
            ...$this->safeDeviceRules(),
        ]);
        // Devices remain passive until a verified vendor driver is configured. Never open arbitrary outbound sockets from a web request.
        $v['is_active'] = false;
        $v['probe_enabled'] = (bool) ($v['probe_enabled'] ?? false);
        $v['probe_interval_seconds'] = (int) ($v['probe_interval_seconds'] ?? $this->configuredProbeIntervalSeconds());
        $v['assigned_agent'] = $v['assigned_agent'] ?? (string) config('attendance.agent_id', 'local');
        $v = $this->protectDeviceCredentials($v);
        if (! app(AttendanceDestinationPolicy::class)->allows((object) $v)) {
            throw ValidationException::withMessages(['host' => 'يلزم اعتماد عنوان الجهاز ومنفذه ووكيله في قائمة السماح على الخادم.']);
        }
        DB::transaction(function () use ($r, $v) {
            $id = DB::table('attendance_devices')->insertGetId($v + ['created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'device', $id, 'created', array_diff_key($v, ['communication_password' => true]));
        });

        return back()->with('success', 'تم حفظ الجهاز بوضع غير متصل حتى تركيب موصل ZKTeco المتوافق.');
    }

    public function updateDevice(Request $r, int $device)
    {
        $this->staff($r);
        $v = $r->validate([
            'name' => 'required|string|max:150', 'location' => 'nullable|string|max:150',
            'host' => 'required|ipv4',
            'port' => 'required|integer|between:1,65535', 'protocol' => ['required', Rule::in(['auto', 'tcp', 'udp'])],
            'probe_enabled' => 'sometimes|boolean',
            'probe_interval_seconds' => ['sometimes', 'integer', Rule::in(range(30, 3600, 30))],
            'assigned_agent' => 'sometimes|required|string|alpha_dash:ascii|max:100',
            ...$this->safeDeviceRules(),
        ]);
        DB::transaction(function () use ($r, $device, $v) {
            $row = DB::table('attendance_devices')->where('id', $device)->lockForUpdate()->first();
            abort_unless($row, 404);
            $v['probe_enabled'] = (bool) ($v['probe_enabled'] ?? $row->probe_enabled);
            $v['probe_interval_seconds'] = (int) ($v['probe_interval_seconds'] ?? $row->probe_interval_seconds);
            $v['assigned_agent'] = $v['assigned_agent'] ?? $row->assigned_agent;
            $v = $this->protectDeviceCredentials($v);
            if (! app(AttendanceDestinationPolicy::class)->allows((object) (['id' => $device] + $v))) {
                throw ValidationException::withMessages(['host' => 'عنوان الجهاز أو منفذه أو وكيله غير معتمد.']);
            }
            $endpointChanged = $row->host !== $v['host']
                || (int) $row->port !== (int) $v['port']
                || $row->protocol !== $v['protocol']
                || $row->assigned_agent !== $v['assigned_agent']
                || (isset($v['timezone']) && ($row->timezone ?? null) !== $v['timezone'])
                || array_key_exists('communication_password', $v);
            $probeConfigurationChanged = $endpointChanged || (bool) $row->probe_enabled !== $v['probe_enabled'];
            if ($endpointChanged) {
                if (Schema::hasColumn('attendance_devices', 'import_approved_at')) {
                    $v += ['import_approved_at' => null, 'import_approved_by' => null, 'sync_requested_at' => null, 'sync_request_mode' => null];
                }
                $v['is_active'] = false;
                $v['sync_mode'] = 'disabled';
                $v += [
                    'agent_last_seen_at' => null,
                    'last_synced_at' => null,
                    'last_sync_success_at' => null,
                    'last_error' => null,
                ];
                if (Schema::hasColumn('attendance_devices', 'firmware')) {
                    $v += ['firmware' => null, 'platform' => null, 'clock_drift_seconds' => null, 'last_failure_at' => null, 'last_failure_code' => null];
                }
                if (AttendanceDeviceBackoff::ready()) {
                    $v += ['sync_failure_count' => 0, 'sync_failed_at' => null, 'sync_retry_at' => null];
                }
            }
            if ($probeConfigurationChanged) {
                $v += [
                    'last_probe_at' => null,
                    'last_success_at' => null,
                    'probe_latency_ms' => null,
                    'probe_result' => null,
                    'probe_error_code' => null,
                    'probe_requested_at' => null,
                    'probe_started_at' => null,
                    'probe_lock_token' => null,
                ];
                if (AttendanceDeviceBackoff::ready()) {
                    $v += ['probe_failure_count' => 0, 'probe_retry_at' => null];
                }
            }
            DB::table('attendance_devices')->where('id', $device)->update($v + ['updated_at' => now()]);
            $this->audit($r, 'device', $device, 'updated', [
                'before' => ['name' => $row->name, 'location' => $row->location, 'host' => $row->host, 'port' => $row->port, 'protocol' => $row->protocol],
                'after' => array_diff_key($v, ['communication_password' => true]),
            ]);
        });

        return back()->with('success', 'تم تحديث الجهاز.');
    }

    private function safeDeviceRules(): array
    {
        if (! Schema::hasColumn('attendance_devices', 'driver')) {
            return ['communication_password' => 'prohibited'];
        }

        return [
            'driver' => ['required', Rule::in(['pyzk'])], 'timezone' => 'required|timezone',
            'connection_timeout' => 'required|integer|between:1,15',
            'sync_interval_seconds' => 'required|integer|between:60,86400',
            'communication_password' => 'nullable|integer|between:0,4294967295',
        ];
    }

    private function protectDeviceCredentials(array $values): array
    {
        if (isset($values['communication_password']) && $values['communication_password'] !== '') {
            $values['communication_password'] = Crypt::encryptString((string) $values['communication_password']);
        } else {
            unset($values['communication_password']);
        }

        return $values;
    }

    public function schedule(Request $r)
    {
        $this->staff($r);
        $v = $r->validate(['name' => 'required|string|max:150'] + ScheduleSettings::rules());
        ScheduleSettings::checkDays($v);
        DB::transaction(function () use ($r, $v) {
            $id = DB::table('attendance_schedules')->insertGetId(['name' => $v['name'], 'created_at' => now(), 'updated_at' => now()] + ScheduleSettings::columns($v));
            foreach ($v['days'] as $d) {
                DB::table('attendance_schedule_days')->insert(['schedule_id' => $id, 'weekday' => $d['weekday'], 'start_time' => $d['start_time'], 'end_time' => $d['end_time'], 'ends_next_day' => (bool) ($d['ends_next_day'] ?? false)]);
            }$this->audit($r, 'schedule', $id, 'created');
        });

        return back()->with('success', 'تم إنشاء قالب الدوام.');
    }

    public function assignment(Request $r)
    {
        $this->staff($r);
        $v = $r->validate(['schedule_id' => 'required|integer|exists:attendance_schedules,id', 'employee_id' => 'nullable|integer|exists:employees,id', 'department_id' => 'nullable|integer|exists:departments,id', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on']);
        if (! empty($v['employee_id']) && ! empty($v['department_id'])) {
            throw ValidationException::withMessages(['employee_id' => 'اختر الموظف أو القسم، وليس كليهما.']);
        }
        DB::transaction(function () use ($r, $v) {
            $id = DB::table('attendance_assignments')->insertGetId($v + ['status' => 'pending', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'assignment', $id, 'submitted');
        });

        return back()->with('success', 'أُرسل التكليف للاعتماد.');
    }

    public function override(Request $r)
    {
        $this->staff($r);
        $v = $r->validate(['label' => 'required|string|max:150', 'kind' => ['required', Rule::in(['ramadan', 'emergency', 'special'])], 'employee_id' => 'nullable|integer|exists:employees,id', 'department_id' => 'nullable|integer|exists:departments,id', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'start_time' => 'nullable|required_with:end_time|date_format:H:i', 'end_time' => 'nullable|required_with:start_time|date_format:H:i', 'ends_next_day' => 'sometimes|boolean', 'reason' => 'required|string|min:5|max:3000']);
        if (! empty($v['employee_id']) && ! empty($v['department_id'])) {
            throw ValidationException::withMessages(['employee_id' => 'اختر موظفًا أو قسمًا أو اتركهما لتشمل الجمعية.']);
        }
        DB::transaction(function () use ($r, $v) {
            $id = DB::table('attendance_overrides')->insertGetId($v + ['status' => 'pending', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'override', $id, 'submitted');
        });

        return back()->with('success', 'أُرسل الاستثناء للاعتماد.');
    }

    public function decide(Request $r, string $type, int $id)
    {
        $this->approver($r);
        abort_unless(in_array($type, ['assignment', 'override'], true), 404);
        $v = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        $table = $type === 'assignment' ? 'attendance_assignments' : 'attendance_overrides';
        DB::transaction(function () use ($r, $type, $id, $table, $v) {
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'تم البت في هذا الطلب مسبقًا.']);
            }
            if ((int) $row->created_by === (int) $r->user()->id) {
                throw ValidationException::withMessages(['decision' => 'لا يعتمد مقدم الطلب طلبه.']);
            }
            DB::table($table)->where('id', $id)->update(['status' => $v['decision'], 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit($r, $type, $id, $v['decision']);
            app(AttendanceDailyService::class)->refreshExisting(
                $row->employee_id ? (int) $row->employee_id : null,
                (string) $row->starts_on, $row->ends_on ? (string) $row->ends_on : null,
            );
            $userId = $v['decision'] === 'approved' && $row->employee_id ? DB::table('employees')->where('id', $row->employee_id)->value('user_id') : null;
            if ($userId) {
                app(NotificationCenter::class)->notify((int) $userId, 'attendance_schedule_changed', [
                    'title' => $type === 'assignment' ? 'اعتُمد جدول دوام جديد لك' : 'اعتُمد استثناء على جدول دوامك: '.$row->label,
                    'body' => 'من '.$row->starts_on.($row->ends_on ? ' إلى '.$row->ends_on : ''), 'url' => '/my/profile?tab=attendance',
                    'dedupe' => 'attendance-schedule:'.$type.':'.$id,
                ]);
            }
        });

        return back()->with('success', 'تم تسجيل القرار.');
    }

    public function manual(Request $r, AttendanceDailyService $daily)
    {
        $this->staff($r);
        $v = $r->validate(['employee_id' => 'required|integer|exists:employees,id', 'occurred_at' => 'required|date', 'direction' => ['required', Rule::in(['in', 'out'])], 'reason' => 'required|string|min:5|max:2000']);
        if (! DB::table('employees')->where('id', $v['employee_id'])->whereNull('deleted_at')->where('employment_status', 'active')->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'الموظف غير نشط.']);
        }
        $when = CarbonImmutable::parse($v['occurred_at'], config('app.timezone'));
        if ($when->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['occurred_at' => 'لا يسمح بتسجيل حركة مستقبلية.']);
        }
        $same = DB::table('attendance_punches')->where('employee_id', $v['employee_id'])->where('direction', $v['direction'])->whereBetween('occurred_at', [$when->subMinute()->utc()->toDateTimeString(), $when->addMinute()->utc()->toDateTimeString()])->exists();
        if ($same) {
            throw ValidationException::withMessages(['occurred_at' => 'توجد حركة مماثلة في الفترة نفسها.']);
        }
        DB::transaction(function () use ($r, $v, $when, $daily) {
            $id = DB::table('attendance_punches')->insertGetId(['employee_id' => $v['employee_id'], 'source' => 'manual', 'direction' => $v['direction'], 'occurred_at' => $when->utc()->toDateTimeString(), 'source_key' => 'manual:'.(string) Str::uuid(), 'reason' => $v['reason'], 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'punch', $id, 'manual_created', ['reason' => $v['reason']]);
            $daily->recalculateAroundPunch((int) $v['employee_id'], $when);
        });

        return back()->with('success', 'تم تسجيل الحركة يدويًا.');
    }
}
