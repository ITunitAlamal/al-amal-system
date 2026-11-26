<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Services\Configuration\GazaGeography;
use App\Services\Orphans\Identity;
use App\Services\Orphans\Persons;
use App\Services\Orphans\PortalApplications;
use App\Services\Orphans\Registration\FamilyRegistrations;
use App\Services\Orphans\Registry;
use App\Services\Orphans\RegistryQueries;
use App\Services\Portal\ChangeRequests;
use App\Services\Portal\GuardianOtp;
use App\Services\Portal\PortalAid;
use App\Services\Portal\PortalNotifier;
use App\Services\Portal\PortalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The five authenticated areas of the beneficiary portal: الرئيسية، أسرتي، طلباتي، مساعداتي وكفالاتي، الإشعارات,
 * plus the profile menu. Every read is scoped by PortalScope. Payloads are built field by field, so no internal
 * note, score, ranking, other beneficiary or staff identity can be serialized by accident.
 */
final class PortalController extends Controller
{
    /** Portal-facing labels (the department keeps its own internal statuses). */
    public const REQUEST_STATUS = ['draft' => 'مسودة', 'submitted' => 'تم تقديم الطلب', 'under_review' => 'قيد المراجعة', 'resubmitted' => 'قيد المراجعة',
        'needs_completion' => 'بحاجة إلى استكمال البيانات', 'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب'];

    private const SAFE_EVENTS = ['submitted' => 'تم تقديم الطلب', 'resubmitted' => 'أُرسل الاستكمال', 'completion_requested' => 'طلب القسم استكمال البيانات',
        'account_approved' => 'اعتُمد حساب البوابة', 'account_rejected' => 'لم يُعتمد حساب البوابة', 'child_approved' => 'اعتُمدت الوصاية على طفل', 'child_rejected' => 'لم تُعتمد الوصاية على طفل',
        'completed' => 'اكتمل البت في الطلب', 'rejected' => 'رُفض الطلب', 'draft_created' => 'أُنشئت المسودة'];

    public function __construct(private readonly PortalScope $scope, private readonly PortalNotifier $notifier) {}

    private function account(): Guardian
    {
        return Auth::guard('guardian')->user();
    }

    // ---------------------------------------------------------------- الرئيسية

    public function home(): Response
    {
        $account = $this->account();
        $apps = DB::table('portal_applications')->where('guardian_account_id', $account->id)->where('source', 'portal')->whereNotIn('status', ['withdrawn'])->orderByDesc('id')->get();
        $actions = [];
        foreach ($apps as $a) {
            if ($a->status === 'draft') {
                $actions[] = ['label' => 'أكمل مسودة طلب التسجيل '.$a->reference_no.' وأرسلها', 'url' => '/guardian/registrations/'.$a->public_id, 'tone' => 'info'];
            } elseif ($a->status === 'needs_completion') {
                $actions[] = ['label' => 'مطلوب استكمال الطلب '.$a->reference_no, 'url' => '/guardian/registrations/'.$a->public_id, 'tone' => 'warning'];
            }
        }
        foreach (DB::table('portal_change_requests')->where('guardian_account_id', $account->id)->where('status', 'needs_info')->get(['reference_no']) as $c) {
            $actions[] = ['label' => 'مطلوب استكمال طلب التعديل '.$c->reference_no, 'url' => '/guardian/requests', 'tone' => 'warning'];
        }
        if (! $account->phone_verified_at && GuardianOtp::available()) {
            $actions[] = ['label' => 'أكّد رقم جوالك لتتمكن من استعادة كلمة المرور', 'url' => '/guardian/profile', 'tone' => 'info'];
        }

        return Inertia::render('Guardians/Home', [
            'account' => $this->accountCard($account), 'actions' => $actions,
            'childrenCount' => count($this->scope->orphanIds($account)), 'hasApplication' => $apps->isNotEmpty(),
            // The newest family request, for the stage timeline (the same card as «طلباتي»).
            'current' => ($latest = $apps->first()) ? [...$this->applicationCard($latest), 'children' => $this->claims($latest->id)] : null,
            'notices' => DB::table('portal_notifications')->where('guardian_id', $account->id)->orderByDesc('id')->limit(3)->get(['id', 'kind', 'title', 'created_at', 'read_at'])->map(fn ($n) => (array) $n),
        ]);
    }

    // ---------------------------------------------------------------- أسرتي

    public function family(): Response
    {
        $account = $this->account();
        $ids = $this->scope->orphanIds($account);
        $children = DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->whereIn('o.id', $ids ?: [0])->orderBy('p.date_of_birth')
            ->get(['o.public_id', 'o.reference_no', 'p.full_name', 'p.sex', 'p.date_of_birth'])
            ->map(fn ($c) => ['public_id' => $c->public_id, 'reference_no' => $c->reference_no, 'full_name' => $c->full_name, 'sex' => $c->sex, 'date_of_birth' => $c->date_of_birth])->all();
        $person = $account->person_id ? DB::table('persons')->find($account->person_id) : null;

        return Inertia::render('Guardians/Family', [
            'linked' => $this->scope->linked($account), 'account' => $this->accountCard($account),
            'profile' => $person ? ['full_name' => $person->full_name, 'id_masked' => Identity::mask($person->id_last4), 'phone' => $person->phone ?: $account->phone,
                'address' => $this->address(['person_id' => $person->id])] : null,
            'children' => $children,
            'households' => collect($this->scope->householdIds($account))->map(fn (int $id) => [
                'reference_no' => DB::table('households')->where('id', $id)->value('reference_no'),
                'current' => $this->address(['household_id' => $id], 'current'), 'original' => $this->address(['household_id' => $id], 'original'),
                'displaced' => DB::table('households')->where('id', $id)->value('displacement_status'),
            ])->all(),
        ]);
    }

    public function child(string $publicId): Response
    {
        $account = $this->account();
        $orphan = $this->scope->orphan($account, $publicId);
        $person = DB::table('persons')->find($orphan->person_id);
        $parents = DB::table('orphan_parent_links as l')->join('persons as p', 'p.id', '=', 'l.person_id')->where('l.orphan_id', $orphan->id)->get(['l.relation', 'p.full_name', 'p.vital_status'])
            ->mapWithKeys(fn ($r) => [$r->relation => ['name' => $r->full_name, 'status' => Persons::VITAL_LABELS[$r->vital_status] ?? 'غير محدد']]);
        $guardianship = DB::table('orphan_guardianships')->where('orphan_id', $orphan->id)->where('guardian_id', $this->scope->guardianId($account))->where('status', 'approved')->first(['starts_on', 'ends_on']);
        $household = DB::table('household_members')->where('person_id', $person->id)->whereNull('ends_on')->value('household_id');

        return Inertia::render('Guardians/Child', [
            'child' => ['public_id' => $orphan->public_id, 'reference_no' => $orphan->reference_no, 'full_name' => $person->full_name, 'sex' => $person->sex,
                'date_of_birth' => $person->date_of_birth, 'id_masked' => Identity::mask($person->id_last4), 'place_of_birth' => $person->place_of_birth],
            'parents' => $parents, 'guardianship' => $guardianship ? ['starts_on' => $guardianship->starts_on, 'ends_on' => $guardianship->ends_on] : null,
            'education' => DB::table('education_records as e')->leftJoin('master_data_values as s', 's.id', '=', 'e.stage_value_id')->leftJoin('master_data_values as st', 'st.id', '=', 'e.status_value_id')
                ->where('e.orphan_id', $orphan->id)->orderByDesc('e.id')->limit(3)->get(['e.academic_year', 'e.school_name', 'e.grade', 's.name_ar as stage', 'st.name_ar as status'])->map(fn ($r) => (array) $r),
            'residence' => $household ? ['current' => $this->address(['household_id' => $household], 'current'), 'original' => $this->address(['household_id' => $household], 'original')]
                : ['current' => $this->address(['person_id' => $person->id], 'current'), 'original' => null],
            'groups' => collect(ChangeRequests::groupsFor('child'))->mapWithKeys(fn ($g) => [$g => ChangeRequests::GROUPS[$g]]),
        ]);
    }

    // ---------------------------------------------------------------- طلب تعديل البيانات

    public function changeForm(Request $request): Response
    {
        $account = $this->account();
        abort_unless($this->scope->linked($account), 403, 'يتاح طلب تعديل السجلات الرسمية بعد اعتماد حسابك.');
        $orphan = $request->query('child') ? $this->scope->orphan($account, (string) $request->query('child')) : null;
        $subject = $orphan ? 'child' : 'guardian';

        return Inertia::render('Guardians/ChangeRequest', [
            'subject' => $subject, 'child' => $orphan ? ['public_id' => $orphan->public_id, 'name' => DB::table('persons')->where('id', $orphan->person_id)->value('full_name')] : null,
            'groups' => collect(ChangeRequests::groupsFor($subject))->mapWithKeys(fn ($g) => [$g => ChangeRequests::GROUPS[$g]]), 'group' => $request->query('group'),
            'options' => ['stages' => app(RegistryQueries::class)->values('education_stages'), 'enrollment' => app(RegistryQueries::class)->values('enrollment_statuses')],
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storeChange(Request $request, ChangeRequests $changes): RedirectResponse
    {
        $account = $this->account();
        $data = $request->validate(['child' => 'nullable|string|max:26', 'field_group' => ['required', Rule::in(array_keys(ChangeRequests::GROUPS))], 'proposed' => 'required|array',
            'proposed.*' => 'nullable', 'reason' => 'nullable|string|max:2000', 'idempotency_key' => 'required|uuid',
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png']], [], ['field_group' => 'نوع التعديل', 'reason' => 'السبب', 'file' => 'المرفق']);
        $orphan = filled($data['child'] ?? null) ? $this->scope->orphan($account, $data['child']) : null;
        $row = $changes->submit($account, $orphan, $data['field_group'], $data['proposed'], $data['reason'] ?? null, $data['idempotency_key']);
        if ($file = $request->file('file')) {
            if (! DB::table('registry_documents')->where('change_request_id', $row->id)->exists()) {
                DB::table('registry_documents')->insert(['public_id' => (string) Str::ulid(), 'change_request_id' => $row->id, 'target' => 'change', 'title' => 'مرفق طلب تعديل',
                    'path' => $file->store('portal-changes/'.now()->format('Y/m'), 'local'), 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
                    'is_restricted' => $data['field_group'] === 'health', 'uploaded_by_guardian' => $account->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return redirect()->route('guardian.requests')->with('success', 'أُرسل طلب التعديل '.$row->reference_no.'. لا يتغير السجل الرسمي قبل مراجعة القسم واعتماده.');
    }

    public function respondChange(Request $request, string $publicId, ChangeRequests $changes): RedirectResponse
    {
        $data = $request->validate(['note' => 'required|string|max:2000'], [], ['note' => 'الاستكمال']);
        $changes->respond($this->account(), $publicId, $data['note']);

        return back()->with('success', 'أُرسل الاستكمال.');
    }

    // ---------------------------------------------------------------- طلباتي

    public function requests(): Response
    {
        $account = $this->account();
        $applications = DB::table('portal_applications')->where('guardian_account_id', $account->id)->where('source', 'portal')->where('status', '<>', 'withdrawn')->orderByDesc('id')->get()
            ->map(fn ($a) => [...$this->applicationCard($a), 'children' => $this->claims($a->id)]);
        $changes = DB::table('portal_change_requests as c')->leftJoin('orphans as o', 'o.id', '=', 'c.orphan_id')->leftJoin('persons as p', 'p.id', '=', 'o.person_id')
            ->where('c.guardian_account_id', $account->id)->orderByDesc('c.id')->limit(50)
            ->get(['c.public_id', 'c.reference_no', 'c.field_group', 'c.status', 'c.public_note', 'c.created_at', 'c.decided_at', 'c.applied_at', 'p.full_name as child'])
            ->map(fn ($c) => ['public_id' => $c->public_id, 'reference_no' => $c->reference_no, 'group' => ChangeRequests::GROUPS[$c->field_group] ?? $c->field_group, 'status' => $c->status,
                'status_label' => ChangeRequests::STATUS[$c->status] ?? $c->status, 'response' => $c->public_note, 'created_at' => $c->created_at, 'decided_at' => $c->decided_at,
                'applied' => (bool) $c->applied_at, 'subject' => $c->child ?: 'بياناتي']);
        $appeals = DB::table('orphan_appeals as a')->leftJoin('orphans as o', 'o.id', '=', 'a.orphan_id')->leftJoin('persons as p', 'p.id', '=', 'o.person_id')
            ->where('a.filed_by_guardian', $account->id)->orderByDesc('a.id')->limit(50)->get(['a.reference_no', 'a.kind', 'a.status', 'a.reason', 'a.decision_note', 'a.created_at', 'p.full_name as child'])
            ->map(fn ($a) => ['reference_no' => $a->reference_no, 'kind' => self::APPEAL_KINDS[$a->kind] ?? $a->kind, 'status' => $a->status,
                'status_label' => ['open' => 'تم تقديم الاعتراض', 'under_review' => 'قيد المراجعة', 'accepted' => 'مقبول', 'rejected' => 'مرفوض'][$a->status] ?? $a->status,
                'reason' => $a->reason, 'response' => in_array($a->status, ['accepted', 'rejected'], true) ? $a->decision_note : null, 'created_at' => $a->created_at, 'subject' => $a->child ?: 'بياناتي']);
        $children = DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->whereIn('o.id', $this->scope->orphanIds($account) ?: [0])->get(['o.public_id', 'p.full_name'])
            ->map(fn ($c) => ['public_id' => $c->public_id, 'name' => $c->full_name]);

        return Inertia::render('Guardians/Requests', ['applications' => $applications, 'changes' => $changes, 'appeals' => $appeals, 'children' => $children,
            'linked' => $this->scope->linked($account), 'appealKinds' => self::APPEAL_KINDS, 'idempotencyKey' => (string) Str::uuid(),
            'canStart' => ! in_array($account->account_status, ['rejected', 'suspended'], true)]);
    }

    public function request(string $publicId): Response
    {
        $application = $this->scope->application($this->account(), $publicId);
        $events = DB::table('portal_application_events')->where('application_id', $application->id)->whereIn('action', array_keys(self::SAFE_EVENTS))->orderBy('id')
            ->get(['action', 'note', 'created_at', 'child_id'])->map(fn ($e) => ['label' => self::SAFE_EVENTS[$e->action], 'date' => $e->created_at,
                // Only messages written for the applicant: completion requests and rejection reasons.
                'message' => in_array($e->action, ['completion_requested', 'child_rejected', 'rejected'], true) ? $e->note : null,
                'child' => $e->child_id ? DB::table('portal_application_children')->where('id', $e->child_id)->value('full_name') : null]);

        return Inertia::render('Guardians/RequestShow', ['application' => [...$this->applicationCard($application), 'children' => $this->claims($application->id)], 'events' => $events]);
    }

    public const APPEAL_KINDS = ['eligibility' => 'اعتراض على الأهلية أو قرار', 'campaign_omission' => 'عدم الإدراج في حملة مساعدة', 'other' => 'أخرى'];

    /** An appeal opens a human review; it never changes an approved campaign list (only the executive director can). */
    public function storeAppeal(Request $request): RedirectResponse
    {
        $account = $this->account();
        abort_unless($this->scope->linked($account), 403, 'يتاح تقديم الاعتراض بعد اعتماد حسابك.');
        $data = $request->validate(['kind' => ['required', Rule::in(array_keys(self::APPEAL_KINDS))], 'child' => 'nullable|string|max:26', 'reason' => 'required|string|min:10|max:3000',
            'idempotency_key' => 'required|uuid'], [], ['kind' => 'نوع الاعتراض', 'reason' => 'سبب الاعتراض']);
        if (DB::table('orphan_appeals')->where('filed_by_guardian', $account->id)->where('idempotency_key', $data['idempotency_key'])->exists()) {
            return back()->with('success', 'اعتراضك مُرسل مسبقًا.');
        }
        $orphan = filled($data['child'] ?? null) ? $this->scope->orphan($account, $data['child']) : null;
        abort_if(DB::table('orphan_appeals')->where('filed_by_guardian', $account->id)->whereIn('status', ['open', 'under_review'])->count() >= 5, 422, 'لديك اعتراضات قيد المراجعة؛ انتظر الرد عليها.');
        $reference = Registry::reference('APL', 'orphan_appeal_seq');
        DB::table('orphan_appeals')->insert(['public_id' => (string) Str::ulid(), 'reference_no' => $reference, 'kind' => $data['kind'], 'orphan_id' => $orphan?->id,
            'guardian_id' => $orphan ? null : $this->scope->guardianId($account), 'filed_via' => 'portal', 'filed_by_guardian' => $account->id, 'reason' => $data['reason'],
            'status' => 'open', 'idempotency_key' => $data['idempotency_key'], 'created_at' => now(), 'updated_at' => now()]);
        $this->notifier->notify($account->id, 'info', 'استلمنا اعتراضك '.$reference, '/guardian/requests');

        return back()->with('success', 'أُرسل الاعتراض '.$reference.'. يراجعه القسم، ولا يغيّر الاعتراض أي كشف معتمد بنفسه.');
    }

    // ---------------------------------------------------------------- مساعداتي وكفالاتي

    public function aid(PortalAid $aid): Response
    {
        $account = $this->account();

        return Inertia::render('Guardians/Aid', ['linked' => $this->scope->linked($account), ...$aid->summary($account)]);
    }

    // ---------------------------------------------------------------- الإشعارات

    public function notifications(Request $request): Response
    {
        $account = $this->account();
        $filter = in_array($request->query('filter'), ['unread', 'action', 'decision', 'aid'], true) ? $request->query('filter') : 'all';
        $rows = DB::table('portal_notifications')->where('guardian_id', $account->id)
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))->when(in_array($filter, ['action', 'decision', 'aid'], true), fn ($q) => $q->where('kind', $filter))
            ->orderByDesc('id')->paginate(20, ['id', 'kind', 'title', 'body', 'url', 'read_at', 'created_at'])->withQueryString();

        return Inertia::render('Guardians/Notifications', ['rows' => $rows, 'filter' => $filter, 'kinds' => PortalNotifier::KINDS]);
    }

    /** Marks a notice read and opens its portal link (the target page authorizes again). */
    public function openNotification(int $id): RedirectResponse
    {
        $row = DB::table('portal_notifications')->where('id', $id)->where('guardian_id', $this->account()->id)->firstOrFail();
        DB::table('portal_notifications')->where('id', $row->id)->whereNull('read_at')->update(['read_at' => now()]);

        return redirect($row->url && str_starts_with($row->url, '/guardian') ? $row->url : '/guardian/notifications');
    }

    public function readAll(): RedirectResponse
    {
        DB::table('portal_notifications')->where('guardian_id', $this->account()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('success', 'عُلّمت كل الإشعارات كمقروءة.');
    }

    // ---------------------------------------------------------------- الملف الشخصي

    public function profile(): Response
    {
        $account = $this->account();

        return Inertia::render('Guardians/Profile', ['account' => [...$this->accountCard($account), 'phone' => $account->phone,
            'phone_verified' => (bool) $account->phone_verified_at, 'sms_consent' => (bool) $account->sms_consent_at, 'last_login_at' => $account->last_login_at?->toDateTimeString()],
            'smsAvailable' => GuardianOtp::available(), 'codeSent' => (bool) session('guardian.phone_code_sent')]);
    }

    public function sendPhoneCode(Request $request, GuardianOtp $otp): RedirectResponse
    {
        $account = $this->account();
        abort_if($account->phone_verified_at, 409, 'رقم الجوال مؤكد مسبقًا.');
        if (! $otp->send($account, 'phone_verify')) {
            return back()->withErrors(['code' => 'تعذر إرسال الرمز الآن. حاول لاحقًا، أو راجع القسم لتأكيد الرقم يدويًا.']);
        }
        $request->session()->flash('guardian.phone_code_sent', true);

        return back()->with('success', 'أُرسل رمز التأكيد إلى جوالك. أدخله خلال 10 دقائق.');
    }

    public function verifyPhone(Request $request, GuardianOtp $otp): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:10'], [], ['code' => 'الرمز']);
        $account = $this->account();
        if (! $otp->verify($account, 'phone_verify', $data['code'])) {
            return back()->withErrors(['code' => 'الرمز غير صحيح أو انتهت صلاحيته.'])->with('guardian.phone_code_sent', true);
        }
        $account->forceFill(['phone_verified_at' => now(), 'phone_verification_method' => 'sms', 'phone_verified_by' => null])->save();

        return back()->with('success', 'تم تأكيد رقم الجوال.');
    }

    public function preferences(Request $request): RedirectResponse
    {
        $data = $request->validate(['sms_consent' => 'required|boolean']);
        $this->account()->forceFill(['sms_consent_at' => $data['sms_consent'] ? now() : null])->save();

        return back()->with('success', 'حُفظت التفضيلات.');
    }

    /** Geography for the portal address pickers (same reference data as staff, through the portal guard). */
    public function geography(Request $request, GazaGeography $geography): JsonResponse
    {
        $input = $request->validate(['parent_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $geography->children(isset($input['parent_id']) ? (int) $input['parent_id'] : null)]);
    }

    // ---------------------------------------------------------------- helpers

    private function accountCard(Guardian $account): array
    {
        return ['name' => $account->name, 'status' => $account->account_status,
            'status_label' => ['pending' => 'بانتظار اعتماد القسم', 'approved' => 'معتمد', 'rejected' => 'غير معتمد', 'suspended' => 'موقوف'][$account->account_status] ?? '',
            'linked' => $this->scope->linked($account)];
    }

    private function applicationCard(object $a): array
    {
        $approved = DB::table('portal_application_children')->where('application_id', $a->id)->where('status', 'approved')->exists();
        $label = $a->status === 'completed' ? ($approved ? 'معتمد' : 'مرفوض') : (self::REQUEST_STATUS[$a->status] ?? $a->status);

        return ['public_id' => $a->public_id, 'reference_no' => $a->reference_no, 'status' => $a->status, 'status_label' => $label, 'editable' => in_array($a->status, FamilyRegistrations::EDITABLE, true),
            'submitted_at' => $a->submitted_at, 'last_saved_at' => $a->last_saved_at, 'completion_request' => $a->status === 'needs_completion' ? $a->completion_request : null,
            'rejection' => $a->status === 'rejected' ? $a->decision_note : null];
    }

    /** Each child claim with its own outcome; the reason is shown only when rejected (written for the applicant). */
    private function claims(int $applicationId): array
    {
        return DB::table('portal_application_children')->where('application_id', $applicationId)->where('status', '<>', 'withdrawn')->orderBy('id')->get(['full_name', 'status', 'decision_note'])
            ->map(fn ($c) => ['full_name' => $c->full_name, 'status' => $c->status, 'status_label' => PortalApplications::CHILD_STATUS[$c->status] ?? $c->status,
                'note' => $c->status === 'rejected' ? $c->decision_note : null])->all();
    }

    private function address(array $owner, string $kind = 'current'): ?array
    {
        $row = DB::table('registry_addresses as a')->leftJoin('master_data_values as g', 'g.id', '=', 'a.governorate_value_id')->leftJoin('master_data_values as l', 'l.id', '=', 'a.locality_value_id')
            ->leftJoin('master_data_values as r', 'r.id', '=', 'a.area_value_id')->where(collect($owner)->mapWithKeys(fn ($v, $k) => ['a.'.$k => $v])->all())->where('a.kind', $kind)->when($kind === 'current', fn ($q) => $q->whereNull('a.ends_on'))
            ->orderByDesc('a.id')->first(['g.name_ar as governorate', 'l.name_ar as city', 'r.name_ar as area', 'a.street', 'a.landmark', 'a.starts_on']);

        return $row ? ['text' => implode(' / ', array_filter([$row->governorate, $row->city, $row->area, $row->street])), 'landmark' => $row->landmark, 'since' => $row->starts_on] : null;
    }
}
