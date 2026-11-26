<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmployeeActivationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Public activation is proof-of-possession of the SMS OTP, not authentication.
 * The employee number alone never activates an account or reveals its status.
 * Public resends are limited and can only send to the employee's stored phone.
 */
class EmployeeActivationController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/EmployeeActivation', [
            'employeeNumber' => (string) $request->query('employee_number', ''),
        ]);
    }

    public function activate(Request $request, EmployeeActivationService $activation): HttpResponse
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'code' => ['required', 'regex:/^[0-9]{6}$/D'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ]);

        $key = $this->rateKey('activate', $request, $data['employee_number']);
        $ipKey = 'employee-activate-ip:'.hash('sha256', $request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 25)) {
            throw ValidationException::withMessages([
                'code' => 'محاولات كثيرة. يرجى الانتظار قليلًا قبل المحاولة مجددًا.',
            ]);
        }
        RateLimiter::hit($key, 300);
        RateLimiter::hit($ipKey, 3600);

        $user = User::query()->where('employee_number', $data['employee_number'])->first();
        if (! $user || $user->is_active) {
            throw ValidationException::withMessages([
                'code' => 'تعذر تأكيد الرمز أو أن الحساب غير مؤهل للتفعيل.',
            ]);
        }

        $activation->activate($user, $data['code'], $data['password']);

        RateLimiter::clear($key);

        // Do not authenticate automatically; use the established login flow.
        $request->session()->flash('success', 'تم تفعيل الحساب بنجاح. يمكنك تسجيل الدخول برقمك الوظيفي وكلمة المرور التي اخترتها.');

        return Inertia::location(route('login'));
    }

    public function resend(Request $request, EmployeeActivationService $activation): RedirectResponse
    {
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/D'],
        ]);

        $key = $this->rateKey('resend', $request, $data['employee_number']);
        $ipKey = 'employee-resend-ip:'.hash('sha256', $request->ip() ?? 'unknown');
        if (RateLimiter::tooManyAttempts($key, 3) || RateLimiter::tooManyAttempts($ipKey, 10)) {
            throw ValidationException::withMessages([
                'employee_number' => 'تم تجاوز عدد الطلبات المسموح به. حاول لاحقًا.',
            ]);
        }
        RateLimiter::hit($key, 3600);
        RateLimiter::hit($ipKey, 3600);

        // A public caller cannot select a phone number or user ID.
        $user = User::query()->where('employee_number', $data['employee_number'])->first();
        if ($user && ! $user->is_active) {
            try {
                $activation->sendCode($user);
            } catch (Throwable $exception) {
                // Do not disclose account existence, phone, OTP, or gateway errors.
                // Fail closed for internal errors; an operator can inspect non-sensitive metrics.
            }
        }

        return back()->with('success',
            'إذا كان الحساب بانتظار التفعيل ومؤهلًا للإرسال، فسيُرسل رمز جديد إلى الجوال الأساسي المسجل.'
        );
    }

    private function rateKey(string $action, Request $request, string $number): string
    {
        return 'employee-'.$action.':'.hash('sha256',
            Str::lower(trim($number)).'|'.($request->ip() ?? 'unknown')
        );
    }
}
