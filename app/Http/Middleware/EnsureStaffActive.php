<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff session lasts only while its account is active: once an account is deactivated, its next request signs it
 * out (the session is invalidated), so an open browser keeps no access until the session would expire. The sign-in
 * itself already refuses inactive accounts (AuthController).
 */
final class EnsureStaffActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('guardian', 'guardian/*')) {
            return $next($request);
        }
        $user = Auth::guard('web')->user();
        if ($user === null || $user->is_active) {
            return $next($request);
        }

        try {
            app(AuditService::class)->event('auth.session_ended_inactive', ['actor_id' => (int) $user->id, 'subject_type' => 'users',
                'subject_id' => $user->id, 'outcome' => 'denied', 'reason_code' => 'account_inactive', 'ip' => $request->ip()]);
        } catch (\Throwable) {
            // Signing the session out must not depend on the audit row.
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->header('X-Inertia')) {
            return Inertia::location(route('login'));
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => 'تم إيقاف هذا الحساب، وانتهت جلسته.'], 401);
        }

        return redirect()->route('login')->withErrors(['employee_number' => 'تم إيقاف هذا الحساب، وانتهت جلسته.']);
    }
}
