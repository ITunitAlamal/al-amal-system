<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\AuditLog;

class AuthController extends Controller
{
    public function showLogin() { return view('auth.login'); }
    public function login(Request $request)
    {
        $credentials = $request->validate(['email'=>['required','email'],'password'=>['required','string']]);
        if (Auth::attempt([...$credentials, 'is_active'=>true])) {
            $request->session()->regenerate();
            AuditLog::create(['actor_id'=>Auth::id(),'action'=>'auth.login','ip_address'=>$request->ip()]);
            return redirect()->intended(route('dashboard'));
        }
        return back()->withErrors(['email'=>'البريد الإلكتروني أو كلمة المرور غير صحيحة'])->onlyInput('email');
    }
    public function logout(Request $request)
    {
        AuditLog::create(['actor_id'=>Auth::id(),'action'=>'auth.logout','ip_address'=>$request->ip()]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
