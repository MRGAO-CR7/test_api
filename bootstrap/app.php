<?php

declare(strict_types=1);

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\VerifyEntraJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request to this service is JSON. Force the Accept header so
        // that Laravel's exception handler returns JSON instead of HTML, even
        // when a client forgets to set it.
        $middleware->append(ForceJsonResponse::class);

        // Route-level aliases. Keep this list short and meaningful — these
        // are the names route definitions will refer to.
        $middleware->alias([
            'auth.jwt' => VerifyEntraJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Phase 6 will wire ApiErrorEnvelope-shaped responses here.
    })->create();
