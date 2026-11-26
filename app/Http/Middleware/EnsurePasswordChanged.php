<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('guardian', 'guardian/*')) {
            return $next($request);
        }
        $user = $request->user();

        // Authentication is handled separately by the auth middleware.
        if (! $user || ! (bool) $user->must_change_password) {
            return $next($request);
        }

        // Permit password change and logout. These names must match routes/web.php.
        if ($request->routeIs('password.change', 'password.change.update', 'logout')) {
            return $next($request);
        }

        // Avoid redirect loops if route names are changed unintentionally.
        if ($request->is('change-password') && $request->isMethod('GET')) {
            return $next($request);
        }

        if ($request->is('change-password') && $request->isMethod('PUT')) {
            return $next($request);
        }

        // For JSON/API requests, return an actionable error instead of HTML redirects.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'يجب تغيير كلمة المرور المؤقتة قبل متابعة استخدام النظام.',
                'change_password_url' => route('password.change'),
            ], 403);
        }

        return redirect()->route('password.change');
    }
}
