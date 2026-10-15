<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'employee_number' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt([
            'employee_number' => $credentials['employee_number'],
            'password' => $credentials['password'],
            'is_active' => true,
        ])) {
            $request->session()->regenerate();

            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'auth.login',
                'ip_address' => $request->ip(),
            ]);

            return redirect()->intended(route('dashboard'));
        }

        return back()
            ->withErrors([
                'employee_number' =>
                    'اسم المستخدم أو كلمة المرور غير صحيحة',
            ])
            ->onlyInput('employee_number');
    }

    public function logout(Request $request)
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'auth.logout',
            'ip_address' => $request->ip(),
        ]);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
