<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\Identity;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\Persons;
use App\Services\Orphans\PortalApplications;
use App\Services\Orphans\Registration\FamilyPromotion;
use App\Services\Orphans\Registration\FamilyRegistrations;
use App\Services\Orphans\Registration\RegistrationForm;
use App\Services\Orphans\Registry;
use App\Services\Orphans\RegistryQueries;
use App\Services\Orphans\SensitiveChanges;
use App\Services\Portal\ChangeRequests;
use App\Services\Portal\PortalNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «الطلبات والمراجعات»: portal applications in one assignable queue, the review workspace, and appeals. */
final class ReviewsController extends Controller
{
    /** @var list<int>|null */
    private ?array $heads = null;

    /** @var list<int>|null */
    private ?array $executives = null;

    public function __construct(private readonly OrphanAccess $access, private readonly PortalApplications $applications, private readonly Persons $persons,
        private readonly RegistryQueries $queries, private readonly AuditService $audit, private readonly NotificationCenter $notifications) {}

    public function index(Request $request): Response
    {
        $this->access->assertView($request->user());
        $tab = $request->query('tab') === 'appeals' ? 'appeals' : 'applications';
        $filters = array_filter(['status' => $request->query('status'), 'assigned' => $request->query('assigned'), 'q' => trim((string) $request->query('q', ''))]);
        $user = $request->user();

        return Inertia::render('Orphans/Reviews/Index', [
            'tab' => $tab, 'filters' => $filters, 'abilities' => $this->access->abilities($user), 'staff' => $this->access->staff(),
            'applications' => $tab === 'applications' ? $this->applicationQuery($filters, $user) : null,
            'appeals' => $tab === 'appeals' ? $this->appealQuery($filters, $user) : null,
            'statuses' => PortalApplications::STATUS,
            'counts' => ['open' => DB::table('portal_applications')->whereIn('status', ['submitted', 'under_review', 'resubmitted'])->count(),
                'mine' => DB::table('portal_applications')->where('assigned_to', $user->id)->whereNotIn('status', ['draft', 'completed', 'rejected', 'withdrawn'])->count(),
                'unassigned' => DB::table('portal_applications')->whereNull('assigned_to')->whereNotIn('status', ['draft', 'completed', 'rejected', 'withdrawn'])->count(),
                'appeals' => DB::table('orphan_appeals')->whereIn('status', ['open', 'under_review'])->count(),
                'sensitive' => app(SensitiveChanges::class)->pendingCount()],
        ]);
    }

    /** «التعديلات الحساسة»: staff changes to approved files, old and new values, decided by the head (ORP-18). */
    public function sensitive(Request $request, SensitiveChanges $changes): Response
    {
        $this->access->assertView($request->user());
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected', 'all'], true) ? $request->query('status') : 'pending';
        $rows = DB::table('orphan_sensitive_changes as c')->leftJoin('orphans as o', 'o.id', '=', 'c.orphan_id')
            ->leftJoin('persons as p', fn ($j) => $j->on('p.id', '=', DB::raw('COALESCE(c.person_id, o.person_id)')))
            ->leftJoin('users as r', 'r.id', '=', 'c.requested_by')->leftJoin('users as d', 'd.id', '=', 'c.decided_by')
            ->when($status !== 'all', fn ($q) => $q->where('c.status', $status))
            ->orderByRaw("CASE c.status WHEN 'pending' THEN 0 ELSE 1 END")->orderByDesc('c.id')
            ->select(['c.*', 'p.full_name as subject_name', 'r.name as requester', 'd.name as decider'])
            ->selectSub(DB::table('orphans as x')->whereColumn('x.person_id', 'p.id')->select('x.public_id')->limit(1), 'child_public_id')
            ->selectSub(DB::table('orphan_guardians as g')->whereColumn('g.person_id', 'p.id')->select('g.public_id')->limit(1), 'guardian_public_id')
            ->addSelect(['o.public_id as file_public_id', 'o.reference_no as orphan_reference'])
            ->paginate(20)->withQueryString();
        $rows->through(function ($row) use ($changes, $request): array {
            $row->orphan_public_id = $row->file_public_id ?? $row->child_public_id;

            return $changes->present($row, $request->user());
        });

        return Inertia::render('Orphans/Reviews/Sensitive', ['rows' => $rows, 'status' => $status, 'abilities' => $this->access->abilities($request->user())]);
    }

    /** Full identity numbers of one sensitive change on explicit request («identity»); every reveal is logged. */
    public function revealSensitiveId(Request $request, string $publicId, SensitiveChanges $changes): JsonResponse
    {
        return response()->json($changes->revealIdentity($publicId, $request->user()));
    }

    public function decideSensitive(Request $request, string $publicId, SensitiveChanges $changes): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => 'nullable|string|max:2000'], [], ['note' => 'سبب القرار']);
        $row = $changes->decide($publicId, $data['decision'], $data['note'] ?? null, $request->user());

        return back()->with('success', $data['decision'] === 'approve' ? 'اعتُمد الطلب '.$row->reference_no.' وطُبّق التعديل على السجل.' : 'رُفض الطلب '.$row->reference_no.' وبقي السجل كما هو.');
    }

    public function show(Request $request, string $publicId): Response
    {
        $this->access->assertView($request->user());
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $this->access->logRead($request->user(), 'portal_application', $app->id);
        $account = DB::table('guardians')->where('id', $app->guardian_account_id)->first(['id', 'phone', 'is_active', 'account_status', 'created_at', 'person_id', 'phone_verified_at', 'phone_verification_method']);
        $guardian = $app->matched_guardian_id ? DB::table('orphan_guardians as gg')->join('persons as p', 'p.id', '=', 'gg.person_id')->where('gg.id', $app->matched_guardian_id)
            ->first(['gg.public_id', 'gg.reference_no', 'p.full_name', 'p.first_name', 'p.father_name', 'p.grandfather_name', 'p.family_name', 'p.id_last4', 'p.phone']) : null;
        $children = DB::table('portal_application_children as c')->leftJoin('orphans as o', 'o.id', '=', 'c.matched_orphan_id')->leftJoin('persons as op', 'op.id', '=', 'o.person_id')
            ->leftJoin('master_data_values as k', 'k.id', '=', 'c.kinship_value_id')->leftJoin('users as u', 'u.id', '=', 'c.decided_by')->where('c.application_id', $app->id)->orderBy('c.id')
            ->where('c.status', '<>', 'withdrawn')
            ->get(['c.id', 'c.card_key', 'c.guardian_name', 'c.first_name', 'c.father_name', 'c.grandfather_name', 'c.family_name', 'c.full_name', 'c.id_last4', 'c.date_of_birth', 'c.sex', 'c.relationship_note', 'c.status',
                'c.decision_note', 'c.decided_at', 'c.guardianship_id', 'k.name_ar as kinship', 'u.name as decided_by_name', 'o.public_id as orphan_public_id', 'o.reference_no as orphan_reference',
                'op.full_name as orphan_name', 'op.date_of_birth as orphan_dob'])
            ->map(function ($c) use ($app): array {
                $others = DB::table('orphan_guardianships as g')->join('orphan_guardians as gg', 'gg.id', '=', 'g.guardian_id')->join('persons as p', 'p.id', '=', 'gg.person_id')
                    ->join('orphans as o', 'o.id', '=', 'g.orphan_id')->where('o.reference_no', $c->orphan_reference)->whereIn('g.status', ['claimed', 'pending', 'approved'])
                    ->when($app->matched_guardian_id, fn ($q) => $q->where('g.guardian_id', '<>', $app->matched_guardian_id))->get(['p.full_name', 'g.status'])->map(fn ($r) => (array) $r)->all();

                return [...(array) $c, 'id_masked' => Identity::mask($c->id_last4), 'status_label' => PortalApplications::CHILD_STATUS[$c->status] ?? $c->status,
                    'other_guardians' => $others, 'name_matches' => $c->orphan_reference ? [] : $this->persons->duplicates((array) $c)];
            });

        // Family request snapshot (reported answers), with health only for the «health» capability.
        $form = FamilyRegistrations::form($app);
        $mayHealth = $this->access->allows($request->user(), 'health');
        $snapshot = $form ? [
            'form' => $mayHealth ? $form : FamilyRegistrations::withoutHealth($form), 'canHealth' => $mayHealth, 'targets' => FamilyRegistrations::targets($form),
            'reconciliation' => RegistrationForm::reconciliation($form), 'conflicts' => app(FamilyPromotion::class)->conflicts($app, $form),
            'missingDocuments' => RegistrationForm::missingDocuments($form, FamilyRegistrations::documents($app->id)), 'options' => FamilyRegistrations::options(),
        ] : null;

        return Inertia::render('Orphans/Reviews/Show', [
            'snapshot' => $snapshot,
            'application' => [...collect((array) $app)->except(['id_number', 'id_hash', 'id', 'guardian_account_id', 'form_data', 'promotion'])->all(), 'id_masked' => Identity::mask($app->id_last4),
                'status_label' => PortalApplications::STATUS[$app->status] ?? $app->status, 'checklist' => json_decode((string) $app->checklist, true) ?: [],
                'assignee' => $app->assigned_to ? DB::table('users')->where('id', $app->assigned_to)->value('name') : null],
            'account' => $account ? ['phone' => $account->phone, 'is_active' => (bool) $account->is_active, 'status' => $account->account_status, 'created_at' => $account->created_at,
                'phone_verified' => (bool) $account->phone_verified_at, 'phone_method' => $account->phone_verification_method] : null,
            'guardian' => $guardian ? [...(array) $guardian, 'id_masked' => Identity::mask($guardian->id_last4)] : null,
            'applicantMatches' => $guardian ? [] : $this->persons->duplicates((array) $app),
            'children' => $children,
            'checklistItems' => $this->queries->values('portal_review_checklist'),
            'documents' => DB::table('registry_documents as d')->leftJoin('master_data_values as t', 't.id', '=', 'd.document_type_value_id')->where('d.application_id', $app->id)
                ->when(! $mayHealth, fn ($q) => $q->where('d.is_restricted', false))
                ->orderBy('d.id')->get(['d.public_id', 'd.title', 'd.original_name', 'd.review_status', 'd.created_at', 't.name_ar as type'])->map(fn ($d) => (array) $d),
            'events' => DB::table('portal_application_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')->where('e.application_id', $app->id)->orderByDesc('e.id')
                ->get(['e.action', 'e.note', 'e.created_at', 'e.child_id', 'u.name as actor', 'e.actor_guardian_id'])->map(fn ($e) => (array) $e),
            'me' => $request->user()->id, 'abilities' => $this->access->abilities($request->user()), 'staff' => $this->access->staff(), 'options' => ['authority' => $this->queries->values('guardian_authority_kinds')],
            // Set when this user entered the request or is its applicant/guardian: the page then offers no review action.
            'reviewConflict' => $this->applications->reviewConflict($app, $request->user()),
        ]);
    }

    /** «طلبات تعديل البيانات» from the portal: proposal against current values, then a decision. */
    public function changes(Request $request): Response
    {
        $this->access->assertView($request->user());
        $status = in_array($request->query('status'), ['open', 'approved', 'rejected', 'all'], true) ? $request->query('status') : 'open';
        $rows = DB::table('portal_change_requests as c')->join('guardians as a', 'a.id', '=', 'c.guardian_account_id')->leftJoin('orphans as o', 'o.id', '=', 'c.orphan_id')
            ->leftJoin('persons as p', 'p.id', '=', 'o.person_id')->leftJoin('users as u', 'u.id', '=', 'c.decided_by')
            ->when($status === 'open', fn ($q) => $q->whereIn('c.status', ['submitted', 'under_review', 'needs_info']))->when(in_array($status, ['approved', 'rejected'], true), fn ($q) => $q->where('c.status', $status))
            ->orderByRaw("CASE c.status WHEN 'submitted' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END")->orderBy('c.id')->paginate(20, ['c.*', 'a.name as account_name', 'o.public_id as orphan_public_id',
                'o.reference_no as orphan_reference', 'p.full_name as child', 'u.name as decided_by_name'])->withQueryString();
        $mayHealth = $this->access->allows($request->user(), 'health');
        $rows->through(fn ($c) => ['public_id' => $c->public_id, 'reference_no' => $c->reference_no, 'account' => $c->account_name, 'child' => $c->child, 'orphan_public_id' => $c->orphan_public_id,
            'orphan_reference' => $c->orphan_reference, 'group' => $c->field_group, 'group_label' => ChangeRequests::GROUPS[$c->field_group] ?? $c->field_group,
            'status' => $c->status, 'status_label' => ChangeRequests::STATUS[$c->status] ?? $c->status, 'reason' => $c->reason, 'created_at' => $c->created_at,
            'restricted' => $c->field_group === 'health' && ! $mayHealth,
            'proposed' => $c->field_group === 'health' && ! $mayHealth ? [] : ChangeRequests::proposed($c), 'current' => ChangeRequests::snapshot($c),
            'auto' => in_array($c->field_group, ChangeRequests::AUTO_APPLY, true), 'public_note' => $c->public_note, 'internal_note' => $c->internal_note,
            'decided_by' => $c->decided_by_name, 'decided_at' => $c->decided_at, 'applied_at' => $c->applied_at,
            'documents' => DB::table('registry_documents')->where('change_request_id', $c->id)->when(! $mayHealth, fn ($q) => $q->where('is_restricted', false))->get(['public_id', 'original_name'])->map(fn ($d) => (array) $d)]);

        return Inertia::render('Orphans/Reviews/Changes', ['rows' => $rows, 'status' => $status, 'abilities' => $this->access->abilities($request->user())]);
    }

    public function decideChange(Request $request, string $publicId, ChangeRequests $changes): RedirectResponse
    {
        $row = DB::table('portal_change_requests')->where('public_id', $publicId)->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['review', 'needs_info', 'approve', 'reject'])], 'public_note' => 'nullable|string|max:2000', 'internal_note' => 'nullable|string|max:3000'],
            [], ['public_note' => 'الرد على الوصي', 'internal_note' => 'الملاحظة الداخلية']);
        $changes->decide($row, $data['action'], $data['public_note'] ?? null, $data['internal_note'] ?? null, $request->user());

        return back()->with('success', match ($data['action']) {
            'approve' => in_array($row->field_group, ChangeRequests::AUTO_APPLY, true) ? 'اعتُمد الطلب وطُبق التعديل على السجل.' : 'اعتُمد الطلب. طبّق التعديل من ملف اليتيم أو الأسرة.',
            'reject' => 'رُفض الطلب مع إبلاغ الوصي بالسبب.', 'needs_info' => 'أُرسل طلب الاستكمال للوصي.', default => 'استلمت الطلب للمراجعة.',
        });
    }

    /**
     * Account support from the department: confirm the phone after a real call (manual verification, recorded), or
     * issue a one-time temporary password (returned once in the response, never stored or logged in clear).
     */
    public function accountTools(Request $request, string $publicId): JsonResponse
    {
        $this->access->assert($request->user(), 'portal');
        $app = DB::table('portal_applications')->where('public_id', $publicId)->where('source', 'portal')->firstOrFail();
        $data = $request->validate(['action' => ['required', Rule::in(['verify_phone', 'temporary_password'])]]);
        $account = Guardian::findOrFail($app->guardian_account_id);
        if ($data['action'] === 'verify_phone') {
            $account->forceFill(['phone_verified_at' => now(), 'phone_verification_method' => 'manual', 'phone_verified_by' => $request->user()->id])->save();
            $this->audit->recordRow('orphans.portal.phone_verified_manually', 'guardians', $account->id, null, ['application' => $app->reference_no], $request->user()->id, $request->ip());

            return response()->json(['message' => 'سُجل تأكيد رقم الجوال يدويًا باسمك.']);
        }
        $temporary = Str::password(12, symbols: false);
        // An open session of this account is held at the password page: changing it needs the temporary password.
        $account->forceFill(['password' => $temporary, 'must_change_password' => true, 'remember_token' => null])->save();
        $this->audit->recordRow('orphans.portal.temporary_password', 'guardians', $account->id, null, ['application' => $app->reference_no], $request->user()->id, $request->ip());

        return response()->json(['message' => 'سلّم كلمة المرور المؤقتة لصاحب الحساب شخصيًا. سيُطلب منه تغييرها عند الدخول.', 'password' => $temporary]);
    }

    public function assign(Request $request, string $publicId): RedirectResponse
    {
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $data = $request->validate(['user_id' => 'nullable|integer|exists:users,id', 'due_on' => 'nullable|date']);
        $this->applications->assign($app, $data['user_id'] ?? null, $request->user());
        if (array_key_exists('due_on', $data) && $this->access->canAssign($request->user())) {
            DB::table('portal_applications')->where('id', $app->id)->update(['due_on' => $data['due_on'], 'updated_at' => now()]);
        }

        return back()->with('success', 'تم تحديث الإسناد.');
    }

    public function checklist(Request $request, string $publicId): RedirectResponse
    {
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $data = $request->validate(['checklist' => 'array', 'checklist.*' => 'boolean', 'review_notes' => 'nullable|string|max:5000']);
        $this->applications->saveChecklist($app, $data['checklist'] ?? [], $request->user());
        if (array_key_exists('review_notes', $data)) {
            DB::table('portal_applications')->where('id', $app->id)->update(['review_notes' => $data['review_notes'], 'updated_at' => now()]);
        }

        return back()->with('success', 'تم حفظ قائمة الفحص.');
    }

    public function account(Request $request, string $publicId): RedirectResponse
    {
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => 'nullable|string|max:2000']);
        $this->applications->decideAccount($app, $data['decision'], $data['note'] ?? null, $request->user());

        return back()->with('success', $data['decision'] === 'approve' ? 'تم اعتماد الحساب. لا يُفتح أي ملف طفل إلا بوصاية معتمدة له.' : 'تم رفض الحساب.');
    }

    public function child(Request $request, string $publicId, int $child): RedirectResponse
    {
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $row = DB::table('portal_application_children')->where('id', $child)->where('application_id', $app->id)->firstOrFail();
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => 'nullable|string|max:2000', 'starts_on' => 'nullable|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on', 'authority_kind_value_id' => 'nullable|integer|exists:master_data_values,id', 'legal_basis' => 'nullable|string|max:300',
            'orphan_public_id' => 'nullable|string|exists:orphans,public_id', 'evidence_note' => 'nullable|string|max:2000', 'resolve_conflicts' => 'nullable|boolean']);
        if ($data['decision'] === 'reject') {
            if (blank($data['note'] ?? null)) {
                return back()->withErrors(['note' => 'سبب الرفض مطلوب.']);
            }
            $this->applications->rejectChild($row, $data['note'], $request->user());

            return back()->with('success', 'تم رفض ادعاء الوصاية لهذا الطفل.');
        }
        if (! empty($data['orphan_public_id'])) {
            $data['orphan_id'] = DB::table('orphans')->where('public_id', $data['orphan_public_id'])->value('id');
        }
        $this->applications->approveChild($row, $data, $request->user());

        return back()->with('success', 'تم اعتماد الوصاية لهذا الطفل.');
    }

    public function completion(Request $request, string $publicId): RedirectResponse
    {
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $data = $request->validate(['message' => 'required|string|max:2000', 'children' => 'array', 'children.*' => 'integer'], [], ['message' => 'نص طلب الاستكمال']);
        $this->applications->requestCompletion($app, $data['message'], $data['children'] ?? [], $request->user());

        return back()->with('success', 'أُرسل طلب الاستكمال للوصي.');
    }

    public function reject(Request $request, string $publicId): RedirectResponse
    {
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $data = $request->validate(['note' => 'required|string|max:2000'], [], ['note' => 'سبب الرفض']);
        $this->applications->rejectApplication($app, $data['note'], $request->user());

        return back()->with('success', 'تم رفض الطلب مع حفظ السبب.');
    }

    public function revealApplicantId(Request $request, string $publicId): JsonResponse
    {
        $this->access->assert($request->user(), 'identity');
        $app = DB::table('portal_applications')->where('public_id', $publicId)->whereNotNull('submitted_at')->firstOrFail();
        $this->access->logRead($request->user(), 'portal_application', $app->id, 'id_revealed');

        return response()->json(['id_number' => $app->id_number ? decrypt($app->id_number) : null]);
    }

    public function storeAppeal(Request $request): RedirectResponse
    {
        $this->access->assertEdit($request->user());
        $data = $request->validate(['kind' => ['required', Rule::in(['eligibility', 'campaign_omission', 'other'])], 'subject' => ['required', Rule::in(['orphan', 'guardian'])],
            'subject_public_id' => 'required|string|max:40', 'campaign_id' => 'nullable|integer', 'reason' => 'required|string|max:3000', 'evidence_note' => 'nullable|string|max:2000']);
        $subject = $data['subject'] === 'orphan' ? ['orphan_id' => DB::table('orphans')->where('public_id', $data['subject_public_id'])->value('id')]
            : ['guardian_id' => DB::table('orphan_guardians')->where('public_id', $data['subject_public_id'])->value('id')];
        abort_unless(array_values($subject)[0], 422, 'صاحب الاعتراض غير موجود في السجل.');
        if (! empty($data['campaign_id']) && RegistryQueries::hasTable('campaigns')) {
            abort_unless(DB::table('campaigns')->where('id', $data['campaign_id'])->exists(), 422, 'الحملة غير موجودة.');
        }
        $id = DB::table('orphan_appeals')->insertGetId(['public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('APL', 'orphan_appeal_seq'), 'kind' => $data['kind'], ...$subject,
            'campaign_id' => $data['campaign_id'] ?? null, 'filed_via' => 'staff', 'filed_by_user' => $request->user()->id, 'reason' => $data['reason'], 'evidence_note' => $data['evidence_note'] ?? null,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.appeal.filed', 'orphan_appeals', $id, null, ['kind' => $data['kind']], $request->user()->id, $request->ip());
        $this->notifications->notifyMany($this->access->headUserIds(), 'orphans_appeal_update', ['title' => 'اعتراض جديد بحاجة إلى مراجعة', 'url' => '/orphans/reviews?tab=appeals', 'dedupe' => 'appeal-'.$id]);

        return back()->with('success', 'تم تسجيل الاعتراض. قبوله لا يعدّل أي كشف معتمد دون موافقة المدير التنفيذي.');
    }

    public function decideAppeal(Request $request, string $publicId): RedirectResponse
    {
        $user = $request->user();
        // The executive director reads the department (no edit role) yet decides an appeal the head escalated to him.
        $this->access->assertView($user);
        $data = $request->validate(['action' => ['required', Rule::in(['review', 'accept', 'reject', 'assign', 'escalate'])], 'note' => 'nullable|string|max:3000', 'user_id' => 'nullable|integer|exists:users,id']);
        $result = DB::transaction(function () use ($publicId, $data, $user): array {
            $appeal = DB::table('orphan_appeals')->where('public_id', $publicId)->whereIn('status', ['open', 'under_review'])->lockForUpdate()->firstOrFail();
            $executive = null;
            $update = match ($data['action']) {
                // Taking an appeal for review records the reviewer; it gives no right to decide it.
                'review' => $this->access->canEdit($user) ? ['status' => 'under_review', ...($appeal->assigned_to ? [] : ['assigned_to' => $user->id, 'assigned_by' => $user->id])]
                    : abort(403, 'مراجعة الاعتراض لموظفي القسم.'),
                'assign' => $this->access->canAssign($user) ? ['assigned_to' => $data['user_id'] ?? null, 'assigned_by' => ($data['user_id'] ?? null) ? $user->id : null] : abort(403, 'الإسناد لرئيس القسم.'),
                'escalate' => $this->escalation($user, $data['note'] ?? null, $executive),
                default => $this->decision($appeal, $user, $data['action'], $data['note'] ?? null),
            };
            DB::table('orphan_appeals')->where('id', $appeal->id)->update([...$update, 'updated_at' => now()]);

            return [$appeal, $executive];
        });
        [$appeal, $executive] = $result;
        if ($appeal->filed_by_guardian && in_array($data['action'], ['accept', 'reject'], true)) {
            // The decision text is the reply shown to the beneficiary in «طلباتي».
            app(PortalNotifier::class)->notify((int) $appeal->filed_by_guardian, 'decision', 'صدر قرار في اعتراضك '.$appeal->reference_no, '/guardian/requests');
        }
        if ($executive) {
            $this->notifications->notifyMany([$executive], 'orphans_appeal_update', ['title' => 'اعتراض صعّده إليك رئيس قسم الأيتام: '.$appeal->reference_no, 'body' => (string) $data['note'],
                'url' => '/orphans/reviews?tab=appeals&assigned=me', 'dedupe' => 'appeal-escalated-'.$appeal->id]);
        }
        $this->audit->recordRow('orphans.appeal.'.$data['action'], 'orphan_appeals', $appeal->id, null, ['note' => $data['note'] ?? null,
            ...($executive ? ['escalated_to' => $executive] : [])], $user->id, $request->ip());

        return back()->with('success', match ($data['action']) {
            'accept' => 'قُبل الاعتراض. أي تعديل على كشف معتمد يحتاج طلب تعديل يعتمده المدير التنفيذي.',
            'escalate' => 'صُعّد الاعتراض إلى المدير التنفيذي ليفصل فيه.', default => 'تم تحديث الاعتراض.',
        });
    }

    /** The head hands the appeal to the designated executive director, with the reason. */
    private function escalation(User $user, ?string $note, ?int &$executive): array
    {
        $this->access->assertDecide($user, 'تصعيد الاعتراض إلى المدير التنفيذي لرئيس القسم.');
        abort_if(blank($note), 422, 'اكتب سبب التصعيد.');
        $executive = $this->access->executiveUserIds()[0] ?? null;
        abort_unless($executive, 409, 'لا يوجد مدير تنفيذي معيّن لتصعيد الاعتراض إليه.');

        return ['status' => 'under_review', 'assigned_to' => $executive, 'assigned_by' => $user->id];
    }

    private function decision(object $appeal, User $user, string $action, ?string $note): array
    {
        $who = $this->appealDecider($appeal, $user);
        abort_if($who['conflict'] !== null, 403, $who['conflict'] ?? '');
        abort_unless($who['may'], 403, 'يفصل في الاعتراض رئيس القسم، أو من أسنده إليه رئيس القسم (ومنهم المدير التنفيذي عند التصعيد).');
        abort_if(blank($note), 422, 'نص القرار مطلوب.');

        return ['status' => $action === 'accept' ? 'accepted' : 'rejected', 'decision_note' => $note, 'decided_by' => $user->id, 'decided_at' => now()];
    }

    /**
     * Who may accept or reject an appeal (req-04 §12): the head, or the person the head assigned it to (the executive
     * director when the head escalated it). Nobody decides an appeal against a campaign list they prepared or an
     * orphan file they entered by hand; the head escalates such an appeal instead.
     *
     * @return array{may: bool, conflict: ?string}
     */
    private function appealDecider(object $appeal, User $user): array
    {
        $assignedBy = (int) ($appeal->assigned_by ?? 0);
        $byHead = (int) $appeal->assigned_to === (int) $user->id && $assignedBy > 0 && $assignedBy !== (int) $user->id
            && in_array($assignedBy, $this->heads ??= $this->access->headUserIds(), true);
        if (! $this->access->canDecide($user) && ! $byHead) {
            return ['may' => false, 'conflict' => null];
        }
        $conflict = null;
        if ($appeal->campaign_id && DB::table('campaign_lists')->where('campaign_id', $appeal->campaign_id)->where('prepared_by', $user->id)->exists()) {
            $conflict = 'أعددتَ كشف الحملة موضوع الاعتراض، فلا تفصل فيه؛ يصعّده رئيس القسم إلى المدير التنفيذي.';
        } elseif ($appeal->orphan_id && DB::table('orphans')->where('id', $appeal->orphan_id)->where('registration_source', 'staff')->where('created_by', $user->id)->exists()) {
            $conflict = 'أدخلتَ ملف اليتيم موضوع الاعتراض، فلا تفصل فيه؛ يصعّده رئيس القسم إلى المدير التنفيذي.';
        }

        return ['may' => $conflict === null, 'conflict' => $conflict];
    }

    private function applicationQuery(array $filters, $user)
    {
        $query = DB::table('portal_applications as a')->whereNotNull('a.submitted_at')->leftJoin('users as u', 'u.id', '=', 'a.assigned_to')
            ->select(['a.public_id', 'a.reference_no', 'a.full_name', 'a.id_last4', 'a.status', 'a.submitted_at', 'a.due_on', 'a.assigned_to', 'u.name as assignee'])
            ->selectSub(DB::table('portal_application_children as c')->whereColumn('c.application_id', 'a.id')->selectRaw('count(*)'), 'children_count')
            ->selectSub(DB::table('portal_application_children as c')->whereColumn('c.application_id', 'a.id')->where('c.status', 'approved')->selectRaw('count(*)'), 'approved_count');
        $status = $filters['status'] ?? 'open';
        $status === 'open' ? $query->whereIn('a.status', ['submitted', 'under_review', 'needs_completion', 'resubmitted']) : ($status === 'all' ? null : $query->where('a.status', $status));
        match ($filters['assigned'] ?? null) {
            'me' => $query->where('a.assigned_to', $user->id), 'none' => $query->whereNull('a.assigned_to'), default => null,
        };
        if (! empty($filters['q'])) {
            $clean = Identity::clean($filters['q']);
            ctype_digit($clean) && $clean !== '' ? $query->where('a.id_hash', Identity::hash($clean))
                : $query->where(fn ($q) => $q->where('a.reference_no', 'ilike', addcslashes($filters['q'], '%_\\').'%')->orWhere('a.full_name', 'ilike', '%'.addcslashes($filters['q'], '%_\\').'%'));
        }

        // Overdue first, then the oldest submission (no automated vulnerability ranking).
        return $query->orderByRaw('CASE WHEN a.due_on IS NOT NULL AND a.due_on < CURRENT_DATE THEN 0 ELSE 1 END')->orderByRaw("CASE a.status WHEN 'resubmitted' THEN 0 WHEN 'submitted' THEN 1 ELSE 2 END")
            ->orderBy('a.submitted_at')->paginate(25)->withQueryString()->through(fn ($r) => [...(array) $r, 'id_masked' => Identity::mask($r->id_last4), 'status_label' => PortalApplications::STATUS[$r->status] ?? $r->status,
                'overdue' => $r->due_on && $r->due_on < today()->toDateString()]);
    }

    private function appealQuery(array $filters, $user)
    {
        $query = DB::table('orphan_appeals as a')->leftJoin('orphans as o', 'o.id', '=', 'a.orphan_id')->leftJoin('persons as op', 'op.id', '=', 'o.person_id')
            ->leftJoin('orphan_guardians as g', 'g.id', '=', 'a.guardian_id')->leftJoin('persons as gp', 'gp.id', '=', 'g.person_id')->leftJoin('users as u', 'u.id', '=', 'a.assigned_to')
            ->select(['a.public_id', 'a.reference_no', 'a.kind', 'a.status', 'a.reason', 'a.decision_note', 'a.campaign_id', 'a.created_at', 'u.name as assignee',
                'a.assigned_to', 'a.assigned_by', 'a.orphan_id',
                DB::raw('COALESCE(op.full_name, gp.full_name) as subject_name'), 'o.public_id as orphan_public_id', 'g.public_id as guardian_public_id']);
        $status = $filters['status'] ?? 'open';
        $status === 'open' ? $query->whereIn('a.status', ['open', 'under_review']) : ($status === 'all' ? null : $query->where('a.status', $status));
        if (($filters['assigned'] ?? null) === 'me') {
            $query->where('a.assigned_to', $user->id);
        }
        $head = $this->access->canDecide($user);

        // Per-row flags so the page offers only what the server accepts (decisions, conflicts, escalation by the head).
        return $query->orderBy('a.id')->paginate(25)->withQueryString()->through(function ($r) use ($user, $head): array {
            $open = in_array($r->status, ['open', 'under_review'], true);
            $who = $open ? $this->appealDecider($r, $user) : ['may' => false, 'conflict' => null];

            return [...collect((array) $r)->except(['assigned_by', 'orphan_id'])->all(), 'can_decide' => $who['may'], 'conflict' => $who['conflict'],
                'can_escalate' => $open && $head, 'escalated' => $r->assigned_to && in_array((int) $r->assigned_to, $this->executives ??= $this->access->executiveUserIds(), true)];
        });
    }
}
