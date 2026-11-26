<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\GuardianApplication;
use App\Models\GuardianApplicationChild;
use App\Services\Orphans\GuardianRegistrationService;
use App\Services\Orphans\GuardianRegistryClient;
use App\Services\Orphans\RegistryIdentity;
use App\Services\Progress\GuardianApplicationProgress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GuardianPortalController extends Controller
{
    public function form(Request $request, GuardianRegistryClient $registry): Response|RedirectResponse
    {
        if (Auth::guard('guardian')->check()) {
            return redirect()->route('guardian.home');
        }

        return Inertia::render('Guardians/Auth', [
            'registering' => $request->routeIs('guardian.register'),
            'registryAvailable' => $registry->available(),
            'urls' => ['register' => route('guardian.register'), 'login' => route('guardian.login')],
        ]);
    }

    public function register(Request $request, GuardianRegistrationService $service): RedirectResponse
    {
        if (Auth::guard('guardian')->check()) {
            return redirect()->route('guardian.home');
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'national_id' => ['required', 'string', 'min:4', 'max:40'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/', 'unique:guardians,phone'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            ...$this->applicationRules(),
        ]);
        try {
            [$guardian, $application] = DB::transaction(function () use ($data, $service): array {
                $hash = RegistryIdentity::hash($data['national_id']);
                if (Guardian::where('national_id_hash', $hash)->exists()) {
                    throw ValidationException::withMessages(['national_id' => 'تعذر إنشاء الحساب بهذه البيانات؛ استخدم حسابك الموجود أو راجع القسم.']);
                }
                $guardian = Guardian::create([
                    'name' => $data['name'], 'national_id' => trim($data['national_id']),
                    'national_id_hash' => $hash, 'phone' => $data['phone'], 'password' => $data['password'],
                ]);

                return [$guardian->refresh(), $service->createApplication($guardian, $data['deceased_national_id'], $data['relationship'])];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'تعذر إنشاء الحساب بهذه البيانات؛ استخدم حسابك الموجود أو راجع القسم.']);
        }
        Auth::guard('guardian')->login($guardian);
        $request->session()->regenerate();
        $service->verify($application);

        return redirect()->route('guardian.home')->with('success', 'تم إنشاء حسابك وحفظ الطلب. الاعتماد وإضافة الأطفال ينتظران التحقق ومراجعة المشرف.');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20'], 'password' => ['required', 'string']]);
        if (! Auth::guard('guardian')->attempt(['phone' => $data['phone'], 'password' => $data['password'], 'is_active' => true])) {
            throw ValidationException::withMessages(['phone' => 'رقم الجوال أو كلمة المرور غير صحيحة، أو الحساب موقوف.']);
        }
        $request->session()->regenerate();

        return redirect()->route('guardian.home');
    }

    public function home(Request $request, GuardianRegistryClient $registry): Response
    {
        $guardian = Auth::guard('guardian')->user();

        return Inertia::render('Guardians/Home', [
            'applications' => $guardian->applications()->latest('id')->get()->map(fn ($app) => [
                'public_id' => $app->public_id, 'status' => $app->status, 'verification_status' => $app->verification_status,
                'relationship' => $app->relationship, 'deceased_suffix' => mb_substr($app->deceased_national_id, -4),
                'review_note' => $app->review_note, 'imported_children_count' => $app->imported_children_count,
                'submitted_at' => $app->created_at?->toIso8601String(),
                'progress' => app(GuardianApplicationProgress::class)->view($app, false),
                // Counts only: names and reasons of unverified children are not shown to the applicant (SRS-002 §8).
                'children_summary' => GuardianApplicationChild::where('guardian_application_id', $app->id)
                    ->where('verification_revision', $app->verification_revision)
                    ->selectRaw('result, count(*) as total')->groupBy('result')->pluck('total', 'result'),
            ]),
            'children' => $guardian->children()->orderBy('orphan_children.id')->get()->map(fn ($child) => [
                'public_id' => $child->public_id, 'full_name' => $child->full_name,
                'date_of_birth' => $child->date_of_birth->format('Y-m-d'),
            ]),
            'registryAvailable' => $registry->available(),
        ]);
    }

    public function createApplication(Request $request, GuardianRegistrationService $service): RedirectResponse
    {
        $data = $request->validate($this->applicationRules());
        $application = $service->createApplication(Auth::guard('guardian')->user(), $data['deceased_national_id'], $data['relationship']);
        $service->verify($application);

        return back()->with('success', 'تم حفظ الطلب وإرساله للتحقق.');
    }

    public function retry(Request $request, string $publicId, GuardianRegistrationService $service): RedirectResponse
    {
        $application = Auth::guard('guardian')->user()->applications()->where('public_id', $publicId)->firstOrFail();
        $service->verify($application);

        return back()->with('success', $application->fresh()->status === 'awaiting_api' ? 'خدمة التحقق غير متاحة؛ طلبك محفوظ بانتظار توفرها.' : 'اكتمل فحص الطلب.');
    }

    public function resubmit(Request $request, string $publicId, GuardianRegistrationService $service): RedirectResponse
    {
        $data = $request->validate(['relationship' => ['required', 'string', 'min:3', 'max:200']]);
        $application = DB::transaction(function () use ($publicId, $data, $service): GuardianApplication {
            $application = Auth::guard('guardian')->user()->applications()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($application->status !== 'needs_changes') {
                throw ValidationException::withMessages(['application' => 'الطلب ليس بانتظار استكمال.']);
            }
            $service->event($application, 'resubmitted', details: ['previous_relationship' => $application->relationship, 'new_relationship' => $data['relationship']]);
            $application->forceFill(['relationship' => $data['relationship'], 'status' => 'awaiting_api', 'verification_status' => 'unavailable', 'verification_payload' => null, 'verified_at' => null])->save();

            return $application;
        });
        $service->verify($application);

        return back()->with('success', 'تم إرسال الاستكمال للتحقق مجددًا.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('guardian')->logout();
        Inertia::clearHistory();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('guardian.login');
    }

    private function applicationRules(): array
    {
        return [
            'deceased_national_id' => ['required', 'string', 'min:4', 'max:40', 'different:national_id'],
            'relationship' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }
}
