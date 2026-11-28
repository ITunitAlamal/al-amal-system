<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every audit row of this request its context: origin (staff/portal/api), guard, route name, method and a
 * correlation id (the caller's X-Request-Id when it is a safe token, otherwise a new ULID, echoed back). It is stored
 * in the session setting `aims.audit_ctx` that the audit trigger reads; no query string, body or cookie is kept.
 *
 * The origin and guard follow the route, not whoever else is signed in to the same session (AC-04): a portal path
 * (/guardian…) is the guardian's, a field API path is a device's, every other path is staff. Only a portal request
 * carries the guardian id the trigger uses for rows written without an actor.
 */
final class AuditContext
{
    /** Same portal paths as PortalHostBoundary. */
    private const PORTAL_PATHS = ['guardian', 'guardian/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $correlation = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) ? $incoming : (string) Str::ulid();
        $request->attributes->set('correlation_id', $correlation);
        $portal = $request->is(...self::PORTAL_PATHS);
        $api = $request->is('api/*');
        $context = [
            'o' => $portal ? 'portal' : ($api ? 'api' : 'staff'),
            'g' => match (true) {
                $portal => 'guardian',
                $api => $request->is('api/field/*') ? 'field_device' : null,
                default => Auth::guard('web')->check() ? 'web' : null,
            },
            'gid' => $portal ? Auth::guard('guardian')->id() : null,
            'r' => $request->route()?->getName(),
            'm' => $request->method(),
            'c' => $correlation,
        ];
        try {
            DB::select("SELECT set_config('aims.audit_ctx', ?, false)", [json_encode($context, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable) {
            // The audit trigger falls back to defaults; a missing context never blocks the request.
        }
        $response = $next($request);
        $response->headers->set('X-Request-Id', $correlation);

        return $response;
    }
}
