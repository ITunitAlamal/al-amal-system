<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\Orphans\Campaigns\Campaigns;
use App\Services\Orphans\Campaigns\NominationEngine;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\RegistryQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «الحملات الإغاثية»: four-step wizard, explained lists, executive-only approval, change requests, Stores and delivery. */
final class CampaignController extends Controller
{
    public function __construct(private readonly OrphanAccess $access, private readonly Campaigns $campaigns, private readonly RegistryQueries $queries) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->canOpenCampaigns($request->user()), 403);
        $user = $request->user();
        $memberOnly = ! $this->access->canView($user);
        $rows = DB::table('campaigns as c')->leftJoin('master_data_values as t', 't.id', '=', 'c.aid_type_value_id')
            ->when($memberOnly, fn ($q) => $q->whereIn('c.id', DB::table('campaign_members')->where('user_id', $user->id)->whereNull('revoked_at')->pluck('campaign_id')))
            ->when($request->query('status'), fn ($q, $s) => $q->where('c.status', $s))
            ->orderByRaw("CASE c.status WHEN 'pending_executive' THEN 0 WHEN 'distributing' THEN 1 WHEN 'approved' THEN 2 ELSE 3 END")->orderByDesc('c.id')
            ->select(['c.public_id', 'c.reference_no', 'c.title', 'c.status', 'c.beneficiary_unit', 'c.starts_on', 'c.ends_on', 'c.donor_name', 't.name_ar as aid_type'])
            ->selectSub(DB::table('campaign_lists')->whereColumn('campaign_id', 'c.id')->where('status', 'approved')->select('entry_count')->limit(1), 'approved_entries')
            ->selectSub(DB::table('campaign_change_requests')->whereColumn('campaign_id', 'c.id')->where('status', 'pending')->selectRaw('count(*)'), 'pending_changes')
            ->paginate(25)->withQueryString();

        return Inertia::render('Orphans/Campaigns/Index', ['rows' => $rows, 'statuses' => Campaigns::STATUS, 'filters' => ['status' => $request->query('status')],
            'abilities' => $this->access->abilities($user), 'importUrl' => \Illuminate\Support\Facades\Route::has('orphans.import.index') ? route('orphans.import.index', ['purpose' => 'campaign_candidates'], false) : null]);
    }

    public function wizard(Request $request, ?string $publicId = null): Response
    {
        $this->access->assert($request->user(), 'campaigns');
        $campaign = $publicId ? $this->find($publicId) : null;
        $run = $campaign ? DB::table('campaign_runs')->where('campaign_id', $campaign->id)->orderByDesc('id')->first() : null;

        return Inertia::render('Orphans/Campaigns/Wizard', [
            'campaign' => $campaign ? [...(array) $campaign, 'targeting' => json_decode((string) $campaign->targeting, true) ?: []] : null,
            'step' => max(1, min(4, (int) $request->query('step', $campaign->wizard_step ?? 1))),
            'items' => $campaign ? DB::table('campaign_items')->where('campaign_id', $campaign->id)->orderBy('id')->get()->map(fn ($i) => [...(array) $i, 'criteria' => json_decode((string) $i->criteria, true) ?: []]) : [],
            'quotas' => $campaign ? DB::table('campaign_quotas')->where('campaign_id', $campaign->id)->get() : [],
            'rules' => $campaign ? $this->rulesPayload(DB::table('campaign_rule_versions')->where('campaign_id', $campaign->id)->orderByDesc('version')->first()) : null,
            'run' => $run ? ['public_id' => $run->public_id, 'created_at' => $run->created_at, 'report' => $this->campaigns->report($campaign, $run)] : null,
            'options' => ['aid_types' => $this->queries->values('relief_campaign_types'), 'governorates' => app(\App\Services\Configuration\GazaGeography::class)->children(null),
                'classes' => $this->queries->values('orphan_classifications'), 'income_bands' => $this->queries->values('income_bands'), 'needs' => $this->queries->values('needs_levels'),
                'warehouses' => DB::table('finance_warehouses')->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'stock_items' => DB::table('finance_stock_items')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'sku']),
                'funds' => DB::table('finance_restricted_funds')->where('is_active', true)->orderBy('name')->get(['id', 'name']), 'sponsors' => DB::table('sponsors')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
                'staff' => $this->access->staff(), 'criteria' => collect(NominationEngine::CRITERIA)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'type' => $c[1]])->values()],
        ]);
    }

    public function saveBasics(Request $request, ?string $publicId = null): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:300', 'description' => 'nullable|string|max:3000', 'donor_name' => 'nullable|string|max:300', 'sponsor_id' => 'nullable|integer|exists:sponsors,id',
            'aid_type_value_id' => 'nullable|integer|exists:master_data_values,id', 'beneficiary_unit' => ['required', Rule::in(['orphan', 'household', 'guardian'])], 'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on', 'warehouse_id' => 'nullable|integer|exists:finance_warehouses,id', 'restricted_fund_id' => 'nullable|integer|exists:finance_restricted_funds,id',
            'manager_user_id' => 'nullable|integer|exists:users,id', 'items' => 'array|max:30', 'items.*.id' => 'nullable|integer', 'items.*.label' => 'required|string|max:200',
            'items.*.variant' => 'nullable|string|max:60', 'items.*.stock_item_id' => 'nullable|integer|exists:finance_stock_items,id', 'items.*.per_unit_quantity' => 'required|numeric|min:0.001',
            'items.*.total_quantity' => 'required|numeric|min:0', 'items.*.criteria' => 'nullable|array', 'items.*.criteria.age_min' => 'nullable|integer|min:0|max:30',
            'items.*.criteria.age_max' => 'nullable|integer|min:0|max:30', 'items.*.criteria.sex' => ['nullable', Rule::in(['male', 'female'])]], [], ['title' => 'عنوان الحملة']);
        $campaign = $this->campaigns->saveBasics($publicId ? $this->find($publicId) : null, $data, $data['items'] ?? [], $request->user());

        return redirect('/orphans/campaigns/'.$campaign->public_id.'/wizard?step=2')->with('success', 'تم حفظ الأساسيات.');
    }

    public function saveTargeting(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['targeting' => 'array', 'targeting.governorates' => 'array', 'targeting.governorates.*' => 'integer', 'targeting.localities' => 'array', 'targeting.localities.*' => 'integer',
            'targeting.age_min' => 'nullable|integer|min:0|max:30', 'targeting.age_max' => 'nullable|integer|min:0|max:30', 'targeting.sex' => ['nullable', Rule::in(['male', 'female'])],
            'quotas' => 'array', 'quotas.*.governorate_value_id' => 'nullable|integer|exists:master_data_values,id', 'quotas.*.locality_value_id' => 'nullable|integer|exists:master_data_values,id', 'quotas.*.quantity' => 'nullable|integer|min:0']);
        $this->campaigns->saveTargeting($this->find($publicId), array_filter($data['targeting'] ?? [], fn ($v) => $v !== null && $v !== '' && $v !== []), $data['quotas'] ?? [], $request->user());

        return redirect('/orphans/campaigns/'.$publicId.'/wizard?step=3')->with('success', 'تم حفظ الاستهداف.');
    }

    public function saveRules(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['general', 'scored', 'hybrid'])], 'missing_policy' => ['required', Rule::in(['review', 'neutral'])],
            'tie_break' => ['nullable', Rule::in(['oldest_benefit', 'youngest', 'earliest_registration'])], 'reserve_count' => 'nullable|integer|min:0|max:100000', 'notes' => 'nullable|string|max:2000',
            'gates' => 'array', 'gates.classes' => 'array', 'gates.classes.*' => 'integer', 'gates.require_approved_guardian' => 'boolean', 'gates.active_files_only' => 'boolean',
            'gates.displaced_only' => 'boolean', 'gates.exclude_sponsored' => 'boolean', 'gates.same_type_window_days' => 'nullable|integer|min:0|max:3650',
            'criteria' => 'array', 'criteria.*.key' => ['required', Rule::in(array_keys(NominationEngine::CRITERIA))], 'criteria.*.weight' => 'required|numeric|min:0|max:100',
            'criteria.*.map' => 'nullable|array', 'criteria.*.cap' => 'nullable|integer|min:1|max:50', 'criteria.*.max_age' => 'nullable|integer|min:1|max:30', 'criteria.*.days' => 'nullable|integer|min:1|max:3650']);
        $this->campaigns->saveRules($this->find($publicId), $data, $request->user());

        return redirect('/orphans/campaigns/'.$publicId.'/wizard?step=4')->with('success', 'حُفظت المعايير كإصدار ثابت.');
    }

    public function simulate(Request $request, string $publicId): RedirectResponse
    {
        $this->campaigns->simulate($this->find($publicId), $request->user());

        return redirect('/orphans/campaigns/'.$publicId.'/wizard?step=4')->with('success', 'اكتملت المحاكاة. لم يُعتمد شيء ولم يُحجز أي مخزون.');
    }

    public function draft(Request $request, string $publicId): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $run = DB::table('campaign_runs')->where('campaign_id', $campaign->id)->where('public_id', $request->input('run'))->firstOrFail();
        $this->campaigns->draftFromRun($campaign, $run, $request->user());

        return redirect('/orphans/campaigns/'.$publicId.'?tab=list')->with('success', 'أُنشئت مسودة الكشف؛ راجعها ثم ارفعها لاعتماد المدير التنفيذي.');
    }

    public function show(Request $request, string $publicId): Response
    {
        $campaign = $this->find($publicId);
        $user = $request->user();
        abort_unless($this->campaigns->canView($user, $campaign), 403);
        $fullAccess = $this->access->canView($user);
        $lists = DB::table('campaign_lists as l')->leftJoin('users as p', 'p.id', '=', 'l.prepared_by')->leftJoin('users as r', 'r.id', '=', 'l.recommended_by')->leftJoin('users as d', 'd.id', '=', 'l.decided_by')
            ->where('l.campaign_id', $campaign->id)->orderByDesc('l.version')->get(['l.*', 'p.name as prepared_name', 'r.name as recommended_name', 'd.name as decided_name']);
        $listId = (int) ($request->query('list') ?: ($lists->firstWhere('status', 'draft')->id ?? $lists->firstWhere('status', 'pending_executive')->id ?? $lists->firstWhere('status', 'approved')->id ?? 0));
        $current = $lists->firstWhere('id', $listId);
        $view = in_array($request->query('view'), ['reserve', 'review', 'primary'], true) ? $request->query('view') : 'primary';
        $run = $current?->run_id ? DB::table('campaign_runs')->find($current->run_id) : DB::table('campaign_runs')->where('campaign_id', $campaign->id)->orderByDesc('id')->first();
        $entries = null;
        if ($view === 'review' && $run) {
            $entries = DB::table('campaign_candidates as c')->where('c.run_id', $run->id)->whereIn('c.outcome', ['review', 'ineligible', 'insufficient_priority'])
                ->leftJoin('master_data_values as g', 'g.id', '=', 'c.governorate_value_id')->orderByRaw("CASE c.outcome WHEN 'review' THEN 0 WHEN 'insufficient_priority' THEN 1 ELSE 2 END")->orderBy('c.rank')
                ->select(['c.id as candidate_id', 'c.unit_key', 'c.orphan_id', 'c.household_id', 'c.guardian_id', 'c.outcome', 'c.score', 'c.reasons', 'c.duplicate_flags', 'g.name_ar as area'])
                ->paginate(30)->withQueryString()->through(fn ($r) => [...(array) $r, 'beneficiary' => $this->campaigns->entryLabel($r), 'reasons' => json_decode((string) $r->reasons, true) ?: [],
                    'outcome_label' => NominationEngine::OUTCOMES[$r->outcome], 'duplicates' => json_decode((string) $r->duplicate_flags, true) ?: []]);
        } elseif ($current) {
            $entries = DB::table('campaign_list_entries as e')->where('e.list_id', $current->id)->where('e.kind', $view)->leftJoin('master_data_values as g', 'g.id', '=', 'e.governorate_value_id')
                ->leftJoin('campaign_items as i', 'i.id', '=', 'e.item_id')->leftJoin('orphan_guardians as rg', 'rg.id', '=', 'e.recipient_guardian_id')->leftJoin('persons as rp', 'rp.id', '=', 'rg.person_id')
                ->leftJoin('campaign_candidates as c', 'c.id', '=', 'e.candidate_id')->orderBy('e.position')
                ->select(['e.id', 'e.unit_key', 'e.orphan_id', 'e.household_id', 'e.guardian_id', 'e.kind', 'e.quantity', 'e.score', 'e.reason', 'e.candidate_id', 'g.name_ar as area',
                    DB::raw("i.label || coalesce(' — ' || i.variant, '') as item"), 'rp.full_name as recipient', 'c.duplicate_flags', 'c.snapshot'])
                ->paginate(30)->withQueryString()->through(function ($r) use ($campaign): array {
                    $snapshot = json_decode((string) $r->snapshot, true) ?: [];

                    return [...collect((array) $r)->except('snapshot')->all(), 'beneficiary' => $this->campaigns->entryLabel($r), 'last_benefit' => $snapshot['last_benefit'] ?? null,
                        'verified' => $snapshot['approved_guardian'] ?? null, 'duplicates' => json_decode((string) $r->duplicate_flags, true) ?: [], 'state' => $this->campaigns->entryState($campaign->id, $r->unit_key)];
                });
        }
        $pending = $lists->firstWhere('status', 'pending_executive');
        $approval = null;
        if ($pending) {
            $request2 = DB::table('campaign_change_requests')->where('proposed_list_id', $pending->id)->first();
            $approval = ['list' => (array) $pending, 'change' => $request2 ? [...(array) $request2, 'diff' => json_decode((string) $request2->diff, true)] : null,
                'items' => DB::table('campaign_items')->where('campaign_id', $campaign->id)->get()->map(fn ($i) => ['label' => $i->label.($i->variant ? ' — '.$i->variant : ''), 'planned' => (float) $i->total_quantity,
                    'listed' => (float) DB::table('campaign_list_entries')->where('list_id', $pending->id)->where('kind', 'primary')->where('item_id', $i->id)->sum('quantity'), 'available' => $this->campaigns->available($campaign, $i)]),
                'regions' => DB::table('campaign_list_entries as e')->leftJoin('master_data_values as g', 'g.id', '=', 'e.governorate_value_id')->where('e.list_id', $pending->id)->where('e.kind', 'primary')
                    ->selectRaw("coalesce(g.name_ar, 'غير محدد') as label, count(*) as n")->groupBy('g.name_ar')->get(),
                'quotas' => DB::table('campaign_quotas as q')->join('master_data_values as g', 'g.id', '=', 'q.governorate_value_id')->where('q.campaign_id', $campaign->id)->get(['g.name_ar as label', 'q.quantity']),
                'duplicates' => DB::table('campaign_duplicate_alerts')->where('campaign_id', $campaign->id)->where('status', 'open')->count(),
                'rules' => $this->rulesPayload($pending->run_id ? DB::table('campaign_rule_versions')->where('id', DB::table('campaign_runs')->where('id', $pending->run_id)->value('rule_version_id'))->first() : null)];
        }

        return Inertia::render('Orphans/Campaigns/Show', [
            'campaign' => [...(array) $campaign, 'targeting' => json_decode((string) $campaign->targeting, true) ?: [], 'status_label' => Campaigns::STATUS[$campaign->status],
                'aid_type' => $campaign->aid_type_value_id ? DB::table('master_data_values')->where('id', $campaign->aid_type_value_id)->value('name_ar') : null],
            'lists' => $lists->map(fn ($l) => [...(array) $l, 'status_label' => Campaigns::LIST_STATUS[$l->status]]), 'current' => $current ? (array) $current : null, 'view' => $view, 'entries' => $entries,
            'approval' => $approval, 'tab' => $request->query('tab', $pending && $this->access->isExecutive($user) ? 'approval' : 'list'),
            'changes' => DB::table('campaign_change_requests as r')->join('campaign_lists as l', 'l.id', '=', 'r.proposed_list_id')->leftJoin('users as u', 'u.id', '=', 'r.requested_by')
                ->where('r.campaign_id', $campaign->id)->orderByDesc('r.id')->get(['r.*', 'l.version', 'l.status as list_status', 'u.name as requested_name'])
                ->map(fn ($r) => [...(array) $r, 'diff' => json_decode((string) $r->diff, true)]),
            'items' => DB::table('campaign_items')->where('campaign_id', $campaign->id)->get()->map(fn ($i) => [...(array) $i,
                'issued' => (float) DB::table('campaign_stock_issues')->where('item_id', $i->id)->selectRaw("coalesce(sum(case when kind = 'issue' then quantity else -quantity end), 0) as q")->value('q'),
                'available' => $this->campaigns->available($campaign, $i)]),
            'delivery' => $this->deliveryStats($campaign),
            'reconciliations' => DB::table('campaign_reconciliations')->where('campaign_id', $campaign->id)->orderByDesc('id')->get()->map(fn ($r) => [...(array) $r, 'summary' => json_decode($r->summary, true)]),
            'members' => $fullAccess ? DB::table('campaign_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.campaign_id', $campaign->id)->whereNull('m.revoked_at')->get(['m.id', 'm.role', 'm.ends_on', 'u.name']) : [],
            'duplicates' => DB::table('campaign_duplicate_alerts')->where('campaign_id', $campaign->id)->orderByDesc('id')->limit(100)->get(),
            'failureReasons' => $this->queries->values('relief_failure_reasons'), 'users' => $fullAccess ? DB::table('users')->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'abilities' => [...$this->access->abilities($user), 'prepare' => $this->campaigns->canPrepare($user, $campaign), 'deliver' => $this->campaigns->canDeliver($user, $campaign),
                'stock' => app(\App\Services\Finance\FinanceGuard::class)->allows($user, 'finance.stock.issue'), 'files' => $fullAccess],
        ]);
    }

    /** Right-hand explanation panel: score contributions, snapshot and benefit history of one candidate. */
    public function candidate(Request $request, string $publicId, int $candidate): JsonResponse
    {
        $campaign = $this->find($publicId);
        abort_unless($this->campaigns->canView($request->user(), $campaign), 403);
        $c = DB::table('campaign_candidates as c')->join('campaign_runs as r', 'r.id', '=', 'c.run_id')->where('r.campaign_id', $campaign->id)->where('c.id', $candidate)->firstOrFail(['c.*', 'r.input_hash', 'r.rule_version_id']);
        $unit = array_filter(['orphan_id' => $c->orphan_id, 'household_id' => $c->household_id]);

        return response()->json(['beneficiary' => $this->campaigns->entryLabel($c), 'outcome' => NominationEngine::OUTCOMES[$c->outcome], 'score' => $c->score, 'rank' => $c->rank,
            'contributions' => json_decode((string) $c->contributions, true), 'reasons' => json_decode((string) $c->reasons, true), 'missing' => json_decode((string) $c->missing, true),
            'snapshot' => json_decode($c->snapshot, true), 'duplicates' => json_decode((string) $c->duplicate_flags, true) ?: [],
            'rule_version' => DB::table('campaign_rule_versions')->where('id', $c->rule_version_id)->value('version'), 'input_hash' => $c->input_hash,
            'benefits' => $unit ? \App\Services\Orphans\Aid::benefits($unit, 10) : []]);
    }

    public function editList(Request $request, string $publicId, string $list): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $row = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('public_id', $list)->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['remove', 'move', 'quantity', 'add'])], 'lock_version' => 'required|integer', 'reason' => 'required|string|max:500',
            'entry_id' => 'nullable|integer', 'candidate_id' => 'nullable|integer', 'kind' => ['nullable', Rule::in(['primary', 'reserve'])], 'quantity' => 'nullable|numeric|min:0.001', 'item_id' => 'nullable|integer'],
            [], ['reason' => 'سبب التعديل']);
        $this->campaigns->editDraft($row, (int) $data['lock_version'], $data['action'], $data, $request->user());

        return back()->with('success', 'تم تعديل المسودة.');
    }

    public function listAction(Request $request, string $publicId, string $list): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $row = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('public_id', $list)->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['submit', 'withdraw', 'approve', 'reject'])], 'note' => 'nullable|string|max:2000', 'content_hash' => 'nullable|string|size:64']);
        match ($data['action']) {
            'submit' => $this->campaigns->submit($row, $data['note'] ?? null, $request->user()),
            'withdraw' => $this->campaigns->withdraw($row, $request->user()),
            default => $this->campaigns->decide($row, $data['action'], $data['note'] ?? null, (string) ($data['content_hash'] ?? ''), $request->user()),
        };
        $messages = ['submit' => 'رُفع الكشف للمدير التنفيذي ولم يعد قابلًا للتعديل.', 'withdraw' => 'أُعيد الكشف إلى مسودة.', 'approve' => 'اعتُمد الإصدار وأصبح مقفلًا.', 'reject' => 'رُفض الإصدار مع حفظ السبب.'];

        return back()->with('success', $messages[$data['action']]);
    }

    public function requestChange(Request $request, string $publicId): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $data = $request->validate(['reason' => 'required|string|max:2000', 'appeal_public_id' => 'nullable|string|exists:orphan_appeals,public_id'], [], ['reason' => 'مبرر التعديل']);
        $list = $this->campaigns->requestChange($campaign, $data['reason'], ! empty($data['appeal_public_id']) ? DB::table('orphan_appeals')->where('public_id', $data['appeal_public_id'])->value('id') : null, $request->user());

        return redirect('/orphans/campaigns/'.$publicId.'?tab=list&list='.$list->id)->with('success', 'أُنشئت مسودة طلب التعديل؛ الكشف المعتمد يبقى كما هو حتى يعتمد المدير التنفيذي التعديل.');
    }

    public function member(Request $request, string $publicId): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'role' => ['required', Rule::in(['viewer', 'staff', 'distributor'])], 'ends_on' => 'nullable|date|after_or_equal:today']);
        $this->campaigns->grantMember($campaign, (int) $data['user_id'], $data['role'], $data['ends_on'] ?? null, $request->user());

        return back()->with('success', 'تم التكليف بالحملة (وصول لهذه الحملة فقط).');
    }

    public function revokeMember(Request $request, string $publicId, int $member): RedirectResponse
    {
        abort_unless($this->access->canAssign($request->user()) || $this->access->isExecutive($request->user()), 403);
        DB::table('campaign_members')->where('id', $member)->where('campaign_id', $this->find($publicId)->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'updated_at' => now()]);

        return back()->with('success', 'سُحب التكليف.');
    }

    public function stock(Request $request, string $publicId): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $data = $request->validate(['item_id' => 'required|integer', 'kind' => ['required', Rule::in(['issue', 'return'])], 'quantity' => 'required|numeric|min:0.001']);
        $item = DB::table('campaign_items')->where('campaign_id', $campaign->id)->where('id', $data['item_id'])->firstOrFail();
        $this->campaigns->stock($campaign, $item, $data['kind'], (string) $data['quantity'], $request->user());

        return back()->with('success', $data['kind'] === 'issue' ? 'سُجّل الصرف في المخازن.' : 'سُجّل الإرجاع في المخازن.');
    }

    public function deliver(Request $request, string $publicId): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $data = $request->validate(['entry_id' => 'required|integer', 'idempotency_key' => 'required|uuid', 'kind' => ['required', Rule::in(['delivered', 'undelivered', 'returned'])],
            'recipient_name' => 'nullable|string|max:300', 'recipient_relation' => ['nullable', Rule::in(['guardian', 'beneficiary', 'proxy'])], 'proxy_note' => 'nullable|string|max:1000',
            'identity_checked' => 'boolean', 'failure_reason_value_id' => 'nullable|integer|exists:master_data_values,id', 'note' => 'nullable|string|max:1000']);
        $result = $this->campaigns->deliver($campaign, (int) $data['entry_id'], $data, $request->user());

        return back()->with('success', $result['status'] === 'duplicate' ? 'سُجّل هذا التسليم مسبقًا؛ لم يتكرر.' : 'سُجّل التسليم.');
    }

    public function correct(Request $request, string $publicId, string $delivery): RedirectResponse
    {
        $row = DB::table('campaign_deliveries')->where('campaign_id', $this->find($publicId)->id)->where('public_id', $delivery)->firstOrFail();
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->campaigns->correct($row, $data['reason'], $request->user());

        return back()->with('success', 'سُجّل حدث التصحيح؛ السجل الأصلي محفوظ.');
    }

    public function reconcile(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:2000']);
        $this->campaigns->reconcile($this->find($publicId), $data['note'] ?? null, $request->user());

        return back()->with('success', 'سُجّلت التسوية.');
    }

    public function close(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:2000']);
        $this->campaigns->close($this->find($publicId), $data['note'] ?? null, $request->user());

        return back()->with('success', 'أُغلقت الحملة.');
    }

    /**
     * Publishes the APPROVED list to the beneficiary portal («جاهزة للاستلام» for its primary entries) or withdraws
     * it. Publication only shows what the executive director already approved; it never approves or edits a list.
     */
    public function portal(Request $request, string $publicId): RedirectResponse
    {
        $campaign = $this->find($publicId);
        abort_unless($this->campaigns->canPrepare($request->user(), $campaign), 403);
        $data = $request->validate(['action' => ['required', Rule::in(['publish', 'withdraw'])], 'instructions' => 'nullable|string|max:1000'], [], ['instructions' => 'تعليمات الاستلام']);
        if ($data['action'] === 'publish') {
            abort_unless(in_array($campaign->status, ['approved', 'distributing'], true) && DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->exists(), 422,
                'لا يُنشر في البوابة إلا كشف اعتمده المدير التنفيذي لحملة في مرحلة التوزيع.');
            abort_if(blank($data['instructions'] ?? null), 422, 'اكتب تعليمات الاستلام التي يراها المستفيد (المكان والموعد وما يُحضره).');
        }
        DB::transaction(function () use ($campaign, $data, $request): void {
            DB::table('campaigns')->where('id', $campaign->id)->update($data['action'] === 'publish'
                ? ['portal_published_at' => now(), 'portal_published_by' => $request->user()->id, 'portal_instructions' => $data['instructions'], 'updated_at' => now()]
                : ['portal_published_at' => null, 'updated_at' => now()]);
            app(\App\Services\AuditService::class)->recordRow('orphans.campaign.portal_'.$data['action'], 'campaigns', $campaign->id, null, ['reference' => $campaign->reference_no], $request->user()->id, $request->ip());
            if ($data['action'] === 'publish') {
                $this->notifyPublished($campaign);
            }
        });

        return back()->with('success', $data['action'] === 'publish' ? 'نُشرت تعليمات الاستلام في بوابة المستفيدين لمن في الكشف المعتمد.' : 'سُحب النشر من البوابة.');
    }

    /** One notice per beneficiary account with a primary entry in the approved list (no names in the notice). */
    private function notifyPublished(object $campaign): void
    {
        $list = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->orderByDesc('version')->value('id');
        $notifier = app(\App\Services\Portal\PortalNotifier::class);
        $accounts = [];
        foreach (DB::table('campaign_list_entries')->where('list_id', $list)->where('kind', 'primary')->get(['orphan_id', 'household_id', 'guardian_id', 'recipient_guardian_id']) as $entry) {
            if ($entry->orphan_id) {
                array_push($accounts, ...$notifier->accountsForOrphan((int) $entry->orphan_id));
            }
            if ($entry->household_id) {
                foreach (DB::table('household_members as m')->join('orphans as o', 'o.person_id', '=', 'm.person_id')->where('m.household_id', $entry->household_id)->whereNull('m.ends_on')->pluck('o.id') as $orphan) {
                    array_push($accounts, ...$notifier->accountsForOrphan((int) $orphan));
                }
            }
            foreach (array_filter([$entry->guardian_id, $entry->recipient_guardian_id]) as $guardian) {
                if ($account = DB::table('orphan_guardians as g')->join('guardians as a', 'a.id', '=', 'g.portal_account_id')->where('g.id', $guardian)->where('a.account_status', 'approved')->value('a.id')) {
                    $accounts[] = (int) $account;
                }
            }
        }
        foreach (array_unique($accounts) as $account) {
            $notifier->notify($account, 'aid', 'مساعدة جاهزة للاستلام: '.$campaign->title, '/guardian/aid', null, 'campaign-ready-'.$campaign->id);
        }
    }

    public function duplicate(Request $request, string $publicId, int $alert): RedirectResponse
    {
        $campaign = $this->find($publicId);
        abort_unless($this->campaigns->canPrepare($request->user(), $campaign), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['exception_granted', 'resolved'])], 'note' => 'required|string|max:1000'], [], ['note' => 'المبرر']);
        DB::table('campaign_duplicate_alerts')->where('id', $alert)->where('campaign_id', $campaign->id)->where('status', 'open')->update(['status' => $data['status'], 'decision_note' => $data['note'],
            'decided_by' => $request->user()->id, 'decided_at' => now()]);

        return back()->with('success', 'سُجّل القرار على تنبيه التكرار.');
    }

    public function batch(Request $request, string $publicId): RedirectResponse
    {
        $campaign = $this->find($publicId);
        $data = $request->validate(['distributor_user_id' => 'required|integer|exists:users,id', 'device_id' => 'required|integer|exists:field_devices,id', 'governorate_value_id' => 'nullable|integer|exists:master_data_values,id']);
        $batch = app(\App\Services\Orphans\Field\FieldBatches::class)->create($campaign, (int) $data['distributor_user_id'], (int) $data['device_id'], [], $data['governorate_value_id'] ?? null, $request->user());

        return back()->with('success', 'أُنشئت الدفعة '.$batch->reference_no.'؛ يُنزّلها الموزع على جهازه المعتمد.');
    }

    public function revokeBatch(Request $request, string $publicId, string $batch): RedirectResponse
    {
        $row = DB::table('campaign_batches')->where('campaign_id', $this->find($publicId)->id)->where('public_id', $batch)->firstOrFail();
        app(\App\Services\Orphans\Field\FieldBatches::class)->revoke($row, $request->user());

        return back()->with('success', 'أُلغيت الدفعة؛ الأحداث المستلمة منها محفوظة للمراجعة.');
    }

    public function resolveEvent(Request $request, string $publicId, int $event): RedirectResponse
    {
        $campaign = $this->find($publicId);
        abort_unless(DB::table('field_events as f')->join('campaign_batches as b', 'b.id', '=', 'f.batch_id')->where('f.id', $event)->where('b.campaign_id', $campaign->id)->exists(), 404);
        $data = $request->validate(['decision' => ['required', Rule::in(['confirm', 'reject'])], 'note' => 'required|string|min:5|max:1000']);
        app(\App\Services\Orphans\Field\FieldSync::class)->resolve($request->user(), $event, $data['decision'] === 'confirm', $data['note'], $this->access);

        return back()->with('success', 'سُجّل القرار على حدث الميدان.');
    }

    private function deliveryStats(object $campaign): array
    {
        $approved = DB::table('campaign_lists')->where('campaign_id', $campaign->id)->where('status', 'approved')->first();
        if (! $approved) {
            return ['available' => false];
        }
        $latest = DB::table('campaign_deliveries as d')->where('d.campaign_id', $campaign->id)->where('d.kind', '<>', 'correction')
            ->whereNotExists(fn ($q) => $q->from('campaign_deliveries as c')->whereColumn('c.corrects_id', 'd.id'))
            ->selectRaw('distinct on (d.unit_key) d.unit_key, d.kind')->orderBy('d.unit_key')->orderByDesc('d.id');
        $states = DB::query()->fromSub($latest, 's')->selectRaw('kind, count(*) as n')->groupBy('kind')->pluck('n', 'kind')->all();
        $total = DB::table('campaign_list_entries')->where('list_id', $approved->id)->where('kind', 'primary')->count();
        $done = array_sum($states);

        $field = ['batches' => DB::table('campaign_batches as b')->join('users as u', 'u.id', '=', 'b.distributor_user_id')->join('field_devices as d', 'd.id', '=', 'b.device_id')->where('b.campaign_id', $campaign->id)
            ->orderByDesc('b.id')->get(['b.public_id', 'b.reference_no', 'b.status', 'b.manifest_version', 'b.offline_expires_at', 'u.name as distributor', 'd.name as device',
                DB::raw('(select count(*) from campaign_batch_entries e where e.batch_id = b.id and e.is_active) as entries')]),
            'held' => DB::table('field_events as f')->join('campaign_batches as b', 'b.id', '=', 'f.batch_id')->join('users as u', 'u.id', '=', 'f.user_id')->where('b.campaign_id', $campaign->id)
                ->whereIn('f.status', ['conflict_held', 'rejected'])->orderByDesc('f.id')->limit(50)->get(['f.id', 'f.kind', 'f.status', 'f.status_reason', 'f.occurred_at', 'b.reference_no', 'u.name as distributor']),
            'distributors' => DB::table('campaign_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.campaign_id', $campaign->id)->where('m.role', 'distributor')->whereNull('m.revoked_at')
                ->get(['u.id', 'u.name'])->map(fn ($u) => [...(array) $u, 'devices' => DB::table('field_devices')->where('user_id', $u->id)->where('status', 'approved')->get(['id', 'name'])])];

        return [...$field, 'available' => true, 'version' => $approved->version, 'total' => $total, 'prepared' => max(0, $total - $done), 'delivered' => (int) ($states['delivered'] ?? 0),
            'undelivered' => (int) ($states['undelivered'] ?? 0), 'returned' => (int) ($states['returned'] ?? 0),
            'recent' => DB::table('campaign_deliveries as d')->leftJoin('users as u', 'u.id', '=', 'd.recorded_by')->where('d.campaign_id', $campaign->id)->orderByDesc('d.id')->limit(30)
                ->get(['d.public_id', 'd.kind', 'd.unit_key', 'd.recipient_name', 'd.recipient_relation', 'd.occurred_at', 'd.note', 'd.corrects_id', 'u.name as recorded_by_name'])];
    }

    private function rulesPayload(?object $rules): ?array
    {
        return $rules ? [...(array) $rules, 'gates' => json_decode($rules->gates, true), 'criteria' => json_decode($rules->criteria, true)] : null;
    }

    private function find(string $publicId): object
    {
        return DB::table('campaigns')->where('public_id', $publicId)->firstOrFail();
    }
}
