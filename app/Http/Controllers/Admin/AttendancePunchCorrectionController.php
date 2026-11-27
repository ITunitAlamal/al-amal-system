<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Attendance\AttendanceAccess;
use App\Services\Attendance\AttendanceDailyService;
use App\Services\Hr\EmployeeNames;
use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AttendancePunchCorrectionController extends Controller
{
    /** To whoever requested a punch correction: it was approved or rejected. */
    public const EVENT_DECIDED = 'attendance_correction_decided';

    /** Where pending corrections are listed and decided («الحضور والانصراف» ← «تصحيح البصمات»). */
    public const TASKS_URL = '/admin/attendance?tab=corrections';

    private const DECIDER_RULE = 'يقرر تصحيح البصمة رئيس قسم الموارد البشرية، أو المدير التنفيذي إن كان رئيس القسم مقدم الطلب أو صاحب البصمة؛ ولا يقرره مقدم الطلب ولا صاحب البصمة.';

    /**
     * Whether decide() accepts this user at all (owner decision 8): the HR head or the executive director, never the
     * system administrator. Each correction is narrowed by AttendanceAccess::correctionsToDecide, which the task
     * inbox (TransactionTracker) and the pages use for the same rows.
     */
    public static function mayDecide(?User $user): bool
    {
        return $user !== null && app(AttendanceAccess::class)->mayDecideCorrections($user);
    }

    private function hr(Request $request): void
    {
        abort_unless($request->user()?->is_active && ($request->user()->isSystemAdmin() || $request->user()->isHrDepartmentHead()), 403);
    }

    private function admin(Request $request): void
    {
        abort_unless(self::mayDecide($request->user()), 403, self::DECIDER_RULE);
    }

    /** This correction, in its current status, is this user's to decide. */
    private function decider(Request $request, int $correction, string $status): void
    {
        abort_unless((bool) app(AttendanceAccess::class)->correctionsToDecide((int) $request->user()->id, $status)?->where('c.id', $correction)->exists(), 403, self::DECIDER_RULE);
    }

    private function audit(Request $request, int $punchId, string $action, array $details): void
    {
        DB::table('attendance_audit_events')->insert([
            'entity_type' => 'punch', 'entity_id' => $punchId, 'action' => $action,
            'actor_id' => $request->user()->id,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function requestCorrection(Request $request, int $punch): RedirectResponse
    {
        $this->hr($request);
        $data = $request->validate([
            'corrected_direction' => ['required', Rule::in(['in', 'out'])],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $punch, $data): void {
            $row = DB::table('attendance_punches')->where('id', $punch)->lockForUpdate()->first();
            abort_unless($row && $row->employee_id && $row->source === 'device' && $row->raw_payload && in_array($row->match_status, ['matched', 'reviewed'], true), 409);
            $pending = DB::table('attendance_punch_corrections')->where('punch_id', $punch)->where('status', 'pending')->exists();
            abort_if($pending, 409, 'يوجد طلب تصحيح معلق لهذه الحركة.');
            $effective = DB::table('attendance_punch_corrections')->where('punch_id', $punch)->where('status', 'approved')->orderByDesc('id')->value('corrected_direction') ?? $row->direction;
            if ($effective === $data['corrected_direction']) {
                throw ValidationException::withMessages(['corrected_direction' => 'الاتجاه المطلوب هو الاتجاه المعتمد حاليًا.']);
            }
            $id = DB::table('attendance_punch_corrections')->insertGetId([
                'punch_id' => $punch, 'original_direction' => $row->direction,
                'corrected_direction' => $data['corrected_direction'], 'reason' => $data['reason'],
                'status' => 'pending', 'requested_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // A pending correction blocks new ones on the punch, so one nobody may decide is not recorded.
            if (! app(AttendanceAccess::class)->correctionHasDecider($id)) {
                throw ValidationException::withMessages(['corrected_direction' => 'لا يوجد اليوم من يقرر هذا الطلب: '.self::DECIDER_RULE]);
            }
            $this->audit($request, $punch, 'direction_correction_requested', ['correction_id' => $id, 'requested_direction' => $data['corrected_direction'], 'reason' => $data['reason']]);
        });

        return back()->with('success', 'سُجّل طلب تصحيح البصمة بانتظار قرار رئيس قسم الموارد البشرية (أو المدير التنفيذي إن كان رئيس القسم مقدمه أو صاحب البصمة).');
    }

    public function decide(Request $request, int $correction): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $correction, $data): void {
            $row = DB::table('attendance_punch_corrections')->where('id', $correction)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'pending', 409);
            abort_if((int) $row->requested_by === (int) $request->user()->id, 403, 'لا يجوز اعتماد الطلب بواسطة مقدمه.');
            $this->decider($request, $correction, 'pending');
            $punch = DB::table('attendance_punches')->where('id', $row->punch_id)->lockForUpdate()->first();
            abort_unless($punch && $punch->employee_id, 409);
            if ($data['decision'] === 'approve') {
                abort_if(DB::table('attendance_punch_corrections')->where('punch_id', $row->punch_id)->where('status', 'approved')->exists(), 409, 'يوجد تصحيح معتمد؛ يجب إلغاؤه أولًا.');
            }
            DB::table('attendance_punch_corrections')->where('id', $correction)->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $request->user()->id, 'review_note' => $data['review_note'] ?? null,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, (int) $row->punch_id, 'direction_correction_'.$data['decision'], ['correction_id' => $correction, 'original_direction' => $row->original_direction, 'corrected_direction' => $row->corrected_direction, 'review_note' => $data['review_note'] ?? null]);
            if ($data['decision'] === 'approve') {
                app(AttendanceDailyService::class)->recalculateAroundPunch((int) $punch->employee_id, CarbonImmutable::parse($punch->occurred_at));
            }
            // Whoever asked for the correction hears the decision (never the decider: they cannot be the same person).
            $employee = DB::table('employees')->where('id', $punch->employee_id)->first(['full_name_ar', 'first_name_ar', 'father_name_ar', 'grandfather_name_ar', 'family_name_ar']);
            $day = CarbonImmutable::parse($punch->occurred_at, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i');
            $note = trim((string) ($data['review_note'] ?? ''));
            app(NotificationCenter::class)->notify((int) $row->requested_by, self::EVENT_DECIDED, [
                'title' => ($data['decision'] === 'approve' ? 'اعتُمد طلب تصحيح البصمة' : 'رُفض طلب تصحيح البصمة').($employee ? ': '.EmployeeNames::display($employee) : ''),
                'body' => 'حركة '.$day.($data['decision'] === 'approve' ? ' · أُعيد احتساب الدوام' : '').($note !== '' ? ' · ملاحظة المراجع: '.$note : ''),
                'url' => self::TASKS_URL, 'dedupe' => 'punch-correction:'.$correction.':decided',
            ]);
        });

        return back()->with('success', 'تم تسجيل القرار وإعادة احتساب الدوام عند الاعتماد، وأُبلغ مقدم الطلب.');
    }

    public function revoke(Request $request, int $correction): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($request, $correction, $data): void {
            $row = DB::table('attendance_punch_corrections')->where('id', $correction)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'approved', 409);
            $this->decider($request, $correction, 'approved');
            $punch = DB::table('attendance_punches')->where('id', $row->punch_id)->lockForUpdate()->first();
            abort_unless($punch && $punch->employee_id, 409);
            DB::table('attendance_punch_corrections')->where('id', $correction)->update(['status' => 'revoked', 'revoked_by' => $request->user()->id, 'revoked_at' => now(), 'revocation_reason' => $data['reason'], 'updated_at' => now()]);
            $this->audit($request, (int) $row->punch_id, 'direction_correction_revoked', ['correction_id' => $correction, 'reason' => $data['reason']]);
            app(AttendanceDailyService::class)->recalculateAroundPunch((int) $punch->employee_id, CarbonImmutable::parse($punch->occurred_at));
        });

        return back()->with('success', 'أُلغي التصحيح مع حفظ سجل التدقيق وإعادة احتساب الدوام.');
    }
}
