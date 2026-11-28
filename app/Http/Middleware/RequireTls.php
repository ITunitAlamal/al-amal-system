<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In production a stateless API answers over TLS only, before anything in the request is read: the field sign-in
 * carries the staff password, so a plain-HTTP request is refused (426) without checking it.
 */
final class RequireTls
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->secure()) {
            return response()->json(['message' => 'الاتصال المشفر (HTTPS) إلزامي.'], 426);
        }

        return $next($request);
    }
}
