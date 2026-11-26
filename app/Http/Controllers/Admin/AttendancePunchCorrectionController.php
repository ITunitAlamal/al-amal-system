<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceDailyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AttendancePunchCorrectionController extends Controller
{
    private function hr(Request $request): void
    {
        abort_unless($request->user()?->is_active && ($request->user()->isSystemAdmin() || $request->user()->isHrDepartmentHead()), 403);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()?->is_active && $request->user()->isSystemAdmin(), 403);
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
            $this->audit($request, $punch, 'direction_correction_requested', ['correction_id' => $id, 'requested_direction' => $data['corrected_direction'], 'reason' => $data['reason']]);
        });

        return back()->with('success', 'تم تسجيل طلب تصحيح البصمة للمراجعة الإدارية.');
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
        });

        return back()->with('success', 'تم تسجيل القرار وإعادة احتساب الدوام عند الاعتماد.');
    }

    public function revoke(Request $request, int $correction): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        DB::transaction(function () use ($request, $correction, $data): void {
            $row = DB::table('attendance_punch_corrections')->where('id', $correction)->lockForUpdate()->first();
            abort_unless($row && $row->status === 'approved', 409);
            $punch = DB::table('attendance_punches')->where('id', $row->punch_id)->lockForUpdate()->first();
            abort_unless($punch && $punch->employee_id, 409);
            DB::table('attendance_punch_corrections')->where('id', $correction)->update(['status' => 'revoked', 'revoked_by' => $request->user()->id, 'revoked_at' => now(), 'revocation_reason' => $data['reason'], 'updated_at' => now()]);
            $this->audit($request, (int) $row->punch_id, 'direction_correction_revoked', ['correction_id' => $correction, 'reason' => $data['reason']]);
            app(AttendanceDailyService::class)->recalculateAroundPunch((int) $punch->employee_id, CarbonImmutable::parse($punch->occurred_at));
        });

        return back()->with('success', 'أُلغي التصحيح مع حفظ سجل التدقيق وإعادة احتساب الدوام.');
    }
}
