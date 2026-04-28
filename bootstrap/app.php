<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\ResolveCurrentUser;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyEntraJwt;
use App\Support\Http\ExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters here:
        //   1. AssignRequestId  -- runs first so even rejected/errored
        //      responses get an id stamped on them and logs from any later
        //      middleware carry the id in their context.
        //   2. ForceJsonResponse -- so the framework's own exception
        //      renderer always picks the JSON branch.
        //   3. SecurityHeaders   -- prepended last (= runs latest on the
        //      response side) so its headers are not stripped by a later
        //      middleware.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(ForceJsonResponse::class);
        $middleware->append(SecurityHeaders::class);

        // Route-level aliases. Keep this list short and meaningful — these
        // are the names route definitions will refer to.
        $middleware->alias([
            'auth.jwt' => VerifyEntraJwt::class,
            'auth.user' => ResolveCurrentUser::class,
        ]);

        // Pin the execution order: our custom auth middlewares MUST run
        // before ThrottleRequests, otherwise the throttle:api_user limiter
        // resolves with no `auth.user` attached and falls back to per-IP
        // bucketing -- which would lump every request from the BFF (one
        // IP, many users) into a single bucket. Laravel's default priority
        // list does not know about our custom middleware, so without this
        // line ThrottleRequests sorts BEFORE both custom auth middlewares.
        $middleware->prependToPriorityList(
            Illuminate\Routing\Middleware\ThrottleRequests::class,
            VerifyEntraJwt::class,
        );
        $middleware->prependToPriorityList(
            Illuminate\Routing\Middleware\ThrottleRequests::class,
            ResolveCurrentUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Funnel every uncaught exception through ExceptionRenderer so the
        // wire shape matches `ApiErrorEnvelope` for every non-2xx response,
        // not just the ones the controllers emit explicitly.
        $exceptions->render(function (Throwable $e, Request $request) {
            // Only intercept JSON / API requests. Anything else falls back
            // to Laravel defaults -- in this service that path is unused
            // (web.php is empty) but it keeps the door open for future
            // browser-facing endpoints (e.g. an OpenAPI viewer).
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ExceptionRenderer::render($e, debug: config('app.debug') === true);
        });
    })->create();
