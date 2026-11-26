<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceDailyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AttendanceScheduleManageController extends Controller
{
    private function staff(Request $r): void
    {
        abort_unless($r->user()?->is_active && ($r->user()->isSystemAdmin() || $r->user()->isHrDepartmentHead() || $this->executive((int) $r->user()->id)), 403);
    }

    private function canApprove(Request $r): bool
    {
        return $r->user()?->is_active && ($r->user()->isSystemAdmin() || $this->executive((int) $r->user()->id));
    }

    private function executive(int $id): bool
    {
        return DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')->where('a.user_id', $id)
            ->where('r.code', 'executive_director')->where('u.is_active', true)->exists();
    }

    private function audit(Request $r, int $id, string $action, array $details): void
    {
        DB::table('attendance_audit_events')->insert([
            'entity_type' => 'schedule', 'entity_id' => $id, 'action' => $action,
            'actor_id' => $r->user()->id, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function requestChange(Request $r, int $schedule)
    {
        $this->staff($r);
        $v = $r->validate([
            'action' => ['required', Rule::in(['edit', 'set_default', 'activate', 'deactivate'])],
            'name' => ['required_if:action,edit', 'nullable', 'string', 'max:150'],
            'days' => ['required_if:action,edit', 'nullable', 'array', 'min:1', 'max:7'],
            'days.*.weekday' => ['required', 'integer', 'between:1,7'],
            'days.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.end_time' => ['required', 'date_format:H:i'],
            'days.*.ends_next_day' => ['sometimes', 'boolean'],
        ]);
        if ($v['action'] === 'edit') {
            $weekdays = array_column($v['days'], 'weekday');
            if (count($weekdays) !== count(array_unique($weekdays))) {
                throw ValidationException::withMessages(['days' => 'يوجد يوم مكرر.']);
            }
            foreach ($v['days'] as $d) {
                if ($d['start_time'] === $d['end_time'] || ($d['end_time'] < $d['start_time'] && empty($d['ends_next_day']))) {
                    throw ValidationException::withMessages(['days' => 'تحقق من وقت البداية والنهاية وخيار اليوم التالي.']);
                }
            }
        }
        DB::transaction(function () use ($r, $schedule, $v) {
            $row = DB::table('attendance_schedules')->where('id', $schedule)->lockForUpdate()->first();
            abort_unless($row, 404);
            if (DB::table('attendance_schedule_change_requests')->where('schedule_id', $schedule)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['action' => 'هناك تعديل سابق بانتظار الاعتماد.']);
            }
            if ($v['action'] === 'edit' && DB::table('attendance_assignments')->where('schedule_id', $schedule)->where('status', 'approved')->exists()) {
                throw ValidationException::withMessages(['action' => 'القالب مرتبط بتكليف معتمد؛ أنشئ قالبًا جديدًا للحفاظ على السجلات التاريخية.']);
            }
            if ($v['action'] === 'deactivate' && $row->is_default) {
                throw ValidationException::withMessages(['action' => 'غيّر القالب الافتراضي أولًا.']);
            }
            if ($v['action'] === 'set_default' && ! $row->is_active) {
                throw ValidationException::withMessages(['action' => 'لا يمكن تعيين قالب معطل كافتراضي.']);
            }
            $id = DB::table('attendance_schedule_change_requests')->insertGetId([
                'schedule_id' => $schedule, 'action' => $v['action'],
                'payload' => json_encode($v['action'] === 'edit' ? ['name' => $v['name'], 'days' => $v['days']] : [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'status' => 'pending', 'requested_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($r, $schedule, 'change_requested', ['request_id' => $id, 'action' => $v['action']]);
        });

        return back()->with('success', 'أرسل التعديل للاعتماد.');
    }

    public function decide(Request $r, int $requestId)
    {
        abort_unless($this->canApprove($r), 403);
        $v = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        DB::transaction(function () use ($r, $requestId, $v) {
            $req = DB::table('attendance_schedule_change_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($req, 404);
            if ($req->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'تم البت في الطلب مسبقًا.']);
            }
            $row = DB::table('attendance_schedules')->where('id', $req->schedule_id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($v['decision'] === 'approved') {
                if ($req->action === 'edit') {
                    if (DB::table('attendance_assignments')->where('schedule_id', $row->id)->where('status', 'approved')->exists()) {
                        throw ValidationException::withMessages(['decision' => 'أصبح القالب مرتبطًا بتكليف معتمد؛ لا يمكن تعديل بياناته التاريخية.']);
                    }
                    $payload = json_decode($req->payload, true, 512, JSON_THROW_ON_ERROR);
                    DB::table('attendance_schedules')->where('id', $row->id)->update(['name' => $payload['name'], 'updated_at' => now()]);
                    DB::table('attendance_schedule_days')->where('schedule_id', $row->id)->delete();
                    foreach ($payload['days'] as $d) {
                        DB::table('attendance_schedule_days')->insert([
                            'schedule_id' => $row->id, 'weekday' => $d['weekday'], 'start_time' => $d['start_time'],
                            'end_time' => $d['end_time'], 'ends_next_day' => (bool) ($d['ends_next_day'] ?? false),
                        ]);
                    }
                } elseif ($req->action === 'set_default') {
                    if (! $row->is_active) {
                        throw ValidationException::withMessages(['decision' => 'القالب غير نشط.']);
                    }
                    DB::table('attendance_schedules')->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);
                    DB::table('attendance_schedules')->where('id', $row->id)->update(['is_default' => true, 'updated_at' => now()]);
                } elseif ($req->action === 'activate') {
                    DB::table('attendance_schedules')->where('id', $row->id)->update(['is_active' => true, 'updated_at' => now()]);
                } elseif ($req->action === 'deactivate') {
                    if ($row->is_default || DB::table('attendance_assignments')->where('schedule_id', $row->id)->where('status', 'approved')->where(function ($q) {
                        $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString());
                    })->exists()) {
                        throw ValidationException::withMessages(['decision' => 'القالب افتراضي أو مرتبط بتكليف سارٍ؛ يجب تغييره أولًا.']);
                    }
                    DB::table('attendance_schedules')->where('id', $row->id)->update(['is_active' => false, 'updated_at' => now()]);
                }
            }
            DB::table('attendance_schedule_change_requests')->where('id', $requestId)->update([
                'status' => $v['decision'], 'decided_by' => $r->user()->id, 'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($r, $row->id, 'change_'.$v['decision'], ['request_id' => $requestId, 'action' => $req->action]);
            app(AttendanceDailyService::class)->refreshExisting();
        });

        return back()->with('success', 'تم تسجيل قرار التعديل.');
    }
}
