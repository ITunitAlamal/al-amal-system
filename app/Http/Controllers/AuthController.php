<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Audit\AuthAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * عرض صفحة تسجيل الدخول.
     */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /**
     * تسجيل الدخول باستخدام الرقم الوظيفي وكلمة المرور.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'employee_number' => [
                'required',
                'string',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
            ],
        ]);

        $authenticated = Auth::attempt([
            'employee_number' => trim(
                $credentials['employee_number']
            ),
            'password' => $credentials['password'],
            'is_active' => true,
        ]);

        if (! $authenticated) {
            app(AuthAudit::class)->staffLoginFailed($request, (string) $credentials['employee_number']);

            throw ValidationException::withMessages([
                'employee_number' => 'الرقم الوظيفي أو كلمة المرور غير صحيحة، أو الحساب غير مفعّل.',
            ]);
        }

        // One session never holds a staff and a beneficiary sign-in together (AC-04): a portal session open in the
        // same browser ends here, recorded as the guardian's sign-out. Guard and origin are written explicitly
        // (the trigger keeps them): this request's context is staff, the row is a portal one.
        if ($guardianId = Auth::guard('guardian')->id()) {
            DB::table('audit_logs')->insert(['actor_id' => null, 'actor_guardian_id' => (int) $guardianId, 'action' => 'portal.auth.logout', 'subject_type' => 'guardians',
                'subject_id' => (string) $guardianId, 'guard' => 'guardian', 'origin' => 'portal', 'reason_code' => 'staff_sign_in', 'sensitivity' => 'normal',
                'metadata' => '{}', 'ip_address' => $request->ip(), 'created_at' => now(), 'updated_at' => now()]);
            Auth::guard('guardian')->logout();
        }

        // تجديد الجلسة بعد نجاح المصادقة.
        $request->session()->regenerate();

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'auth.login',
            'ip_address' => $request->ip(),
        ]);

        // الحسابات التي تتطلب تغيير كلمة المرور
        // لا تستطيع تجاوز صفحة التغيير.
        if (Auth::user()->must_change_password) {
            return redirect()->route('password.change');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * تسجيل الخروج وإنهاء الجلسة.
     */
    public function logout(Request $request): Response
    {
        if (Auth::check()) {
            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'auth.logout',
                'ip_address' => $request->ip(),
            ]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(route('login'));
    }
}
