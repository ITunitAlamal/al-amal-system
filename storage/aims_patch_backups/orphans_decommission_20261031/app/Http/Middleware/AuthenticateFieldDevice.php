<?php

namespace App\Http\Middleware;

use App\Services\Relief\FieldDevices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Field client authentication: a short-lived bearer token bound to one user and one approved device (fingerprint
 * header). Revoked devices, expired tokens and ended field assignments are refused at every call. In production the
 * API answers over TLS only.
 */
final class AuthenticateFieldDevice
{
    public function __construct(private readonly FieldDevices $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->secure()) {
            return response()->json(['message' => 'الاتصال المشفر (HTTPS) إلزامي.'], 426);
        }
        $session = $this->devices->authenticate($request->bearerToken(), $request->header('X-Device-Fingerprint'));
        if (! $session) {
            return response()->json(['message' => 'انتهت الجلسة أو أُلغي الجهاز أو التكليف؛ سجّل الدخول متصلًا من جديد.'], 401);
        }
        $request->attributes->set('field_session', $session);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
