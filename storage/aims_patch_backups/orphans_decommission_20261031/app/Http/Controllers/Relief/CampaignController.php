<?php

namespace App\Http\Controllers\Relief;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Orphans\VisitController;
use App\Models\OrphanChild;
use App\Models\User;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Finance\FinanceGuard;
use App\Services\Orphans\ScoringEngine;
use App\Services\Orphans\ScoringRulesets;
use App\Services\Relief\CampaignRosters;
use App\Services\Relief\FieldDevices;
use App\Services\Relief\FieldKeys;
use App\Services\Relief\FieldSync;
use App\Services\Relief\ReliefAccess;
use App\Services\Relief\ReliefBatches;
use App\Services\Relief\ReliefCampaigns;
use App\Services\Relief\ReliefClosure;
use App\Services\Relief\ReliefExceptions;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Relief campaigns of the Sponsorships & Orphans department: lifecycle, roster, exceptions, field work, closure. */
final class CampaignController extends Controller
{
    public function __construct(private readonly ReliefAccess $access, private readonly ReliefCampaigns $campaigns) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $prepare = $this->access->canPrepare($user);
        $executive = $this->access->orphans()->isExecutive($user);
        $memberOf = DB::table('relief_campaign_members')->where('user_id', $user->id)->whereNull('revoked_at')->where('ends_on', '>=', today()->toDateString())->pluck('campaign_id');
        abort_unless($prepare || $executive || $memberOf->isNotEmpty(), 403, 'لا تملك تكليفًا في الحملات الإغاثية.');
        $rows = DB::table('relief_campaigns')->when(! $prepare && ! $executive, fn ($q) => $q->whereIn('id', $memberOf))->orderByDesc('id')->limit(200)
            ->get(['id', 'public_id', 'reference_no', 'title', 'status', 'unit_of_benefit', 'starts_on', 'ends_on', 'priority']);

        return Inertia::render('Relief/Index', [
            // «الكفالات والمساعدات» switch: shown only when the viewer may open sponsorships too.
            'aidSwitch' => app(NavigationAccessRules::class)->allows($request->user(), 'orphans_sponsorships'),
            'campaigns' => $rows->map(fn ($c) => (array) $c + ['status_label' => ReliefCampaigns::STATUS_LABELS[$c->status] ?? $c->status,
                'unit_label' => ReliefCampaigns::UNITS[$c->unit_of_benefit] ?? $c->unit_of_benefit]),
            'can' => ['create' => $prepare, 'devices' => $this->access->orphans()->isHead($user) || $user->isSystemAdmin()],
            'options' => $prepare ? $this->formOptions() : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $id = $this->campaigns->save($request->user(), $request->all());

        return redirect()->route('relief.campaigns.show', DB::table('relief_campaigns')->where('id', $id)->value('public_id'))->with('success', 'أُنشئت مسودة الحملة؛ أضف البنود ثم ارفعها للاعتماد.');
    }

    public function show(Request $request, string $publicId, CampaignRosters $rosters, ReliefClosure $closure, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $campaign = $this->find($publicId);
        $this->access->assertView($user, (int) $campaign->id);
        $identities = $this->access->canSeeBeneficiaries($user);
        $pseudonyms = $this->access->canSeePseudonyms($user, (int) $campaign->id);
        $head = $this->access->orphans()->isHead($user);
        $prepare = $this->access->canPrepare($user);
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $flow = fn (?int $id) => $id ? DB::table('workflow_instances')->where('id', $id)->first() : null;
        $workflowView = fn ($instance) => $instance ? ['progress' => $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational'),
            'workflow' => ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision, 'status' => $instance->status,
                'actions' => $engine->availableActions($instance, $user)]] : null;
        $roster = DB::table('relief_rosters')->where('campaign_id', $campaign->id)->orderByDesc('revision')->first();
        $approvedRoster = DB::table('relief_rosters')->where('campaign_id', $campaign->id)->where('status', 'approved')->first();
        $entries = $roster && $pseudonyms ? DB::table('relief_roster_entries')->where('roster_id', $roster->id)->orderByRaw('rank_overall is null, rank_overall')->orderBy('unit_key')->limit(500)->get() : collect();
        $children = $identities ? OrphanChild::whereIn('id', $entries->pluck('orphan_child_id')->filter())->get()->keyBy('id') : collect();
        $households = $identities ? DB::table('orphan_households')->whereIn('id', $entries->pluck('household_id'))->pluck('reference_no', 'id') : collect();
        $allocations = DB::table('relief_allocations')->where('campaign_id', $campaign->id)->get();
        $events = DB::table('relief_delivery_events as e')->join('relief_batches as b', 'b.id', '=', 'e.batch_id')->where('b.campaign_id', $campaign->id);
        $summary = $closure->confirmedSummary((int) $campaign->id);
        $reach = DB::table('relief_allocations as a')->join('relief_roster_entries as e', 'e.id', '=', 'a.roster_entry_id')->where('a.campaign_id', $campaign->id)
            ->whereIn('a.status', ['delivered', 'partially_delivered'])->groupBy('e.area_value_id')->selectRaw('e.area_value_id, count(*) as n')->get()
            ->map(fn ($r) => ['area' => $values[$r->area_value_id] ?? 'غير محدد', 'units' => $r->n < 5 ? '<5' : (string) $r->n]);

        return Inertia::render('Relief/Campaign', [
            'campaign' => (array) $campaign + ['status_label' => ReliefCampaigns::STATUS_LABELS[$campaign->status] ?? $campaign->status,
                'unit_label' => ReliefCampaigns::UNITS[$campaign->unit_of_benefit] ?? $campaign->unit_of_benefit, 'type' => $values[$campaign->type_value_id] ?? null,
                'delivery_method' => $values[$campaign->delivery_method_value_id] ?? null, 'manager' => DB::table('users')->where('id', $campaign->manager_user_id)->value('name'),
                'eligibility' => json_decode((string) $campaign->eligibility, true) ?: [], 'capacity' => $this->campaigns->capacity((int) $campaign->id),
                'fund' => $campaign->finance_fund_id ? DB::table('finance_restricted_funds')->where('id', $campaign->finance_fund_id)->first(['name', 'currency', 'received', 'committed', 'spent']) : null],
            'items' => DB::table('relief_campaign_items as i')->leftJoin('finance_stock_items as s', 's.id', '=', 'i.stock_item_id')->where('i.campaign_id', $campaign->id)->orderBy('i.id')
                ->get(['i.*', 's.name as stock_item']),
            'approval' => $workflowView($flow($campaign->workflow_instance_id)),
            'closureFlow' => $workflowView($flow($campaign->closure_instance_id)),
            'ruleset' => DB::table('scoring_rulesets')->where('kind', 'campaign')->where('campaign_id', $campaign->id)->orderByDesc('version')->first(['id', 'version', 'name', 'status']),
            'roster' => $roster ? (array) $roster + ['status_label' => CampaignRosters::STATUS_LABELS[$roster->status] ?? $roster->status] + ($workflowView($flow($roster->workflow_instance_id)) ?? []) : null,
            'entries' => $entries->map(fn ($e) => ['id' => $e->id, 'pseudonym' => $e->pseudonym, 'unit_type' => $e->unit_type,
                'child_id' => $identities ? $e->orphan_child_id : null, 'household_id' => $identities ? $e->household_id : null,
                'name' => $identities ? ($e->orphan_child_id ? $children->get($e->orphan_child_id)?->full_name : 'أسرة '.$households[$e->household_id]) : null,
                'reference' => $identities ? ($e->orphan_child_id ? $children->get($e->orphan_child_id)?->file_no : $households[$e->household_id]) : null,
                'area' => $values[$e->area_value_id] ?? null, 'eligibility' => $e->eligibility, 'reasons' => json_decode((string) $e->eligibility_reasons, true) ?: [],
                'score' => $identities ? $e->score_total : null, 'components' => $identities ? json_decode((string) $e->score_components, true) : null,
                'rank' => $e->rank_overall, 'rank_area' => $e->rank_area, 'decision' => $e->decision, 'reason' => $e->reason, 'exception' => (bool) $e->exception_id]),
            'exceptions' => DB::table('relief_exceptions')->where('campaign_id', $campaign->id)->orderByDesc('id')->get(['id', 'reference_no', 'unit_key', 'status', 'category_value_id', 'created_at'])
                ->map(fn ($x) => ['id' => $x->id, 'reference_no' => $x->reference_no, 'status' => $x->status, 'category' => $values[$x->category_value_id] ?? '',
                    'status_label' => ReliefExceptions::STATUS_LABELS[$x->status] ?? $x->status, 'created_at' => $x->created_at]),
            'members' => DB::table('relief_campaign_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.campaign_id', $campaign->id)->orderByDesc('m.id')
                ->get(['m.id', 'm.role', 'm.starts_on', 'm.ends_on', 'm.revoked_at', 'm.may_view_pseudonymous', 'u.name'])
                ->map(fn ($m) => (array) $m + ['role_label' => ReliefAccess::ROLES[$m->role] ?? $m->role]),
            'batches' => DB::table('relief_batches as b')->join('users as u', 'u.id', '=', 'b.distributor_user_id')->join('field_devices as d', 'd.id', '=', 'b.device_id')
                ->where('b.campaign_id', $campaign->id)->orderByDesc('b.id')
                ->get(['b.id', 'b.reference_no', 'b.status', 'b.manifest_version', 'b.manifest_issued_at', 'b.offline_expires_at', 'u.name as distributor', 'd.name as device',
                    DB::raw('(select count(*) from relief_allocations a where a.batch_id = b.id) as allocations'),
                    DB::raw("(select count(*) from relief_delivery_events e where e.batch_id = b.id and e.status = 'confirmed') as confirmed"),
                    DB::raw("(select count(*) from relief_delivery_events e where e.batch_id = b.id and e.status = 'conflict_held') as held")])
                ->map(fn ($b) => (array) $b + ['status_label' => ReliefBatches::STATUS_LABELS[$b->status] ?? $b->status]),
            'held' => ($head || $identities) ? (clone $events)->whereIn('e.status', ['conflict_held', 'rejected'])->orderByDesc('e.id')->limit(100)
                ->get(['e.id', 'e.event_uuid', 'e.kind', 'e.status', 'e.status_reason', 'e.occurred_at', 'e.received_at', 'b.reference_no as batch'])
                ->map(fn ($e) => (array) $e + ['status_label' => FieldSync::STATUS_LABELS[$e->status] ?? $e->status]) : [],
            'reserved' => $head && $approvedRoster ? DB::table('relief_allocations as a')->join('relief_roster_entries as e', 'e.id', '=', 'a.roster_entry_id')
                ->where('a.roster_id', $approvedRoster->id)->whereIn('a.status', ['reserved', 'not_delivered'])->orderBy('e.rank_overall')->limit(500)
                ->get(['a.id', 'a.public_id', 'a.status', 'e.pseudonym', 'e.area_value_id'])->map(fn ($a) => (array) $a + ['area' => $values[$a->area_value_id] ?? 'غير محدد']) : [],
            'distributors' => $head ? DB::table('relief_campaign_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.campaign_id', $campaign->id)
                ->where('m.role', 'field_distributor')->whereNull('m.revoked_at')->where('m.ends_on', '>=', today()->toDateString())->get(['u.id', 'u.name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'devices' => DB::table('field_devices')->where('user_id', $u->id)->where('status', 'approved')->get(['id', 'name'])]) : [],
            'stats' => [
                'allocations' => $allocations->countBy('status'), 'confirmed_units' => $summary['units'], 'confirmed_quantities' => $summary['quantities'],
                'pending_sync' => DB::table('relief_batches')->where('campaign_id', $campaign->id)->where('status', 'downloaded')->count(),
                'held' => (clone $events)->where('e.status', 'conflict_held')->count(), 'reach' => $reach,
                'stock_issued' => DB::table('finance_stock_movements')->where('reference_type', 'relief_campaign')->where('reference_id', $campaign->id)->sum('quantity'),
            ],
            'reconciliations' => DB::table('relief_reconciliations as r')->join('users as u', 'u.id', '=', 'r.recorded_by')->where('r.campaign_id', $campaign->id)->orderByDesc('r.id')
                ->get(['r.kind', 'r.reference', 'r.outcome', 'r.confirmed_units', 'r.notes', 'r.created_at', 'u.name']),
            'closureBlockers' => in_array($campaign->status, ['active', 'suspended', 'closing'], true) ? $closure->blockers((int) $campaign->id) : [],
            'options' => $prepare ? $this->formOptions() + ['exception_categories' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'relief_exception_categories')->where('v.is_active', true)->get(['v.id', 'v.name_ar as name']),
                'staff' => DB::table('users')->where('is_active', true)->orderBy('name')->get(['id', 'name'])] : null,
            'can' => ['prepare' => $prepare, 'head' => $head, 'executive' => $this->access->orphans()->isExecutive($user), 'identities' => $identities, 'pseudonyms' => $pseudonyms,
                'admin' => $user->isSystemAdmin(), 'manager' => (int) $campaign->manager_user_id === (int) $user->id,
                'reconcile' => ! $user->isSystemAdmin() && (app(FinanceGuard::class)->allows($user, 'finance.stock.issue') || app(FinanceGuard::class)->allows($user, 'finance.sponsorships.record'))],
        ]);
    }

    public function update(Request $request, string $publicId): RedirectResponse
    {
        $this->campaigns->save($request->user(), $request->all(), (int) $this->find($publicId)->id);

        return back()->with('success', 'حُفظت الحملة.');
    }

    public function action(Request $request, string $publicId, string $action, WorkflowEngine $engine, CampaignRosters $rosters, ReliefExceptions $exceptions,
        ReliefBatches $batches, ReliefClosure $closure): RedirectResponse
    {
        $user = $request->user();
        $campaign = $this->find($publicId);
        $id = (int) $campaign->id;
        $message = match ($action) {
            'item' => tap('حُفظ البند.', fn () => $this->campaigns->saveItem($user, $id, $request->all(), $request->integer('item_id') ?: null)),
            'remove-item' => tap('حُذف البند من المسودة.', fn () => $this->campaigns->removeItem($user, $id, $request->integer('item_id'))),
            'submit' => tap('رُفعت الحملة لرئيس القسم ثم المدير التنفيذي.', fn () => $this->campaigns->submit($user, $id, $engine)),
            'activate' => tap('بدأت الحملة.', fn () => $this->campaigns->activate($user, $id)),
            'suspend' => tap('أُوقفت الحملة.', fn () => $this->campaigns->suspend($user, $id, true, (string) $request->input('reason'))),
            'resume' => tap('استُؤنفت الحملة.', fn () => $this->campaigns->suspend($user, $id, false, (string) $request->input('reason'))),
            'roster' => tap('أُعدت مسودة الكشف بالقواعد المعتمدة.', fn () => $rosters->generate($user, $id)),
            'roster-submit' => tap('رُفع الكشف للقرار.', fn () => $rosters->submit($user, $request->integer('roster_id'), $engine)),
            'exception' => tap('قُدّم طلب الاستثناء للمدير التنفيذي عبر رئيس القسم.', fn () => $exceptions->request($user, $id, $request->all(), $engine)),
            'member' => tap('سُجل التكليف محدد المدة.', fn () => $this->campaigns->grantMember($user, $id, $request->all())),
            'member-revoke' => tap('أُنهي التكليف.', fn () => $this->campaigns->revokeMember($user, $request->integer('member_id'), (string) $request->input('reason', 'إنهاء التكليف'))),
            'batch' => tap('أُسندت الدفعة للموزع.', fn () => $batches->create($user, $id, $request->integer('distributor_user_id'), $request->integer('device_id'), (array) $request->input('allocations', []), $request->integer('area_value_id') ?: null)),
            'batch-revoke' => tap('أُلغيت الدفعة وأعيد غير المسلّم للحجز.', fn () => $batches->revoke($user, $request->integer('batch_id'), (string) $request->input('reason'))),
            'batch-close' => tap('أُغلقت الدفعة.', fn () => $batches->close($user, $request->integer('batch_id'))),
            'requeue' => tap('أُعيد المستفيد للحجز لمحاولة تسليم جديدة.', fn () => $batches->requeue($user, $request->integer('allocation_id'))),
            'resolve' => tap('سُجل قرار المراجعة.', fn () => app(FieldSync::class)->resolve($user, $request->integer('event_id'), $request->boolean('confirm'), (string) $request->input('note'), $this->access->orphans())),
            'reconcile' => tap('سُجلت التسوية.', fn () => $closure->reconcile($user, $id, $request->all())),
            'close' => tap('رُفع طلب الإغلاق للقرار.', fn () => $closure->requestClosure($user, $id, $engine)),
            default => abort(404),
        };

        return back()->with('success', $message);
    }

    /** The campaign's own priority rulesets (separate from the general social score), edited in the shared scoring editor. */
    public function rulesets(Request $request, string $publicId): Response
    {
        $user = $request->user();
        $campaign = $this->find($publicId);
        abort_unless($this->access->canPrepare($user) || $this->access->orphans()->isExecutive($user), 403);

        return Inertia::render('Orphans/Scoring', [
            'rulesets' => DB::table('scoring_rulesets as r')->leftJoin('users as a', 'a.id', '=', 'r.approved_by')->where('r.kind', 'campaign')->where('r.campaign_id', $campaign->id)
                ->orderByDesc('r.version')->get(['r.id', 'r.version', 'r.name', 'r.status', 'a.name as approver'])
                ->map(fn ($r) => (array) $r + ['status_label' => ScoringRulesets::STATUS_LABELS[$r->status] ?? $r->status]),
            'selected' => null, 'kind' => 'campaign', 'campaign' => ['id' => $campaign->id, 'public_id' => $campaign->public_id, 'reference_no' => $campaign->reference_no, 'title' => $campaign->title],
            'factors' => app(VisitController::class)->catalog('campaign'), 'missing' => ScoringEngine::MISSING,
            'canDraft' => $this->access->canPrepare($user),
        ]);
    }

    public function exception(Request $request, int $exception, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $row = DB::table('relief_exceptions as x')->join('relief_campaigns as c', 'c.id', '=', 'x.campaign_id')->where('x.id', $exception)
            ->first(['x.*', 'c.public_id as campaign_public_id', 'c.reference_no as campaign_reference', 'c.title as campaign_title']);
        abort_unless($row, 404);
        abort_unless($this->access->canSeeBeneficiaries($user), 403);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $items = DB::table('relief_campaign_items')->where('campaign_id', $row->campaign_id)->pluck('label', 'id');
        $unit = $row->orphan_child_id ? OrphanChild::find($row->orphan_child_id) : null;

        return Inertia::render('Relief/Exception', [
            'exception' => ['id' => $row->id, 'reference_no' => $row->reference_no, 'status' => $row->status, 'status_label' => ReliefExceptions::STATUS_LABELS[$row->status] ?? $row->status,
                'category' => DB::table('master_data_values')->where('id', $row->category_value_id)->value('name_ar'), 'rule_violated' => $row->rule_violated, 'explanation' => $row->explanation,
                'staff_recommendation' => $row->staff_recommendation, 'head_recommendation' => $row->head_recommendation, 'decision_reason' => $row->decision_reason,
                'decided_by' => $row->decided_by ? DB::table('users')->where('id', $row->decided_by)->value('name') : null, 'decided_at' => $row->decided_at,
                'requested_by' => DB::table('users')->where('id', $row->requested_by)->value('name'), 'budget_impact' => $row->budget_impact, 'stock_impact' => $row->stock_impact,
                'proposed' => collect(json_decode($row->proposed_items, true))->map(fn ($q, $id) => ['label' => $items[(int) $id] ?? $id, 'quantity' => $q])->values(),
                'unit' => $unit ? $unit->full_name.' ('.$unit->file_no.')' : 'أسرة '.DB::table('orphan_households')->where('id', $row->household_id)->value('reference_no'),
                'expires_on' => $row->expires_on, 'campaign' => ['public_id' => $row->campaign_public_id, 'reference_no' => $row->campaign_reference, 'title' => $row->campaign_title]],
            'progress' => $instance ? $progress->view($instance, $visibility->accessLevel($user, (int) $instance->id) ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision, 'status' => $instance->status,
                'actions' => $engine->availableActions($instance, $user)] : null,
        ]);
    }

    public function devices(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->access->orphans()->isHead($user) || $user->isSystemAdmin(), 403);

        return Inertia::render('Relief/Devices', [
            'devices' => DB::table('field_devices as d')->join('users as u', 'u.id', '=', 'd.user_id')->orderByRaw("d.status = 'pending' desc")->orderByDesc('d.id')
                ->get(['d.id', 'd.public_id', 'd.name', 'd.platform', 'd.app_version', 'd.status', 'd.key_fingerprint', 'd.last_seen_at', 'd.last_sync_at', 'd.approved_at', 'd.revoke_reason', 'u.name as user'])
                ->map(fn ($d) => (array) $d + ['status_label' => FieldDevices::STATUS_LABELS[$d->status] ?? $d->status, 'fingerprint' => substr($d->key_fingerprint, 0, 16)]),
            'serverKey' => app(FieldKeys::class)->keyId(),
        ]);
    }

    public function deviceAction(Request $request, int $device, string $action, FieldDevices $devices): RedirectResponse
    {
        match ($action) {
            'approve' => $devices->approve($request->user(), $device),
            'revoke' => $devices->revoke($request->user(), $device, false, (string) $request->input('reason')),
            'lost' => $devices->revoke($request->user(), $device, true, (string) $request->input('reason')),
            default => abort(404),
        };

        return back()->with('success', 'سُجل الإجراء على الجهاز.');
    }

    private function find(string $publicId): object
    {
        $campaign = DB::table('relief_campaigns')->where('public_id', $publicId)->first();
        abort_unless($campaign, 404);

        return $campaign;
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $type = fn (string $code) => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', $code)
            ->where('v.is_active', true)->whereNull('v.parent_id')->orderBy('v.sort_order')->get(['v.id', 'v.code', 'v.name_ar as name']);

        return ['types' => $type('relief_campaign_types'), 'delivery_methods' => $type('relief_delivery_methods'), 'governorates' => $type('governorates'),
            'classes' => $type('orphan_classifications'), 'funds' => DB::table('finance_restricted_funds')->where('is_active', true)->get(['id', 'name']),
            'stock_items' => DB::table('finance_stock_items')->where('is_active', true)->get(['id', 'name', 'unit']),
            'managers' => DB::table('users')->where('is_active', true)->orderBy('name')->get(['id', 'name'])->filter(fn ($u) => $this->access->canPrepare(User::find($u->id)))->values()];
    }
}
