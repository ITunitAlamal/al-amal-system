<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\OrphanHousehold;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanFollowups;
use App\Services\Orphans\OrphanForms;
use App\Services\Orphans\OrphanVisitPlans;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Case follow-ups (Q10, Q27) and visit plans (Q31); the services hold the rules. */
final class FollowupController extends Controller
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function schedule(Request $request, string $publicId, OrphanFollowups $followups): RedirectResponse
    {
        $this->ensureInstalled($followups->available());
        $followups->schedule($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(),
            $request->only(['due_on', 'reason', 'assigned_to', 'child_public_id']));

        return back()->with('success', 'جُدولت متابعة الحالة. يصل تذكير للمسؤول قبل موعدها، ويُنبَّه رئيس القسم إن تأخرت.');
    }

    public function complete(Request $request, int $followup, OrphanFollowups $followups): RedirectResponse
    {
        $followups->complete($request->user(), $followup, $request->only(['outcome', 'next_due_on']));

        return back()->with('success', 'سُجلت نتيجة المتابعة، وجُدولت التالية إن عُرف موعدها.');
    }

    public function cancel(Request $request, int $followup, OrphanFollowups $followups): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $followups->cancel($request->user(), $followup, $data['reason']);

        return back()->with('success', 'أُلغيت المتابعة وبقيت في سجل الملف.');
    }

    public function reassign(Request $request, int $followup, OrphanFollowups $followups): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['required', 'integer']]);
        $followups->reassign($request->user(), $followup, (int) $data['assigned_to']);

        return back()->with('success', 'أُسندت المتابعة للموظف المختار ونُبّه بها.');
    }

    public function interval(Request $request, string $publicId, OrphanFollowups $followups): RedirectResponse
    {
        $this->ensureInstalled($followups->available());
        $data = $request->validate(['months' => ['nullable', 'integer']]);
        $followups->setInterval($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(), $data['months'] ?? null);

        return back()->with('success', 'حُفظت دورية متابعة الحالة.');
    }

    public function plans(Request $request, OrphanVisitPlans $plans, OrphanFollowups $followups): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $this->ensureInstalled($plans->available());

        return Inertia::render('Orphans/VisitPlans', [
            'plans' => $plans->index(),
            'canManage' => $plans->canManage($user),
            'policy' => $followups->settings(),
        ]);
    }

    public function storePlan(Request $request, OrphanVisitPlans $plans): RedirectResponse
    {
        $this->ensureInstalled($plans->available());
        $publicId = $plans->create($request->user(), $request->only(['title', 'starts_on', 'ends_on', 'notes']));

        return redirect()->route('orphans.visit-plans.show', $publicId)->with('success', 'أُنشئت الخطة. اختر الأسر والباحثين لتوزيع الزيارات.');
    }

    public function showPlan(Request $request, string $publicId, OrphanVisitPlans $plans, OrphanForms $forms): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $this->ensureInstalled($plans->available());
        $plan = $plans->find($publicId);
        $manage = $plans->canManage($user);

        return Inertia::render('Orphans/VisitPlan', [
            'plan' => $plans->presentPlan($plan) + ['notes' => $plan->notes, 'author' => $plan->author],
            'progress' => $plans->progress($plan),
            'candidates' => $manage ? $plans->candidates($plan) : [],
            'researchers' => $manage ? $plans->researcherOptions() : [],
            'visitTypes' => $manage ? ($forms->options(['social_visit_types'])['social_visit_types'] ?? []) : [],
            'canManage' => $manage,
        ]);
    }

    public function assignPlan(Request $request, string $publicId, OrphanVisitPlans $plans): RedirectResponse
    {
        $count = $plans->assign($request->user(), $plans->find($publicId), $request->only(['researchers', 'rows', 'visit_type_value_id', 'reason']));

        return back()->with('success', "أُنشئت {$count} زيارة ووُزعت على الباحثين، ونُبّه كل باحث بزياراته.");
    }

    private function ensureInstalled(bool $available): void
    {
        abort_unless($available, 409, 'يلزم تنفيذ ترحيل المرحلة الثانية أولًا.');
    }
}
