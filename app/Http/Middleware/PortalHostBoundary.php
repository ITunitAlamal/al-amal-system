<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host boundary between the staff ERP and the beneficiary portal (runs before the session starts).
 *
 * On the portal host: own session cookie (host-only), only portal paths are served, «/» opens the portal.
 * On the staff host: portal paths are not served. Hosts come from config/portal.php; unset = single-host mode.
 */
final class PortalHostBoundary
{
    private const PORTAL_PATHS = ['guardian', 'guardian/*', 'build/*', 'images/*', 'favicon.ico', 'robots.txt', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $portal = config('portal.host');
        $staff = config('portal.staff_host');
        $host = strtolower($request->getHost());

        if ($portal && $host === strtolower($portal)) {
            config(['session.cookie' => config('portal.session_cookie'), 'session.domain' => null]);
            if ($request->path() === '/' || $request->path() === '') {
                return redirect('/guardian');
            }
            abort_unless($request->is(...self::PORTAL_PATHS), 404);
        } elseif ($portal && $staff && $host === strtolower($staff) && $request->is('guardian', 'guardian/*')) {
            abort(404);
        }

        return $next($request);
    }
}
