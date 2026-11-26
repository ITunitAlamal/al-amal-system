<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\OrphanChild;
use App\Models\OrphanHealthRecord;
use App\Models\OrphanHousehold;
use App\Models\OrphanParent;
use App\Models\OrphanPaymentInstruction;
use App\Models\User;
use App\Services\Configuration\CustomFieldEntities;
use App\Services\Configuration\CustomFieldService;
use App\Services\Finance\FinanceMoney;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanApplications;
use App\Services\Orphans\OrphanAssistance;
use App\Services\Orphans\OrphanChanges;
use App\Services\Orphans\OrphanDocuments;
use App\Services\Orphans\OrphanEmergencies;
use App\Services\Orphans\OrphanForms;
use App\Services\Orphans\OrphanReferrals;
use App\Services\Orphans\OrphanRegistry;
use App\Services\Orphans\OrphanVisits;
use App\Services\Orphans\RegistryIdentity;
use App\Services\Orphans\ScoringEngine;
use App\Services\Orphans\ScoringRulesets;
use App\Services\Orphans\SponsorshipAccounts;
use App\Services\Orphans\Sponsorships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Family files, parents, children, guardian, education, health and documents of the orphans department. */
final class RegistryController extends Controller
{
    private const SELECT_FIELDS = ['identity_type_value_id', 'orphan_class_value_id', 'vital_status_value_id', 'death_cause_value_id', 'marital_status_value_id',
        'education_level_value_id', 'enrollment_value_id', 'stage_value_id', 'health_status_value_id', 'income_source_value_id', 'economic_status_value_id',
        'needs_level_value_id', 'previous_tenure_value_id', 'current_tenure_value_id', 'shelter_type_value_id', 'document_type_value_id', 'channel_value_id',
        'institution_value_id', 'disability_types', 'guardian_authority_kinds', 'social_visit_types'];

    public function __construct(private readonly OrphanAccess $access, private readonly OrphanRegistry $registry, private readonly OrphanForms $forms) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $filters = $request->validate(['status' => ['nullable', 'in:draft,registered,active,inactive,closed'], 'q' => ['nullable', 'string', 'max:40'], 'tab' => ['nullable', 'string', 'max:20']]);
        $query = DB::table('orphan_households as h')->leftJoin('guardians as g', 'g.id', '=', 'h.primary_guardian_id')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('h.status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = trim($term);
                $q->where(fn ($w) => $w->where('h.reference_no', 'ilike', "%{$term}%")->orWhere('g.reference_no', 'ilike', "%{$term}%")
                    ->orWhereIn('h.id', DB::table('orphan_children')->where('file_no', 'ilike', "%{$term}%")
                        ->orWhere('national_id_hash', RegistryIdentity::hash($term))->select('household_id')));
            })
            ->orderByDesc('h.id');
        $emergencies = app(OrphanEmergencies::class);
        $households = $query->paginate(25, ['h.id', 'h.public_id', 'h.reference_no', 'h.family_name', 'h.status', 'h.displaced', 'h.created_at', 'g.name as guardian_name',
            DB::raw('(select count(*) from orphan_children c where c.household_id = h.id) as children'),
            DB::raw("(select count(*) from orphan_children c where c.household_id = h.id and c.status = 'active') as active_children"),
            DB::raw($emergencies->available() ? '(select count(*) from orphan_emergency_flags f where f.household_id = h.id and f.cleared_at is null) as emergency' : '0 as emergency')])->withQueryString();
        $queues = [
            'applications' => DB::table('orphan_applications as a')->join('orphan_households as h', 'h.id', '=', 'a.household_id')->whereIn('a.status', ['submitted', 'under_review', 'needs_info', 'in_decision'])
                ->orderBy('a.id')->limit(50)->get(['a.id', 'a.reference_no', 'a.status', 'a.source', 'a.created_at', 'h.reference_no as household'])
                ->map(fn ($row) => (array) $row + ['status_label' => OrphanApplications::STATUS_LABELS[$row->status] ?? $row->status]),
            'changes' => DB::table('orphan_change_requests')->whereIn('status', ['submitted', 'in_review', 'returned'])->orderBy('id')->limit(50)
                ->get(['id', 'reference_no', 'category', 'status', 'created_at'])
                ->map(fn ($row) => (array) $row + ['category_label' => OrphanChanges::CATEGORY_LABELS[$row->category] ?? $row->category, 'status_label' => OrphanChanges::STATUS_LABELS[$row->status] ?? $row->status]),
            'visits' => DB::table('orphan_social_visits as v')->join('orphan_households as h', 'h.id', '=', 'v.household_id')->leftJoin('users as u', 'u.id', '=', 'v.researcher_id')
                ->whereIn('v.status', ['planned', 'submitted', 'returned'])->orderBy('v.scheduled_on')->limit(50)
                ->get(['v.id', 'v.reference_no', 'v.status', 'v.scheduled_on', 'h.reference_no as household', 'u.name as researcher'])
                ->map(fn ($row) => (array) $row + ['status_label' => OrphanVisits::STATUS_LABELS[$row->status] ?? $row->status]),
            'emergencies' => $emergencies->open(),
            'referrals' => app(OrphanReferrals::class)->open($user),
            'documents' => app(OrphanDocuments::class)->dueForRenewal(100),
        ];

        return Inertia::render('Orphans/Index', [
            'households' => $households->through(fn ($row) => [
                'public_id' => $row->public_id, 'reference_no' => $row->reference_no, 'family_name' => $this->decrypt($row->family_name), 'status' => $row->status,
                'status_label' => OrphanHousehold::STATUS_LABELS[$row->status] ?? $row->status, 'guardian' => $row->guardian_name, 'children' => (int) $row->children,
                'active_children' => (int) $row->active_children, 'displaced' => (bool) $row->displaced, 'emergency' => (int) $row->emergency > 0,
            ]),
            'queues' => $queues,
            'counts' => [
                'active_children' => DB::table('orphan_children')->where('status', 'active')->count(),
                'pending_children' => DB::table('orphan_children')->whereIn('status', ['applicant', 'pending_activation'])->count(),
                'households' => DB::table('orphan_households')->count(),
                'open_applications' => DB::table('orphan_applications')->whereIn('status', ['submitted', 'under_review', 'needs_info', 'in_decision'])->count(),
            ],
            'filters' => $filters, 'can' => $this->can($user),
            'householdRules' => $this->forms->rules('household'), 'options' => $this->forms->options(['income_source_value_id', 'economic_status_value_id',
                'needs_level_value_id', 'previous_tenure_value_id', 'current_tenure_value_id', 'shelter_type_value_id']),
        ]);
    }

    public function storeHousehold(Request $request): RedirectResponse
    {
        $household = $this->registry->createHousehold($request->user(), $request->all());

        return redirect()->route('orphans.households.show', $household->public_id)->with('success', 'أُنشئ ملف الأسرة '.$household->reference_no.'. أكمل الوالدين والأطفال والوصي والمستندات.');
    }

    public function showHousehold(Request $request, string $publicId, OrphanDocuments $documents): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $household = OrphanHousehold::where('public_id', $publicId)->firstOrFail();
        $this->access->logRead($user, 'household', $household->id);
        $sensitive = $this->access->allows($user, 'sensitive');
        $parents = OrphanParent::whereIn('id', DB::table('orphan_household_parents')->where('household_id', $household->id)->pluck('parent_id'))->orderBy('role')->get();
        $guardian = $household->primary_guardian_id ? Guardian::find($household->primary_guardian_id) : null;
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $customFields = app(CustomFieldService::class);
        $guardianFields = $this->registry->guardianFieldDefinitions($user);
        $guardianValues = $guardian ? collect($customFields->context(CustomFieldEntities::ORPHAN_GUARDIAN, (int) $guardian->id))
            ->only($guardianFields->pluck('code')->all())->all() : [];
        $emergencies = app(OrphanEmergencies::class);
        $referrals = app(OrphanReferrals::class);

        return Inertia::render('Orphans/Household', [
            'household' => [
                'id' => $household->id, 'public_id' => $household->public_id, 'reference_no' => $household->reference_no, 'status' => $household->status,
                'status_label' => OrphanHousehold::STATUS_LABELS[$household->status] ?? $household->status,
                'fields' => $household->only(['family_name', 'family_size', 'dependents_count', 'working_members_count', 'income_source_value_id', 'economic_status_value_id',
                    'needs_level_value_id', 'previous_tenure_value_id', 'current_tenure_value_id', 'shelter_type_value_id', 'displaced', 'basic_needs', 'guardian_authority_value_id'])
                    + ['monthly_income' => $sensitive ? $household->monthly_income : null, 'rent_amount' => $household->rent_amount, 'displaced_since' => $household->displaced_since?->format('Y-m-d')],
                'original_address' => $this->registry->currentAddress($household->id, 'original'), 'current_address' => $this->registry->currentAddress($household->id, 'current'),
                'address_history' => DB::table('orphan_addresses')->where('household_id', $household->id)->whereNotNull('valid_to')->orderByDesc('valid_to')
                    ->get(['kind', 'governorate', 'city', 'neighborhood', 'valid_from', 'valid_to']),
                'editable' => ! in_array($household->status, ['active', 'inactive', 'closed'], true),
            ],
            'guardian' => $guardian ? ['id' => $guardian->id, 'reference_no' => $guardian->reference_no, 'name' => $guardian->name,
                'identity' => $this->mask($guardian->national_id, $sensitive), 'phone' => $this->mask($guardian->phone, $sensitive), 'portal_active' => (bool) $guardian->is_active,
                'authority' => $values[$household->guardian_authority_value_id] ?? null,
                'extra' => $customFields->display(CustomFieldEntities::ORPHAN_GUARDIAN, (int) $guardian->id, $sensitive)] : null,
            'guardianFields' => $customFields->forForm($guardianFields),
            'guardianValues' => (object) $guardianValues,
            'emergencies' => $emergencies->forHousehold((int) $household->id),
            'referrals' => $referrals->forHousehold((int) $household->id, $user),
            'caseAlerts' => ['available' => $emergencies->available(), 'raise' => $emergencies->canRaise($user), 'clear' => $emergencies->canClear($user),
                'head_appointed' => $this->access->headUserIds() !== [], 'refer' => $referrals->canRecord($user),
                'responsible' => $referrals->canRecord($user) ? $referrals->responsibleOptions() : []],
            'parents' => $parents->map(fn (OrphanParent $p) => ['id' => $p->id, 'role' => $p->role, 'full_name' => $p->full_name, 'identity' => $this->mask($p->identity_no, $sensitive),
                'vital' => $values[$p->vital_status_value_id] ?? null, 'death_date' => $p->death_date?->format('Y-m-d'), 'death_cause' => $values[$p->death_cause_value_id] ?? null,
                'provenance' => $p->provenance, 'fields' => $p->only(['full_name', 'identity_type_value_id', 'vital_status_value_id', 'death_cause_value_id', 'occupation',
                    'marital_status_value_id', 'education_level_value_id', 'specialization', 'has_illness', 'has_disability', 'disability_types'])
                    + ['date_of_birth' => $p->date_of_birth?->format('Y-m-d'), 'death_date' => $p->death_date?->format('Y-m-d'), 'illness_note' => $sensitive ? $p->illness_note : null]]),
            'children' => OrphanChild::where('household_id', $household->id)->orderBy('date_of_birth')->get()->map(fn (OrphanChild $c) => [
                'public_id' => $c->public_id, 'full_name' => $c->full_name, 'file_no' => $c->file_no, 'status' => $c->status,
                'status_label' => OrphanChild::STATUS_LABELS[$c->status] ?? $c->status, 'age' => $c->date_of_birth ? $c->ageOn() : null, 'sex' => $c->sex,
                'class' => $values[$c->orphan_class_value_id] ?? null, 'verification' => OrphanChild::VERIFICATION_LABELS[$c->verification_status] ?? $c->verification_status,
                'father_id' => $c->father_id, 'mother_id' => $c->mother_id,
                'fields' => ($c->name_parts ?? []) + $c->only(['english_name', 'identity_type_value_id', 'place_of_birth', 'sex', 'orphan_class_value_id', 'sole_survivor', 'father_id', 'mother_id'])
                    + ['date_of_birth' => $c->date_of_birth?->format('Y-m-d'), 'phone' => $sensitive ? $c->phone : null],
            ]),
            'checklist' => $documents->checklist($household->id),
            'applications' => DB::table('orphan_applications')->where('household_id', $household->id)->orderByDesc('id')->get(['id', 'reference_no', 'status', 'source', 'created_at', 'decided_at'])
                ->map(fn ($a) => (array) $a + ['status_label' => OrphanApplications::STATUS_LABELS[$a->status] ?? $a->status]),
            'changes' => DB::table('orphan_change_requests')->where('household_id', $household->id)->orderByDesc('id')->limit(20)->get(['id', 'reference_no', 'category', 'status', 'created_at'])
                ->map(fn ($c) => (array) $c + ['category_label' => OrphanChanges::CATEGORY_LABELS[$c->category] ?? $c->category, 'status_label' => OrphanChanges::STATUS_LABELS[$c->status] ?? $c->status]),
            'visits' => DB::table('orphan_social_visits')->where('household_id', $household->id)->orderByDesc('scheduled_on')->limit(20)->get(['id', 'reference_no', 'status', 'scheduled_on', 'visited_on'])
                ->map(fn ($v) => (array) $v + ['status_label' => OrphanVisits::STATUS_LABELS[$v->status] ?? $v->status]),
            'payments' => DB::table('orphan_payment_instructions')->where('household_id', $household->id)->orderByDesc('id')->get(['id', 'channel_value_id', 'institution_value_id', 'account_last4', 'status', 'created_at', 'review_note'])
                ->map(fn ($p) => ['id' => $p->id, 'channel' => $values[$p->channel_value_id] ?? '', 'institution' => $values[$p->institution_value_id] ?? null,
                    'account' => '••••'.$p->account_last4, 'status' => $p->status, 'status_label' => OrphanPaymentInstruction::STATUS_LABELS[$p->status] ?? $p->status,
                    'review_note' => $p->review_note, 'created_at' => $p->created_at]),
            'sponsorships' => app(SponsorshipController::class)->forHousehold($user, (int) $household->id),
            'assistance' => DB::table('orphan_assistance_requests')->where('household_id', $household->id)->orderByDesc('id')->limit(30)
                ->get(['public_id', 'reference_no', 'status', 'assistance_type_value_id'])
                ->map(fn ($a) => ['public_id' => $a->public_id, 'reference_no' => $a->reference_no, 'type' => $values[$a->assistance_type_value_id] ?? null,
                    'status_label' => OrphanAssistance::STATUS_LABELS[$a->status] ?? $a->status]),
            'assistanceOptions' => $this->access->allows($user, 'records') && in_array($household->status, ['registered', 'active'], true)
                ? $this->forms->options(['assistance_types']) + ['currencies' => DB::table('currencies')->where('is_active', true)->orderBy('code')->pluck('code'),
                    'documents' => app(SponsorshipController::class)->householdDocuments((int) $household->id)] : null,
            'rules' => ['household' => $this->forms->rules('household'), 'father' => $this->forms->rules('father'), 'mother' => $this->forms->rules('mother'),
                'child' => $this->forms->rules('child'), 'guardian' => $this->forms->rules('guardian')],
            'options' => $this->forms->options(self::SELECT_FIELDS),
            'researchers' => $this->access->isHead($user) ? $this->researchers() : [],
            'can' => $this->can($user),
        ]);
    }

    public function updateHousehold(Request $request, string $publicId): RedirectResponse
    {
        $this->registry->updateHousehold($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(), $request->all());

        return back()->with('success', 'حُفظت بيانات الأسرة.');
    }

    public function saveGuardian(Request $request, string $publicId): RedirectResponse
    {
        $guardian = $this->registry->saveGuardian($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail(), $request->all());

        return back()->with('success', 'سُجل الوصي '.$guardian->reference_no.' للأسرة.');
    }

    public function saveParent(Request $request, string $publicId, string $role): RedirectResponse
    {
        $household = OrphanHousehold::where('public_id', $publicId)->firstOrFail();
        $parent = $request->filled('parent_id') ? OrphanParent::findOrFail((int) $request->input('parent_id')) : null;
        $this->registry->saveParent($request->user(), $household, $role, $request->all(), $parent);

        return back()->with('success', $role === 'father' ? 'حُفظت بيانات الأب.' : 'حُفظت بيانات الأم.');
    }

    public function saveChild(Request $request, string $publicId): RedirectResponse
    {
        $household = OrphanHousehold::where('public_id', $publicId)->firstOrFail();
        $child = $request->filled('child_public_id') ? OrphanChild::where('public_id', $request->input('child_public_id'))->firstOrFail() : null;
        $this->registry->saveChild($request->user(), $household, $request->all(), $child);

        return back()->with('success', 'حُفظت بيانات الطفل.');
    }

    public function showChild(Request $request, string $publicId, OrphanDocuments $documents, ScoringRulesets $rulesets): Response
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $child = OrphanChild::where('public_id', $publicId)->firstOrFail();
        $this->access->logRead($user, 'child', $child->id);
        $sensitive = $this->access->allows($user, 'sensitive');
        $values = DB::table('master_data_values')->pluck('name_ar', 'id');
        $health = $sensitive ? OrphanHealthRecord::where('orphan_child_id', $child->id)->orderByDesc('id')->limit(10)->get() : collect();
        $score = DB::table('orphan_social_scores as s')->join('scoring_rulesets as r', 'r.id', '=', 's.ruleset_id')->where('s.orphan_child_id', $child->id)->where('s.is_current', true)
            ->first(['s.*', 'r.name as ruleset', 'r.version']);
        $household = OrphanHousehold::find($child->household_id);

        return Inertia::render('Orphans/Child', [
            'child' => ['id' => $child->id, 'public_id' => $child->public_id, 'full_name' => $child->full_name, 'file_no' => $child->file_no, 'status' => $child->status,
                'status_label' => OrphanChild::STATUS_LABELS[$child->status] ?? $child->status, 'status_reason' => $child->status_reason,
                'verification' => OrphanChild::VERIFICATION_LABELS[$child->verification_status] ?? $child->verification_status,
                'identity' => $this->mask($child->national_id, $sensitive), 'date_of_birth' => $child->date_of_birth?->format('Y-m-d'), 'age' => $child->ageOn(),
                'sex' => $child->sex, 'class' => $values[$child->orphan_class_value_id] ?? null, 'sole_survivor' => $child->sole_survivor,
                'place_of_birth' => $child->place_of_birth, 'english_name' => $child->english_name, 'activated_at' => $child->activated_at?->toDateString(),
                'father' => $child->father?->full_name, 'mother' => $child->mother?->full_name],
            'household' => ['public_id' => $household?->public_id, 'reference_no' => $household?->reference_no],
            'education' => DB::table('orphan_education_records')->where('orphan_child_id', $child->id)->orderByDesc('school_year')->get()
                ->map(fn ($e) => ['school_year' => $e->school_year, 'enrollment' => $values[$e->enrollment_value_id] ?? '', 'stage' => $values[$e->stage_value_id] ?? null,
                    'school_name' => $e->school_name, 'grade' => $e->grade, 'achievement' => $e->achievement, 'support_needs' => $e->support_needs]),
            'health' => $health->map(fn ($h) => ['id' => $h->id, 'is_current' => $h->is_current, 'status' => $values[$h->health_status_value_id] ?? null, 'has_chronic' => $h->has_chronic,
                'chronic_note' => $h->chronic_note, 'has_disability' => $h->has_disability, 'disability_types' => $h->disability_types, 'medications' => $h->medications,
                'coverage' => $h->coverage, 'follow_up' => $h->follow_up, 'recorded_at' => $h->created_at?->toDateString()]),
            'guardianships' => DB::table('orphan_guardianships as g')->join('guardians as p', 'p.id', '=', 'g.guardian_id')->where('g.orphan_child_id', $child->id)->orderByDesc('g.id')
                ->get(['p.name', 'p.reference_no', 'g.authority_kind', 'g.starts_on', 'g.ends_on', 'g.ended_reason']),
            'score' => $score ? ['id' => $score->id, 'total' => (float) $score->total, 'override_total' => $score->override_total !== null ? (float) $score->override_total : null,
                'override_reason' => $score->override_reason, 'ruleset' => $score->ruleset.' (إصدار '.$score->version.')', 'computed_at' => $score->computed_at,
                'components' => json_decode($score->components, true), 'factor_labels' => array_map(fn ($f) => $f[0], ScoringEngine::FACTORS)] : null,
            'socialRuleset' => (bool) $rulesets->activeSocial(),
            'documents' => collect($documents->checklist((int) $child->household_id))->where('owner_type', 'child')->where('owner_id', $child->id)->values(),
            'changes' => DB::table('orphan_change_requests')->where('subject_type', 'child')->where('subject_id', $child->id)->orderByDesc('id')->get(['id', 'reference_no', 'category', 'status'])
                ->map(fn ($c) => (array) $c + ['category_label' => OrphanChanges::CATEGORY_LABELS[$c->category] ?? $c->category, 'status_label' => OrphanChanges::STATUS_LABELS[$c->status] ?? $c->status]),
            'rules' => ['education' => $this->forms->rules('education'), 'health' => $this->forms->rules('health'), 'child' => $this->forms->rules('child')],
            'options' => $this->forms->options(['enrollment_value_id', 'stage_value_id', 'health_status_value_id', 'disability_types', 'orphan_class_value_id', 'identity_type_value_id']),
            'sponsorship' => app(Sponsorships::class)->childStatus((int) $child->id, (int) $child->household_id) + [
                'list' => collect(app(SponsorshipController::class)->forHousehold($user, (int) $child->household_id))->where('child_public_id', $child->public_id)->values(),
                'savings' => $sensitive ? collect(app(SponsorshipAccounts::class)->savingsBalances((int) $child->id))->map(fn (int $cents) => FinanceMoney::format($cents))->all() : null],
            'changeCategories' => collect(OrphanChanges::FIELDS)->filter(fn ($subjects) => isset($subjects['child']))->keys()->mapWithKeys(fn ($k) => [$k => OrphanChanges::CATEGORY_LABELS[$k]]),
            'can' => $this->can($user) + ['sensitive' => $sensitive, 'override' => $this->access->isHead($user)],
        ]);
    }

    public function saveEducation(Request $request, string $publicId): RedirectResponse
    {
        $this->registry->saveEducation($request->user(), OrphanChild::where('public_id', $publicId)->firstOrFail(), $request->all());

        return back()->with('success', 'حُفظت بيانات العام الدراسي.');
    }

    public function saveHealth(Request $request, string $publicId): RedirectResponse
    {
        $this->registry->saveHealth($request->user(), OrphanChild::where('public_id', $publicId)->firstOrFail(), $request->all());

        return back()->with('success', 'سُجلت الحالة الصحية وحُفظت السابقة في السجل.');
    }

    public function uploadDocument(Request $request, OrphanDocuments $documents): RedirectResponse
    {
        $data = $request->validate(['owner_type' => ['required', 'in:child,household,guardian,father,mother'], 'owner_id' => ['required', 'integer'],
            'document_type_value_id' => ['required', 'integer'], 'file' => ['required', 'file', 'max:'.OrphanDocuments::MAX_KB],
            'document_no' => ['nullable', 'string', 'max:60'], 'issued_on' => ['nullable', 'date', 'before_or_equal:today'], 'expires_on' => ['nullable', 'date', 'after:issued_on']]);
        $this->assertOwnerExists($data['owner_type'], (int) $data['owner_id']);
        $documents->store($request->user(), $data['owner_type'], (int) $data['owner_id'], (int) $data['document_type_value_id'], $request->file('file'), $data);

        return back()->with('success', 'رُفع المستند وينتظر المراجعة.');
    }

    public function reviewDocument(Request $request, int $document, OrphanDocuments $documents): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:accepted,rejected'], 'note' => ['nullable', 'string', 'max:1000']]);
        $documents->review($request->user(), $document, $data['decision'], $data['note'] ?? null);

        return back()->with('success', $data['decision'] === 'accepted' ? 'قُبل المستند.' : 'رُفض المستند وسُجل السبب.');
    }

    /** A five-minute signed link, issued only to a user who may read this document. */
    public function documentLink(Request $request, int $document, OrphanDocuments $documents): JsonResponse
    {
        $row = DB::table('orphan_documents')->where('id', $document)->first();
        abort_unless($row && $documents->canRead($request->user(), $row), 403);

        return response()->json(['url' => URL::temporarySignedRoute('orphans.documents.download', now()->addMinutes(5), ['document' => $row->public_id])]);
    }

    public function downloadDocument(Request $request, string $document, OrphanDocuments $documents): HttpResponse
    {
        $row = DB::table('orphan_documents')->where('public_id', $document)->first();
        abort_unless($row && $documents->canRead($request->user(), $row), 403);
        $this->access->logRead($request->user(), 'document', (int) $row->id, 'downloaded');
        $file = $documents->read($row);

        return response($file['content'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'inline; filename="'.$file['name'].'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private', 'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'"]);
    }

    public function storePayment(Request $request, string $publicId): RedirectResponse
    {
        $user = $request->user();
        $this->access->assert($user, 'sponsorships');
        $household = OrphanHousehold::where('public_id', $publicId)->firstOrFail();
        $data = $request->validate(['channel_value_id' => ['required', 'integer', $this->forms->valueOf('payment_channels')],
            'institution_value_id' => ['nullable', 'integer', $this->forms->valueOf('banks')], 'branch' => ['nullable', 'string', 'max:120'],
            'account_holder' => ['required', 'string', 'min:3', 'max:150'], 'account_identifier' => ['required', 'string', 'regex:/^[A-Za-z0-9\- ]{4,40}$/']]);
        $identifier = preg_replace('/\s+/', '', $data['account_identifier']);
        DB::transaction(function () use ($user, $household, $data, $identifier): void {
            DB::table('orphan_payment_instructions')->where('household_id', $household->id)->where('status', 'pending_review')->lockForUpdate()->get();
            OrphanPaymentInstruction::create(['household_id' => $household->id, 'channel_value_id' => $data['channel_value_id'],
                'institution_value_id' => $data['institution_value_id'] ?? null, 'branch' => $data['branch'] ?? null, 'account_holder' => $data['account_holder'],
                'account_identifier' => $identifier, 'account_hash' => RegistryIdentity::hash($identifier), 'account_last4' => mb_substr($identifier, -4),
                'status' => 'pending_review', 'submitted_by' => $user->id]);
        });

        return back()->with('success', 'سُجلت بيانات الصرف وتنتظر اعتماد قسم الحسابات؛ لا يُصرف عليها قبل الاعتماد.');
    }

    public function openApplication(Request $request, string $publicId, OrphanApplications $applications): RedirectResponse
    {
        $id = $applications->openForHousehold($request->user(), OrphanHousehold::where('public_id', $publicId)->firstOrFail());

        return redirect()->route('orphans.applications.show', $id);
    }

    /** @return array<string, bool> */
    private function can($user): array
    {
        return ['records' => $this->access->allows($user, 'records'), 'verify' => $this->access->allows($user, 'verify'), 'visits' => $this->access->allows($user, 'visits'),
            'sponsorships' => $this->access->allows($user, 'sponsorships'), 'campaigns' => $this->access->allows($user, 'campaigns'),
            'head' => $this->access->isHead($user), 'executive' => $this->access->isExecutive($user), 'admin' => $user->isSystemAdmin()];
    }

    /** @return list<array{id: int, name: string}> */
    private function researchers(): array
    {
        return DB::table('users')->where('is_active', true)->where('department_id', $this->access->departmentId())->orderBy('name')->get(['id', 'name'])
            ->filter(fn ($u) => $this->access->allows(User::find($u->id), 'visits'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all();
    }

    private function assertOwnerExists(string $type, int $id): void
    {
        $table = match ($type) {
            'child' => 'orphan_children', 'household' => 'orphan_households', 'guardian' => 'guardians', default => 'orphan_parents',
        };
        abort_unless(DB::table($table)->where('id', $id)->exists(), 404);
    }

    private function mask(?string $value, bool $full): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $full ? $value : '•••'.mb_substr($value, -3);
    }

    private function decrypt(?string $cipher): ?string
    {
        try {
            return $cipher ? Crypt::decryptString($cipher) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
