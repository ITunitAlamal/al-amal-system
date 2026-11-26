<?php

use App\Http\Middleware\AuditContext;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeDigits;
use App\Http\Middleware\PortalHostBoundary;
use App\Services\Audit\AuditDenials;
use App\Services\Validation\DatabaseConflictResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/field_api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Staff ERP and beneficiary portal hosts: separate cookies and routes (config/portal.php). Must run before the session.
        $middleware->prepend(PortalHostBoundary::class);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('guardian', 'guardian/*') ? route('guardian.welcome') : route('login'));
        $middleware->web(append: [
            NormalizeDigits::class,
            HandleInertiaRequests::class,
            EnsurePasswordChanged::class,
        ]);
        // «سجل النظام»: request context (origin, guard, route, correlation id) for every audit row of the request.
        $middleware->web(append: [AuditContext::class]);
        $middleware->api(append: [AuditContext::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['national_id', 'communication_password']);
        // Refused requests (403, throttled sign-in) are recorded in «سجل النظام»; the response itself is unchanged.
        $exceptions->render(function (Throwable $exception, Request $request) {
            AuditDenials::capture($exception, $request);

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // A duplicate or out-of-range value that reached the database shows as a form message, not an error page.
        $exceptions->render(fn (QueryException $exception, Request $request) => DatabaseConflictResponse::from($exception, $request));
    })
    ->create();
