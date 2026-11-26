<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceAutoCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Automatic direction corrections. Review now lives in the «التصحيحات» tab of /admin/attendance; the old address
 * redirects there. Reverting needs the HR head or the system administrator and a written reason.
 */
final class AttendanceAutoCorrectionController extends Controller
{
    private function hr(Request $r): void
    {
        abort_unless($r->user()?->is_active && ($r->user()->isSystemAdmin() || $r->user()->isHrDepartmentHead()), 403);
    }

    public function index(Request $r): RedirectResponse
    {
        $this->hr($r);

        return redirect('/admin/attendance?tab=corrections');
    }

    public function revert(Request $r, int $correction, AttendanceAutoCorrectionService $service): RedirectResponse
    {
        $this->hr($r);
        $input = $r->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        try {
            $service->revert($correction, (int) $r->user()->id, $input['reason']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['reason' => 'هذا التصحيح أُلغي مسبقًا أو غير موجود.']);
        }

        return back()->with('success', 'تم التراجع عن التصحيح مع حفظ سبب التراجع وإعادة احتساب الدوام؛ لن يُعاد تطبيقه تلقائيًا.');
    }
}
