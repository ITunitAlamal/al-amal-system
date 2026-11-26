<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Portal session gate: active account only; a temporary password must be replaced before anything else. */
final class EnsureGuardianActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard('guardian')->user();
        if (! $account?->is_active) {
            Auth::guard('guardian')->logout();
            abort(403, 'حساب الوصي غير فعال؛ راجع القسم.');
        }
        if ($account->must_change_password && ! $request->routeIs('guardian.password', 'guardian.password.update', 'guardian.logout')) {
            return redirect()->route('guardian.password');
        }

        return $next($request);
    }
}
