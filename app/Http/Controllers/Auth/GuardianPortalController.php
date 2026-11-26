<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orphans\FamilyRegistrationRequest;
use App\Models\Guardian;
use App\Services\Audit\AuthAudit;
use App\Services\Orphans\Identity;
use App\Services\Orphans\PortalApplications;
use App\Services\Orphans\Registration\FamilyRegistrations;
use App\Services\Portal\GuardianOtp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Beneficiary portal (guardians). An account starts «بانتظار الاعتماد»; it can submit applications, but sees a child
 * only through an approved, current guardianship decided by the department for that child.
 */
final class GuardianPortalController extends Controller
{
    public function __construct(private readonly PortalApplications $applications, private readonly FamilyRegistrations $registrations) {}

    /** Public welcome page of the portal (portal host «/» lands here for guests). */
    public function welcome(): Response|RedirectResponse
    {
        if (Auth::guard('guardian')->check()) {
            return redirect()->route('guardian.home');
        }

        return Inertia::render('Guardians/Welcome', ['contact' => json_decode((string) DB::table('orphan_settings')->where('key', 'portal_contact_text')->value('value'), true)]);
    }

    public function form(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('guardian')->check()) {
            return redirect()->route('guardian.home');
        }

        return Inertia::render('Guardians/Auth', ['registering' => $request->routeIs('guardian.register'),
            'urls' => ['login' => route('guardian.login'), 'register' => route('guardian.register'), 'forgot' => route('guardian.password.forgot')]]);
    }

    /** «نسيت كلمة المرور»: the same answer whether or not an account matches (no enumeration). */
    public function forgotForm(Request $request): Response
    {
        return Inertia::render('Guardians/Recover', ['step' => $request->session()->has('guardian.reset_phone') ? 'code' : 'request', 'smsAvailable' => GuardianOtp::available(),
            'phone' => $request->session()->get('guardian.reset_phone')]);
    }

    public function forgotSend(Request $request, GuardianOtp $otp): RedirectResponse
    {
        $data = $request->validate(['national_id' => 'required|string|max:20', 'phone' => 'required|string|max:20'], [], ['national_id' => 'رقم الهوية', 'phone' => 'رقم الجوال']);
        $phone = Identity::clean($data['phone']);
        $account = Guardian::query()->where('national_id_hash', Identity::hash(Identity::clean($data['national_id'])))->where('phone', $phone)->where('is_active', true)->first();
        // Codes go only to a phone already verified for this account; anything else gets the same message.
        if ($account && $account->phone_verified_at) {
            $otp->send($account, 'password_reset');
        }
        $request->session()->put('guardian.reset_phone', $phone);

        return redirect()->route('guardian.password.forgot')->with('success', 'إن كانت البيانات مطابقة لحساب برقم جوال مؤكد، ستصلك رسالة برمز الاستعادة خلال دقائق. إن لم تصلك، راجع القسم لإعادة التعيين يدويًا.');
    }

    public function resetPassword(Request $request, GuardianOtp $otp): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:10', 'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]], [], ['code' => 'الرمز', 'password' => 'كلمة المرور']);
        $phone = $request->session()->get('guardian.reset_phone');
        $account = $phone ? Guardian::query()->where('phone', $phone)->where('is_active', true)->whereNotNull('phone_verified_at')->first() : null;
        if (! $account || ! $otp->verify($account, 'password_reset', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح أو انتهت صلاحيته. اطلب رمزًا جديدًا.']);
        }
        $account->forceFill(['password' => $data['password'], 'must_change_password' => false, 'remember_token' => null])->save();
        app(AuthAudit::class)->guardianPasswordChanged($request, (int) $account->id, 'sms_reset');
        $request->session()->forget('guardian.reset_phone');
        $request->session()->regenerate();

        return redirect()->route('guardian.login')->with('success', 'تم تغيير كلمة المرور. سجّل الدخول بكلمة المرور الجديدة.');
    }

    public function restartRecovery(Request $request): RedirectResponse
    {
        $request->session()->forget('guardian.reset_phone');

        return redirect()->route('guardian.password.forgot');
    }

    public function passwordForm(): Response
    {
        return Inertia::render('Guardians/Password', ['forced' => (bool) Auth::guard('guardian')->user()->must_change_password]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $account = Auth::guard('guardian')->user();
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)->letters()->numbers()]],
            [], ['current_password' => 'كلمة المرور الحالية', 'password' => 'كلمة المرور الجديدة']);
        if (! Hash::check($data['current_password'], $account->password)) {
            throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);
        }
        $account->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        app(AuthAudit::class)->guardianPasswordChanged($request, (int) $account->id, 'self_change');
        $request->session()->regenerate();

        return redirect()->route('guardian.home')->with('success', 'تم تغيير كلمة المرور.');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100', 'father_name' => 'required|string|max:100', 'grandfather_name' => 'nullable|string|max:100', 'family_name' => 'required|string|max:100',
            'national_id' => 'required|string|max:20', 'phone' => ['required', 'string', 'regex:/^\+?[0-9٠-٩]{8,15}$/u'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()], 'declaration' => 'accepted', 'sms_consent' => 'nullable|boolean',
        ], ['declaration.accepted' => 'يلزم الإقرار بصحة البيانات والموافقة على مراجعتها.'],
            ['first_name' => 'الاسم الأول', 'father_name' => 'اسم الأب', 'family_name' => 'اسم العائلة', 'national_id' => 'رقم الهوية', 'phone' => 'رقم الجوال', 'password' => 'كلمة المرور']);
        [$format, $clean] = Identity::classify($data['national_id'], null);
        if ($format !== 'valid_format') {
            throw ValidationException::withMessages(['national_id' => 'رقم الهوية الفلسطينية يتكون من 9 أرقام صحيحة.']);
        }
        $phone = Identity::clean($data['phone']);
        $hash = Identity::hash($clean);
        if (Guardian::query()->where('national_id_hash', $hash)->orWhere('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['national_id' => 'تعذر إنشاء الحساب بهذه البيانات؛ استخدم حسابك الموجود أو راجع القسم.']);
        }
        try {
            $account = Guardian::create(['name' => implode(' ', array_filter([$data['first_name'], $data['father_name'], $data['grandfather_name'] ?? null, $data['family_name']])),
                'national_id' => $clean, 'national_id_hash' => $hash, 'phone' => $phone, 'password' => $data['password']]);
            $account->forceFill(['sms_consent_at' => ! empty($data['sms_consent']) ? now() : null, 'last_login_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['national_id' => 'تعذر إنشاء الحساب بهذه البيانات؛ استخدم حسابك الموجود أو راجع القسم.']);
        }
        $request->session()->put('guardian.name_parts', collect($data)->only(['first_name', 'father_name', 'grandfather_name', 'family_name'])->all());
        Auth::guard('guardian')->login($account);
        $request->session()->regenerate();

        return redirect()->route('guardian.home')->with('success', 'تم إنشاء حسابك. قدّم الآن طلب تسجيل الأطفال الذين ترعاهم؛ يراجع القسم الحساب وكل طفل على حدة.');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20'], 'password' => ['required', 'string']]);
        if (! Auth::guard('guardian')->attempt(['phone' => Identity::clean($data['phone']), 'password' => $data['password'], 'is_active' => true])) {
            app(AuthAudit::class)->guardianLoginFailed($request, Identity::clean($data['phone']));

            throw ValidationException::withMessages(['phone' => 'رقم الجوال أو كلمة المرور غير صحيحة، أو الحساب موقوف.']);
        }
        $request->session()->regenerate();
        $account = Auth::guard('guardian')->user();
        $account->forceFill(['last_login_at' => now()])->save();
        app(AuthAudit::class)->guardianLogin($request, (int) $account->id);

        return $account->must_change_password ? redirect()->route('guardian.password') : redirect()->route('guardian.home');
    }

    /** Opens a new family registration draft (partial saves allowed; nothing reaches the department before submission). */
    public function storeApplication(): RedirectResponse
    {
        $application = $this->registrations->createDraft(Auth::guard('guardian')->user(), null);

        return redirect()->route('guardian.registrations.edit', $application->public_id);
    }

    public function editRegistration(string $publicId): Response
    {
        $account = Auth::guard('guardian')->user();
        $application = $this->ownApplication($publicId);

        return Inertia::render('Guardians/Registration', $this->registrations->page($application, true, [
            'save' => route('guardian.registrations.update', $publicId), 'submit' => route('guardian.registrations.submit', $publicId),
            'documents' => route('guardian.registrations.documents', $publicId), 'discard' => route('guardian.registrations.discard', $publicId), 'back' => route('guardian.home'),
        ]) + ['account' => ['name' => $account->name, 'phone' => $account->phone, 'id_masked' => Identity::mask(mb_substr(Identity::clean($account->national_id), -4))]]);
    }

    /** Draft save (autosave sends JSON and gets the saved time and version back). */
    public function updateRegistration(FamilyRegistrationRequest $request, string $publicId): RedirectResponse|JsonResponse
    {
        $saved = $this->registrations->save($this->ownApplication($publicId), $this->portalData($request), false, Auth::guard('guardian')->user(), null,
            $request->integer('version') ?: null, $request->integer('last_step'));

        return $request->expectsJson() ? response()->json(FamilyRegistrations::savedState($saved)) : back()->with('success', 'حُفظت المسودة. يمكنك إكمالها لاحقًا.');
    }

    public function submitRegistration(FamilyRegistrationRequest $request, string $publicId): RedirectResponse
    {
        $current = $this->ownApplication($publicId);
        // A repeated «إرسال» (double tap, retry after a lost connection) never submits twice.
        if ($current->submitted_at && ! in_array($current->status, FamilyRegistrations::EDITABLE, true)) {
            return redirect()->route('guardian.requests')->with('success', 'الطلب '.$current->reference_no.' مُرسل مسبقًا وهو قيد المعالجة.');
        }
        $application = $this->registrations->save($current, $this->portalData($request), true, Auth::guard('guardian')->user(), null, $request->integer('version') ?: null);

        return redirect()->route('guardian.requests')->with('success', 'أُرسل الطلب '.$application->reference_no.'. يراجع القسم حسابك وكل طفل على حدة؛ الإرسال ليس اعتمادًا للوصاية.');
    }

    public function uploadDocument(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(FamilyRegistrations::uploadRules(), [], FamilyRegistrations::uploadAttributes());
        $this->registrations->attach($this->ownApplication($publicId), $request->file('file'), $data['document_type_value_id'] ?? null, $data['target'], Auth::guard('guardian')->user(), null);

        return back()->with('success', 'أُرفق المستند.');
    }

    public function removeDocument(string $publicId, string $document): RedirectResponse
    {
        $this->registrations->detach($this->ownApplication($publicId), $document, Auth::guard('guardian')->user(), null);

        return back()->with('success', 'حُذف المرفق.');
    }

    public function discardRegistration(string $publicId): RedirectResponse
    {
        $this->registrations->discard($this->ownApplication($publicId), Auth::guard('guardian')->user(), null);

        return redirect()->route('guardian.home')->with('success', 'حُذفت المسودة.');
    }

    private function ownApplication(string $publicId): object
    {
        return DB::table('portal_applications')->where('public_id', $publicId)->where('source', 'portal')->where('guardian_account_id', Auth::guard('guardian')->id())->firstOrFail();
    }

    /** The applicant of a portal request is the account holder: identity and phone come from the account, not the form. */
    private function portalData(FamilyRegistrationRequest $request): array
    {
        $account = Auth::guard('guardian')->user();
        $data = $request->validated();
        $data['request']['applicant'] = [...($data['request']['applicant'] ?? []), 'id_number' => $account->national_id, 'id_type_value_id' => null, 'phone' => $account->phone];

        return $data;
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($id = Auth::guard('guardian')->id()) {
            app(AuthAudit::class)->guardianLogout($request, (int) $id);
        }
        Auth::guard('guardian')->logout();
        Inertia::clearHistory();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('guardian.welcome');
    }
}
