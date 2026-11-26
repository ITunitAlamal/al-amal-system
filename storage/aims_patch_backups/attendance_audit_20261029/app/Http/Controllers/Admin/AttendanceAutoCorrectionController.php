<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceAutoCorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AttendanceAutoCorrectionController extends Controller
{
    private function hr(Request $r): void
    {
        abort_unless($r->user()?->is_active && ($r->user()->isSystemAdmin() || $r->user()->isHrDepartmentHead()), 403);
    }

    public function index(Request $r, AttendanceAutoCorrectionService $service): Response
    {
        $this->hr($r);
        $date = $r->input('date', CarbonImmutable::now(config('app.timezone'))->toDateString());
        abort_unless(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date), 422);
        $name = trim((string) $r->input('name', ''));
        abort_unless(mb_strlen($name) <= 100, 422);
        $query = DB::table('attendance_daily_results as d')->join('employees as e', 'e.id', '=', 'd.employee_id')
            ->where('d.work_date', $date)
            ->when($name !== '', fn ($q) => $q->where(function ($w) use ($name) {
                $w->where('e.first_name_ar', 'ilike', '%'.$name.'%')->orWhere('e.family_name_ar', 'ilike', '%'.$name.'%');
            }))
            ->orderBy('e.first_name_ar')->limit(300)
            ->get(['d.employee_id', 'd.work_date', 'd.expected_in', 'd.actual_in', 'd.actual_out', 'd.expected_out', 'd.late_minutes', 'd.early_minutes', 'd.status', 'd.grace_minutes', 'e.first_name_ar', 'e.family_name_ar']);
        $ledger = $query->map(function ($row) {
            $row->punctuality = 'neutral';
            $row->punctuality_label = 'لم يُحسم';
            $row->actual_delay_minutes = null;
            if ($row->status === 'grace_unapproved') {
                $row->punctuality_label = 'فترة السماح غير معتمدة';

                return $row;
            }
            if (! $row->actual_in || ! $row->expected_in || ! in_array($row->status, ['present', 'early', 'late', 'incomplete_punches'], true)) {
                return $row;
            }
            $expected = CarbonImmutable::parse($row->expected_in);
            $actual = CarbonImmutable::parse($row->actual_in);
            $seconds = $expected->getTimestamp() < $actual->getTimestamp() ? $actual->getTimestamp() - $expected->getTimestamp() : 0;
            $delay = (int) floor($seconds / 60);
            $grace = $row->grace_minutes === null ? null : (int) $row->grace_minutes;
            if ($grace === null) {
                return $row;
            }
            $row->actual_delay_minutes = $delay;
            if ($seconds === 0) {
                $row->punctuality = 'on_time';
                $row->punctuality_label = 'ملتزم — في الموعد';
            } elseif ($delay <= $grace && $seconds <= $grace * 60) {
                $row->punctuality = 'grace_used';
                $row->punctuality_label = "ضمن السماح: {$delay} من {$grace} دقائق";
            } else {
                $row->punctuality = 'late';
                $row->punctuality_label = 'متأخر '.max(0, $delay - $grace).' دقيقة بعد السماح';
            }

            return $row;
        });
        $corrections = DB::table('attendance_auto_corrections as c')
            ->join('employees as e', 'e.id', '=', 'c.employee_id')->orderByDesc('c.id')->limit(100)
            ->get(['c.id', 'c.punch_id', 'c.original_direction', 'c.corrected_direction', 'c.reason_ar', 'c.status', 'c.applied_at', 'c.reversion_reason', 'e.first_name_ar', 'e.family_name_ar']);

        return Inertia::render('Admin/Attendance/AutoCorrections', [
            'date' => $date, 'name' => $name, 'ledger' => $ledger, 'corrections' => $corrections,
            'suggestions' => $service->suggestions(7, 50),
            'canRevert' => $r->user()->isSystemAdmin(),
            'autoEnabled' => (bool) config('attendance.auto_direction_enabled', false),
        ]);
    }

    public function revert(Request $r, int $correction, AttendanceAutoCorrectionService $service): RedirectResponse
    {
        $this->hr($r);
        abort_unless($r->user()->isSystemAdmin(), 403);
        $input = $r->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $service->revert($correction, (int) $r->user()->id, $input['reason']);

        return back()->with('success','تم التراجع عن التصحيح مع حفظ ملاحظة التراجع وإعادة احتساب الدوام.');
    }
}
