<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeDigits;
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
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('guardian', 'guardian/*') ? route('guardian.login') : route('login'));
        $middleware->web(append: [
            NormalizeDigits::class,
            HandleInertiaRequests::class,
            EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['national_id', 'deceased_national_id', 'communication_password']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // A duplicate or out-of-range value that reached the database shows as a form message, not an error page.
        $exceptions->render(fn (QueryException $exception, Request $request) => DatabaseConflictResponse::from($exception, $request));
    })
    ->create();
