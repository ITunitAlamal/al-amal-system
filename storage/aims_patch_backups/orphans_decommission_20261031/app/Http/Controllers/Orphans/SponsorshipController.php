<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\OrphanChild;
use App\Models\OrphanHousehold;
use App\Models\User;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Finance\FinanceGuard;
use App\Services\Orphans\HouseholdNeeds;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanAssistance;
use App\Services\Orphans\OrphanContributions;
use App\Services\Orphans\OrphanForms;
use App\Services\Orphans\SponsorAgreements;
use App\Services\Orphans\Sponsors;
use App\Services\Orphans\SponsorshipAccounts;
use App\Services\Orphans\SponsorshipFunds;
use App\Services\Orphans\Sponsorships;
use App\Services\Orphans\SponsorshipWaitlist;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowProgress;
use App\Services\WorkflowVisibilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sponsors, sponsorships, one-off donations and assistance requests of the Sponsorships & Orphans department.
 * Money columns are read-only here: Accounts record collections and payouts on their own page.
 */
final class SponsorshipController extends Controller
{
    public function __construct(
        private readonly OrphanAccess $access,
        private readonly Sponsors $sponsors,
        private readonly Sponsorships $sponsorships,
    ) {}

    public function index(Request $request, OrphanForms $forms, OrphanContributions $contributions): Response
    {
        $user = $request->user();
        $manage = $this->access->allows($user, 'sponsorships');
        abort_unless($manage || $this->access->isExecutive($user), 403, 'تحتاج إلى تفويض «الكفلاء والكفالات والتبرعات» في قسم الكفالات.');
        $filters = $request->validate(['status' => ['nullable', 'in:draft,pending_approval,active,paused,ended,rejected,cancelled,superseded'], 'q' => ['nullable', 'string', 'max:40'],
            'tab' => ['nullable', 'in:sponsorships,sponsors,contributions,assistance,waitlist,agreements,funds,gap']]);
        $identity = $this->sponsors->seesIdentity($user);
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $term = trim((string) ($filters['q'] ?? ''));
        $rows = $this->listQuery()->when($filters['status'] ?? null, fn ($q, $s) => $q->where('s.status', $s), fn ($q) => $q->whereNotIn('s.status', ['superseded', 'cancelled']))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('s.sponsorship_no', 'ilike', "%{$term}%")->orWhere('c.file_no', 'ilike', "%{$term}%")
                ->orWhere('h.reference_no', 'ilike', "%{$term}%")->orWhere('d.donor_no', 'ilike', "%{$term}%")))
            ->orderByDesc('s.id')->limit(300)->get();
        $activeChildren = DB::table('orphan_children')->where('status', 'active');
        $sponsoredChildren = DB::table('orphan_children as c')->where('c.status', 'active')->where(fn ($q) => $q
            ->whereExists(fn ($e) => $e->from('sponsorships')->whereColumn('orphan_child_id', 'c.id')->where('status', 'active'))
            ->orWhereExists(fn ($e) => $e->from('sponsorships')->whereColumn('household_id', 'c.household_id')->whereNull('orphan_child_id')->where('status', 'active')))->count();

        return Inertia::render('Orphans/Sponsorships', [
            // «الكفالات والمساعدات» switch: shown only when the viewer may open relief campaigns too.
            'aidSwitch' => app(NavigationAccessRules::class)->allows($request->user(), 'relief_campaigns'),
            'tab' => $filters['tab'] ?? 'sponsorships', 'filters' => ['status' => $filters['status'] ?? null, 'q' => $term],
            'sponsorships' => $this->presentRows($rows, $identity, $values),
            'sponsors' => DB::table('sponsors as d')->orderBy('d.name')->limit(500)
                ->get(['d.*', DB::raw("(select count(*) from sponsorships s where s.sponsor_id = d.id and s.status = 'active') as active_count")])
                ->map(fn ($s) => $this->sponsors->present($s, $identity, $manage) + ['active_count' => (int) $s->active_count]),
            'contributions' => DB::table('orphan_contributions as o')->leftJoin('sponsors as d', 'd.id', '=', 'o.sponsor_id')->orderByDesc('o.id')->limit(200)
                ->get(['o.*', 'd.name as sponsor_name', 'd.donor_no', 'd.confidential'])
                ->map(fn ($o) => ['id' => $o->id, 'reference_no' => $o->reference_no, 'amount' => $o->amount, 'currency' => $o->currency, 'status' => $o->status,
                    'status_label' => OrphanContributions::STATUS_LABELS[$o->status] ?? $o->status, 'earmark' => $contributions->earmarkLabel($o),
                    'donor' => $o->sponsor_id ? $this->sponsors->displayName((object) ['name' => $o->sponsor_name, 'donor_no' => $o->donor_no, 'confidential' => $o->confidential], $identity) : $o->donor_name,
                    'accounting_reference' => $o->accounting_reference, 'posted_on' => $o->posted_on, 'notes' => $o->notes, 'created_at' => $o->created_at,
                    'can_cancel' => $o->status === 'pledged' && ((int) $o->created_by === (int) $user->id || $this->access->isHead($user))]),
            'assistance' => DB::table('orphan_assistance_requests as a')->join('orphan_households as h', 'h.id', '=', 'a.household_id')
                ->orderByRaw("a.status in ('delivered','rejected')")->orderByDesc('a.id')->limit(200)
                ->get(['a.public_id', 'a.reference_no', 'a.status', 'a.assistance_type_value_id', 'a.estimated_value', 'a.currency', 'a.created_at', 'h.reference_no as household'])
                ->map(fn ($a) => (array) $a + ['status_label' => OrphanAssistance::STATUS_LABELS[$a->status] ?? $a->status, 'type' => $values[$a->assistance_type_value_id] ?? null]),
            'counts' => [
                'active' => DB::table('sponsorships')->where('status', 'active')->count(),
                'pending' => DB::table('sponsorships')->where('status', 'pending_approval')->count(),
                'irregular' => DB::table('sponsorships as s')->join('master_data_values as v', 'v.id', '=', 's.regularity_value_id')->where('s.status', 'active')->where('v.code', 'irregular')->count(),
                'sponsored_children' => $sponsoredChildren, 'unsponsored_children' => max(0, (clone $activeChildren)->count() - $sponsoredChildren),
            ],
            'options' => $manage ? $forms->options(['sponsorship_types', 'sponsorship_frequencies', 'sponsorship_purposes']) + [
                'sponsors' => DB::table('sponsors')->where('status', 'active')->orderBy('name')->get(['id', 'donor_no', 'name', 'confidential'])
                    ->map(fn ($s) => ['id' => $s->id, 'label' => $s->donor_no.' — '.$this->sponsors->displayName($s, $identity)]),
                'currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code'),
                'earmarks' => OrphanContributions::EARMARKS, 'kinds' => Sponsors::KINDS] : null,
            'can' => ['manage' => $manage, 'head' => $this->access->isHead($user), 'executive' => $this->access->isExecutive($user)],
        ] + $this->programs($identity, $manage, $forms));
    }

    /**
     * Phase 3 tabs: waiting list, agreements and templates, pooled funds and the need gap (empty until the
     * phase 3 migration has run).
     *
     * @return array<string, mixed>
     */
    private function programs(bool $identity, bool $manage, OrphanForms $forms): array
    {
        $agreements = app(SponsorAgreements::class);
        if (! $agreements->available()) {
            return ['programs' => null];
        }

        return ['programs' => [
            'waitlist' => app(SponsorshipWaitlist::class)->board(),
            'agreements' => $agreements->index($identity),
            'templates' => $agreements->templates(),
            'funds' => app(SponsorshipFunds::class)->index(),
            'gap' => app(HouseholdNeeds::class)->report(),
            'kinds' => SponsorAgreements::KINDS, 'placeholders' => SponsorAgreements::PLACEHOLDERS,
            'options' => $manage ? ['programs' => $forms->options(['sponsorship_types'])['sponsorship_types'] ?? [],
                'agreements' => DB::table('sponsorship_agreements')->whereIn('status', ['draft', 'signed'])->orderByDesc('id')->get(['id', 'agreement_no', 'sponsor_id', 'kind']),
                'templates' => DB::table('sponsorship_agreement_templates')->where('status', 'active')->orderBy('title')->get(['id', 'title', 'kind', 'version']),
                'funds' => DB::table('sponsorship_funds')->where('status', 'active')->orderBy('name')->get(['id', 'fund_no', 'name', 'currency'])] : null,
        ]];
    }

    public function storeSponsor(Request $request): RedirectResponse
    {
        $this->sponsors->save($request->user(), $request->all());

        return back()->with('success', 'سُجل الكافل.');
    }

    public function updateSponsor(Request $request, int $sponsor): RedirectResponse
    {
        $this->sponsors->save($request->user(), $request->all(), $sponsor);

        return back()->with('success', 'حُدّثت بيانات الكافل.');
    }

    public function store(Request $request): RedirectResponse
    {
        $id = $this->sponsorships->save($request->user(), $request->all());

        return redirect()->route('orphans.sponsorships.show', $this->publicId($id))->with('success', 'حُفظت مسودة الكفالة؛ راجعها ثم ارفعها للتوصية والاعتماد.');
    }

    public function show(Request $request, string $publicId, SponsorshipAccounts $accounts, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $row = $this->find($publicId);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $level = $instance ? $visibility->accessLevel($user, (int) $instance->id) : null;
        $manage = $this->access->allows($user, 'sponsorships');
        abort_unless($manage || $this->access->isExecutive($user) || $accounts->allows($user) || $level !== null, 403);
        $this->access->logRead($user, 'sponsorship', (int) $row->id);
        $identity = $this->sponsors->seesIdentity($user);
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $presented = $this->presentRows($this->listQuery()->where('s.id', $row->id)->get(), $identity, $values)->first();
        $sponsor = DB::table('sponsors')->where('id', $row->sponsor_id)->first();
        $chain = DB::table('sponsorships')->where(fn ($q) => $q->where('id', $row->supersedes_id ?? 0)->orWhere('supersedes_id', $row->id))
            ->orderBy('version')->get(['public_id', 'sponsorship_no', 'version', 'status', 'starts_on', 'ends_on']);

        return Inertia::render('Orphans/Sponsorship', [
            'sponsorship' => $presented + ['id' => $row->id, 'expense_amount' => $row->expense_amount, 'savings_amount' => $row->savings_amount, 'conditions' => $row->conditions,
                'source' => $row->source, 'status_reason' => $row->status_reason, 'type_value_id' => $row->type_value_id, 'frequency_value_id' => $row->frequency_value_id,
                'purpose_value_id' => $row->purpose_value_id, 'type' => $values[$row->type_value_id] ?? null, 'sponsor_id' => (int) $row->sponsor_id,
                'beneficiary_type' => $row->beneficiary_type, 'beneficiary_reference' => $row->orphan_child_id
                    ? DB::table('orphan_children')->where('id', $row->orphan_child_id)->value('file_no') : DB::table('orphan_households')->where('id', $row->household_id)->value('reference_no'), 'approved_by' => $row->approved_by ? DB::table('users')->where('id', $row->approved_by)->value('name') : null,
                'approved_at' => $row->approved_at, 'created_by' => DB::table('users')->where('id', $row->created_by)->value('name'), 'is_amendment' => (bool) $row->supersedes_id,
                'editable' => $manage && $row->status === 'draft' && $this->sponsorships->mayEditDraft($user, $row), 'submitted' => (bool) $row->workflow_instance_id],
            'sponsor' => $this->sponsors->present($sponsor, $identity, $manage),
            'figures' => in_array($row->status, ['active', 'paused', 'ended', 'superseded'], true) ? $this->sponsorships->figures($row) : null,
            'schedules' => DB::query()->fromSub($this->sponsorships->scheduleBalances(), 'b')->where('b.sponsorship_id', $row->id)->orderBy('b.period_start')->limit(400)->get()
                ->map(fn ($s) => ['id' => $s->id, 'period_start' => $s->period_start, 'due_on' => $s->due_on, 'expected' => $s->expected_amount, 'collected' => $s->collected,
                    'remaining' => $s->remaining, 'savings_part' => $s->savings_part, 'status' => $s->status, 'status_label' => Sponsorships::SCHEDULE_LABELS[$s->status] ?? $s->status]),
            'collections' => DB::table('sponsorship_collections as c')->join('sponsorship_schedules as s', 's.id', '=', 'c.schedule_id')->leftJoin('users as u', 'u.id', '=', 'c.recorded_by')
                ->where('c.sponsorship_id', $row->id)->orderByDesc('c.id')->limit(200)
                ->get(['c.id', 'c.amount', 'c.currency', 'c.accounting_reference', 'c.posted_on', 'c.reversed_at', 'c.reversal_reason', 's.period_start', 'u.name as recorded_by']),
            'disbursements' => DB::table('sponsorship_disbursements as x')->leftJoin('users as u', 'u.id', '=', 'x.recorded_by')->where('x.sponsorship_id', $row->id)->orderByDesc('x.id')->limit(200)
                ->get(['x.id', 'x.amount', 'x.currency', 'x.accounting_reference', 'x.executed_on', 'x.status', 'x.reversal_reason', 'u.name as recorded_by']),
            'versions' => $chain->map(fn ($v) => (array) $v + ['status_label' => Sponsorships::STATUS_LABELS[$v->status] ?? $v->status]),
            'agreement' => ! empty($row->agreement_id) ? DB::table('sponsorship_agreements')->where('id', $row->agreement_id)->first(['public_id', 'agreement_no', 'kind', 'status']) : null,
            'progress' => $instance ? $progress->view($instance, $level ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision, 'status' => $instance->status,
                'actions' => $engine->availableActions($instance, $user)] : null,
            'options' => $manage ? app(OrphanForms::class)->options(['sponsorship_types', 'sponsorship_frequencies', 'sponsorship_purposes'])
                + ['currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code')] : null,
            'can' => ['manage' => $manage, 'head' => $this->access->isHead($user),
                'amend' => $manage && in_array($row->status, ['active', 'paused'], true) && ! DB::table('sponsorships')->where('supersedes_id', $row->id)->whereIn('status', ['draft', 'pending_approval'])->exists()],
        ]);
    }

    public function update(Request $request, string $publicId): RedirectResponse
    {
        $this->sponsorships->save($request->user(), $request->all(), (int) $this->find($publicId)->id);

        return back()->with('success', 'حُفظت المسودة.');
    }

    public function action(Request $request, string $publicId, string $action, WorkflowEngine $engine): RedirectResponse
    {
        $user = $request->user();
        $id = (int) $this->find($publicId)->id;
        if ($action === 'amend') {
            $newId = $this->sponsorships->amend($user, $id);

            return redirect()->route('orphans.sponsorships.show', $this->publicId($newId))->with('success', 'فُتح إصدار تعديل؛ عدّل القيم وتاريخ السريان ثم ارفعه للاعتماد.');
        }
        $message = match ($action) {
            'submit' => tap('رُفعت الكفالة لرئيس القسم ثم المدير التنفيذي.', fn () => $this->sponsorships->submit($user, $id, $engine)),
            'cancel' => tap('أُلغيت المسودة.', fn () => $this->sponsorships->cancelDraft($user, $id, (string) $request->input('reason', 'إلغاء المسودة'))),
            'pause' => tap('أُوقفت الكفالة مؤقتًا.', fn () => $this->sponsorships->changeStatus($user, $id, 'pause', $request->all())),
            'resume' => tap('استؤنفت الكفالة.', fn () => $this->sponsorships->changeStatus($user, $id, 'resume', $request->all())),
            'end' => tap('أُنهيت الكفالة.', fn () => $this->sponsorships->changeStatus($user, $id, 'end', $request->all())),
            default => abort(404),
        };

        return back()->with('success', $message);
    }

    public function storeContribution(Request $request, OrphanContributions $contributions): RedirectResponse
    {
        $contributions->create($request->user(), $request->all());

        return back()->with('success', 'سُجل التعهد؛ يُعد مقبوضًا بعد تسجيل الحسابات لسند القبض.');
    }

    public function cancelContribution(Request $request, int $contribution, OrphanContributions $contributions): RedirectResponse
    {
        $contributions->cancel($request->user(), $contribution, (string) $request->input('reason'));

        return back()->with('success', 'أُلغي التعهد.');
    }

    public function storeAssistance(Request $request, string $publicId, OrphanAssistance $assistance, WorkflowEngine $engine): RedirectResponse
    {
        $household = OrphanHousehold::where('public_id', $publicId)->firstOrFail();
        $id = $assistance->create($request->user(), (int) $household->id, $request->all(), $engine);

        return redirect()->route('orphans.assistance.show', DB::table('orphan_assistance_requests')->where('id', $id)->value('public_id'))
            ->with('success', 'قُدّم طلب المساعدة لرئيس القسم ثم المدير التنفيذي.');
    }

    public function showAssistance(Request $request, string $publicId, OrphanForms $forms, WorkflowEngine $engine, WorkflowProgress $progress, WorkflowVisibilityService $visibility): Response
    {
        $user = $request->user();
        $row = DB::table('orphan_assistance_requests')->where('public_id', $publicId)->first();
        abort_unless($row, 404);
        $instance = $row->workflow_instance_id ? DB::table('workflow_instances')->where('id', $row->workflow_instance_id)->first() : null;
        $level = $instance ? $visibility->accessLevel($user, (int) $instance->id) : null;
        $finance = app(FinanceGuard::class);
        abort_unless($this->access->allows($user, 'view') || $this->access->isExecutive($user) || $level !== null
            || (! $user->isSystemAdmin() && ($finance->allows($user, 'finance.sponsorships.record') || $finance->allows($user, 'finance.stock.issue'))), 403);
        $this->access->logRead($user, 'assistance', (int) $row->id);
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $household = OrphanHousehold::find($row->household_id);
        $child = $row->orphan_child_id ? OrphanChild::find($row->orphan_child_id) : null;
        $documentIds = json_decode((string) $row->evidence_document_ids, true) ?: [];
        $editable = $row->status === 'returned' && (int) $row->created_by === (int) $user->id;
        $names = fn (?int $id) => $id ? DB::table('users')->where('id', $id)->value('name') : null;

        return Inertia::render('Orphans/Assistance', [
            'request' => ['public_id' => $row->public_id, 'reference_no' => $row->reference_no, 'status' => $row->status, 'status_label' => OrphanAssistance::STATUS_LABELS[$row->status] ?? $row->status,
                'household' => ['public_id' => $household?->public_id, 'reference_no' => $household?->reference_no], 'child' => $child ? ['public_id' => $child->public_id, 'name' => $child->full_name, 'file_no' => $child->file_no] : null,
                'type' => $values[$row->assistance_type_value_id] ?? null, 'assistance_type_value_id' => $row->assistance_type_value_id, 'description' => $row->description,
                'quantity' => $row->quantity, 'estimated_value' => $row->estimated_value, 'currency' => $row->currency, 'funding_source' => $row->funding_source,
                'execution_reference' => $row->execution_reference, 'executed_by' => $names($row->executed_by), 'executed_at' => $row->executed_at,
                'delivery_proof' => $row->delivery_proof, 'delivered_by' => $names($row->delivered_by), 'delivered_at' => $row->delivered_at,
                'created_by' => $names($row->created_by), 'created_at' => $row->created_at, 'evidence_document_ids' => $documentIds],
            'documents' => DB::table('orphan_documents as d')->join('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')->whereIn('d.id', $documentIds)
                ->get(['d.id', 'v.name_ar as type', 'd.status', 'd.created_at']),
            'householdDocuments' => $editable ? $this->householdDocuments((int) $row->household_id) : [],
            'progress' => $instance ? $progress->view($instance, $level ?? 'operational') : null,
            'workflow' => $instance ? ['publicId' => $instance->public_id, 'referenceNo' => $instance->reference_no, 'revision' => (int) $instance->revision, 'status' => $instance->status,
                'actions' => $engine->availableActions($instance, $user)] : null,
            'options' => $editable ? $forms->options(['assistance_types']) + ['currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code')] : null,
            'can' => ['edit' => $editable],
        ]);
    }

    public function updateAssistance(Request $request, string $publicId, OrphanAssistance $assistance): RedirectResponse
    {
        $id = (int) DB::table('orphan_assistance_requests')->where('public_id', $publicId)->value('id');
        abort_unless($id > 0, 404);
        $assistance->update($request->user(), $id, $request->all());

        return back()->with('success', 'حُفظ الاستكمال؛ أعد رفع الطلب من «الإجراء المطلوب منك».');
    }

    /** @return list<array{id: int, label: string}> documents of the family file (household and children) to attach as evidence */
    public function householdDocuments(int $householdId): array
    {
        $children = DB::table('orphan_children')->where('household_id', $householdId)->pluck('id');

        return DB::table('orphan_documents as d')->join('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('d.owner_type', 'household')->where('d.owner_id', $householdId))
                ->orWhere(fn ($w) => $w->where('d.owner_type', 'child')->whereIn('d.owner_id', $children)))
            ->orderByDesc('d.id')->limit(100)->get(['d.id', 'v.name_ar', 'd.owner_type', 'd.created_at'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'label' => $d->name_ar.($d->owner_type === 'child' ? ' (طفل)' : '').' — '.substr((string) $d->created_at, 0, 10)])->all();
    }

    /** Sponsorships of one household and its children, for the family file. */
    public function forHousehold(User $user, int $householdId): array
    {
        $identity = $this->sponsors->seesIdentity($user);

        return $this->presentRows($this->listQuery()->where('s.household_id', $householdId)->whereNotIn('s.status', ['cancelled', 'superseded'])->orderByDesc('s.id')->get(),
            $identity, DB::table('master_data_values')->pluck('name_ar', 'id'))->all();
    }

    /** Lines of one agreement (all states but superseded), for the agreement page. */
    public function forAgreement(User $user, int $agreementId): array
    {
        return $this->presentRows($this->listQuery()->where('s.agreement_id', $agreementId)->where('s.status', '<>', 'superseded')->orderBy('s.id')->get(),
            $this->sponsors->seesIdentity($user), DB::table('master_data_values')->pluck('name_ar', 'id'))->all();
    }

    /** Sponsorships of one sponsor, for the sponsor's page. */
    public function forSponsor(User $user, int $sponsorId): array
    {
        return $this->presentRows($this->listQuery()->where('s.sponsor_id', $sponsorId)->whereNotIn('s.status', ['cancelled', 'superseded'])->orderByDesc('s.id')->limit(300)->get(),
            $this->sponsors->seesIdentity($user), DB::table('master_data_values')->pluck('name_ar', 'id'))->all();
    }

    private function listQuery(): Builder
    {
        return DB::table('sponsorships as s')->join('sponsors as d', 'd.id', '=', 's.sponsor_id')->leftJoin('orphan_children as c', 'c.id', '=', 's.orphan_child_id')
            ->join('orphan_households as h', 'h.id', '=', 's.household_id')
            ->select('s.id', 's.public_id', 's.sponsorship_no', 's.status', 's.amount', 's.currency', 's.frequency_value_id', 's.purpose_value_id', 's.regularity_value_id',
                's.starts_on', 's.ends_on', 's.version', 's.supersedes_id', 's.beneficiary_type', 's.orphan_child_id', 'd.name as sponsor_name', 'd.donor_no', 'd.confidential',
                'c.file_no', 'h.reference_no as household_ref', 'h.public_id as household_public_id');
    }

    /** @param Collection<int, object> $rows */
    private function presentRows(Collection $rows, bool $identity, Collection $values): Collection
    {
        $children = OrphanChild::whereIn('id', $rows->pluck('orphan_child_id')->filter()->unique())->get(['id', 'public_id', 'full_name'])->keyBy('id');

        return $rows->map(fn ($s) => ['public_id' => $s->public_id, 'sponsorship_no' => $s->sponsorship_no, 'status' => $s->status,
            'status_label' => Sponsorships::STATUS_LABELS[$s->status] ?? $s->status, 'regularity' => $values[$s->regularity_value_id] ?? null,
            'amount' => $s->amount, 'currency' => $s->currency, 'frequency' => $values[$s->frequency_value_id] ?? null, 'purpose' => $values[$s->purpose_value_id] ?? null,
            'starts_on' => $s->starts_on, 'ends_on' => $s->ends_on, 'version' => (int) $s->version,
            'sponsor' => $this->sponsors->displayName((object) ['name' => $s->sponsor_name, 'donor_no' => $s->donor_no, 'confidential' => $s->confidential], $identity),
            'beneficiary' => $s->orphan_child_id ? ($children->get($s->orphan_child_id)?->full_name.' ('.$s->file_no.')') : 'أسرة '.$s->household_ref,
            'child_public_id' => $s->orphan_child_id ? $children->get($s->orphan_child_id)?->public_id : null, 'household_public_id' => $s->household_public_id]);
    }

    private function find(string $publicId): object
    {
        $row = DB::table('sponsorships')->where('public_id', $publicId)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function publicId(int $id): string
    {
        return (string) DB::table('sponsorships')->where('id', $id)->value('public_id');
    }
}
