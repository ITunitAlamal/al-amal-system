<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LeavePolicyLaunchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Keeps existing routes while displaying the approved policy draft, not removed test correction. */
final class LeavePolicyCorrectionController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->is_active && $request->user()->isSystemAdmin(), 403);
    }

    public function show(Request $request, int $correction, LeavePolicyLaunchService $service): Response
    {
        $this->guard($request);

        return Inertia::render('Admin/LeavePolicyCorrections/Show', [
            'username' => $request->user()->name,
            'navigation' => [['label' => 'سياسات الإجازات', 'url' => route('admin.leave-entitlement-rules.index'), 'icon' => '⚙', 'active' => false]],
            'preview' => $service->preview($correction),
        ]);
    }

    public function apply(Request $request, int $correction, LeavePolicyLaunchService $service): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'go_live_date' => ['required', 'date_format:Y-m-d'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $service->activate($correction, $data['go_live_date'], $request->user(), true);

        return redirect()->route('admin.leave-policy-corrections.show', $correction)
            ->with('success', 'تم تفعيل السياسة المعتمدة دون تغيير أرصدة الموظفين.');
    }
}
