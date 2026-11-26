<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\OrphanChild;
use App\Models\OrphanHousehold;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanVisits;
use App\Services\Orphans\ScoringEngine;
use App\Services\Orphans\ScoringRulesets;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Social visits, scoring rulesets (both systems) and the general social score of a child. */
final class VisitController extends Controller
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function plan(Request $request, string $publicId, OrphanVisits $visits): RedirectResponse
    {
        $household = OrphanHousehold::where('public_id', $publicId)->firstOrFail();
        $id = $visits->plan($request->user(), $household->id, $request->all());

        return redirect()->route('orphans.visits.show', $id)->with('success', 'جُدولت الزيارة.');
    }

    public function show(Request $request, int $visit, OrphanVisits $visits, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $row = DB::table('orphan_social_visits as v')->join('orphan_households as h', 'h.id', '=', 'v.household_id')->leftJoin('users as u', 'u.id', '=', 'v.researcher_id')
            ->leftJoin('users as r', 'r.id', '=', 'v.reviewed_by')->where('v.id', $visit)
            ->first(['v.*', 'h.public_id as household_public_id', 'h.reference_no as household_reference', 'u.name as researcher', 'r.name as reviewer']);
        abort_unless($row, 404);
        $this->access->logRead($user, 'visit', $visit);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $own = (int) $row->researcher_id === (int) $user->id;

        return Inertia::render('Orphans/Visit', [
            'visit' => ['id' => $row->id, 'reference_no' => $row->reference_no, 'status' => $row->status, 'status_label' => OrphanVisits::STATUS_LABELS[$row->status] ?? $row->status,
                'reason' => $row->reason, 'scheduled_on' => $row->scheduled_on, 'visited_on' => $row->visited_on, 'next_visit_on' => $row->next_visit_on,
                'researcher' => $row->researcher, 'reviewer' => $row->reviewer, 'review_note' => $row->review_note, 'attested_at' => $row->attested_at,
                'type' => $row->visit_type_value_id ? DB::table('master_data_values')->where('id', $row->visit_type_value_id)->value('name_ar') : null,
                'child' => $row->orphan_child_id ? OrphanChild::find($row->orphan_child_id)?->full_name : null,
                'needs' => json_decode((string) $row->needs, true) ?: [], 'action_items' => json_decode((string) $row->action_items, true) ?: [],
                'household' => ['public_id' => $row->household_public_id, 'reference_no' => $row->household_reference]] + $visits->findings($row),
            'canReport' => $own && in_array($row->status, ['planned', 'returned'], true),
            'canSubmit' => $own && $row->status === 'planned' && ! $row->workflow_instance_id,
            'progress' => $instance ? $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision,
                'status' => $instance->status, 'actions' => $engine->availableActions($instance, $user)] : null,
        ]);
    }

    public function report(Request $request, int $visit, OrphanVisits $visits): RedirectResponse
    {
        $visits->report($request->user(), $visit, $request->all());

        return back()->with('success', 'حُفظ تقرير الزيارة.');
    }

    public function submit(Request $request, int $visit, OrphanVisits $visits, WorkflowEngine $engine): RedirectResponse
    {
        $visits->submit($request->user(), $visit, $engine);

        return back()->with('success', 'رُفع التقرير لرئيس القسم بعد إقرارك بصحته.');
    }

    public function rulesets(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, 'view'), 403);

        return Inertia::render('Orphans/Scoring', [
            'rulesets' => DB::table('scoring_rulesets as r')->leftJoin('users as u', 'u.id', '=', 'r.created_by')->leftJoin('users as a', 'a.id', '=', 'r.approved_by')
                ->where('r.kind', 'social')->orderByDesc('r.version')->get(['r.id', 'r.version', 'r.name', 'r.status', 'r.created_at', 'r.approved_at', 'u.name as author', 'a.name as approver'])
                ->map(fn ($r) => (array) $r + ['status_label' => ScoringRulesets::STATUS_LABELS[$r->status] ?? $r->status]),
            'selected' => null, 'factors' => $this->factorCatalog('social'), 'missing' => ScoringEngine::MISSING,
            'canDraft' => $this->access->allows($user, 'records'), 'kind' => 'social', 'campaign' => null,
        ]);
    }

    public function ruleset(Request $request, int $ruleset, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, 'view') || $this->access->allows($user, 'campaigns'), 403);
        $row = DB::table('scoring_rulesets')->where('id', $ruleset)->first();
        abort_unless($row, 404);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;

        return Inertia::render('Orphans/Scoring', [
            'rulesets' => [], 'factors' => $this->factorCatalog($row->kind), 'missing' => ScoringEngine::MISSING, 'kind' => $row->kind,
            'campaign' => $row->campaign_id ? DB::table('relief_campaigns')->where('id', $row->campaign_id)->first(['id', 'public_id', 'reference_no', 'title']) : null,
            'selected' => ['id' => $row->id, 'version' => $row->version, 'name' => $row->name, 'status' => $row->status, 'status_label' => ScoringRulesets::STATUS_LABELS[$row->status] ?? $row->status,
                'factors' => json_decode($row->factors, true), 'tie_breakers' => json_decode((string) $row->tie_breakers, true) ?: [], 'notes' => $row->notes,
                'factors_hash' => $row->factors_hash, 'decision_note' => $row->decision_note, 'editable' => $row->status === 'draft',
                'can_submit' => $row->status === 'draft' && ! $row->workflow_instance_id],
            'canDraft' => $this->access->allows($user, $row->kind === 'campaign' ? 'campaigns' : 'records'),
            'progress' => $instance ? $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision,
                'status' => $instance->status, 'actions' => $engine->availableActions($instance, $user)] : null,
        ]);
    }

    public function saveRuleset(Request $request, ScoringRulesets $rulesets): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:social,campaign'], 'campaign_id' => ['nullable', 'integer'], 'ruleset_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:200'], 'factors' => ['required', 'array'], 'tie_breakers' => ['nullable', 'array'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if ($data['kind'] === 'campaign') {
            abort_unless(! empty($data['campaign_id']) && DB::table('relief_campaigns')->where('id', $data['campaign_id'])->exists(), 422);
        }
        $id = $rulesets->saveDraft($request->user(), $data['kind'], $data['kind'] === 'campaign' ? (int) $data['campaign_id'] : null, $data, $data['ruleset_id'] ?? null);

        return redirect()->route('orphans.scoring.show', $id)->with('success', 'حُفظت مسودة القواعد. لا تُستخدم قبل اعتماد المدير التنفيذي.');
    }

    public function submitRuleset(Request $request, int $ruleset, ScoringRulesets $rulesets, WorkflowEngine $engine): RedirectResponse
    {
        $rulesets->submit($request->user(), $ruleset, $engine);

        return back()->with('success', 'رُفعت القواعد لرئيس القسم ثم المدير التنفيذي.');
    }

    public function computeScore(Request $request, string $publicId, ScoringRulesets $rulesets): RedirectResponse
    {
        $rulesets->computeSocial($request->user(), OrphanChild::where('public_id', $publicId)->firstOrFail());

        return back()->with('success', 'حُسب التقييم الاجتماعي بالإصدار المعتمد؛ التقييم أداة مراجعة ولا يمنح أو يحجب مساعدة بذاته.');
    }

    public function overrideScore(Request $request, int $score, ScoringRulesets $rulesets): RedirectResponse
    {
        $data = $request->validate(['total' => ['required', 'numeric', 'between:0,100000'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $rulesets->override($request->user(), $score, (float) $data['total'], $data['reason']);

        return back()->with('success', 'سُجل تعديل التقييم بمبرره مع بقاء القيمة المحسوبة ظاهرة.');
    }

    /** @return list<array{code: string, label: string, type: string, values: list<array{code: string, name: string}>}> */
    public function catalog(string $kind): array
    {
        return $this->factorCatalog($kind);
    }

    /** @return list<array{code: string, label: string, type: string, values: list<array{code: string, name: string}>}> */
    private function factorCatalog(string $kind): array
    {
        $typeOf = ['orphan_class' => 'orphan_classifications', 'shelter_type' => 'shelter_types', 'current_tenure' => 'housing_tenures', 'needs_level' => 'needs_levels',
            'economic_status' => 'economic_statuses', 'education_enrollment' => 'enrollment_statuses', 'governorate' => 'governorates'];
        $values = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->whereIn('t.code', array_values($typeOf))
            ->where('v.is_active', true)->where(fn ($q) => $q->where('t.code', '<>', 'governorates')->orWhereNull('v.parent_id'))
            ->orderBy('v.sort_order')->get(['t.code as type', 'v.code', 'v.name_ar'])->groupBy('type');

        return collect(ScoringEngine::FACTORS)->filter(fn ($f) => $f[2] === 'both' || $kind === 'campaign')
            ->map(fn ($f, $code) => ['code' => $code, 'label' => $f[0], 'type' => $f[1],
                'values' => isset($typeOf[$code]) ? ($values[$typeOf[$code]] ?? collect())->map(fn ($v) => ['code' => $v->code, 'name' => $v->name_ar])->unique('code')->values()->all() : []])
            ->values()->all();
    }
}
