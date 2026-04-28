<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ReadinessController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes — namespaced under /api/v1/test/*
|--------------------------------------------------------------------------
|
| Mounted under /api by withRouting(api: ..., apiPrefix: 'api') in
| bootstrap/app.php, so the URLs below resolve to /api/v1/test/*.
|
| URL layout choice:
|   - `v1`   — test_api's own version, under our control. Future breaking
|              changes ship under /api/v2/test/* alongside this group.
|   - `test` — namespace segment that says "this is the test_api surface".
|              Useful if/when this service ever has to live behind the same
|              ingress as another Laravel API (no path collisions). It also
|              lets the BFF mount a single catch-all at /api/test/[...slug]
|              and forward every request through `${TEST_API_URL}/<slug>`
|              where TEST_API_URL points at /api/v1/test.
|
| Conventions:
|   - One controller per resource. No closures in routes.
|   - Authenticated routes sit inside `['auth.jwt', 'auth.user', 'throttle:api_user']`.
|     `auth.jwt` verifies the token; `auth.user` JIT-creates / touches the
|     local users row and attaches the model to the request; `throttle:api_user`
|     applies a per-uuid rate-limit (see RateLimitServiceProvider).
|   - Public probes use `throttle:public` so monitoring agents can poll
|     liberally without affecting the user-facing limiter.
|
*/

Route::prefix('v1/test')->name('v1.')->group(function (): void {
    Route::middleware('throttle:public')->group(function (): void {
        Route::get('/health', HealthController::class)->name('health');
        Route::get('/ready', ReadinessController::class)->name('ready');
    });

    Route::middleware(['auth.jwt', 'auth.user', 'throttle:api_user'])->group(function (): void {
        Route::get('/me', [MeController::class, 'show'])->name('me.show');
        Route::patch('/me', [MeController::class, 'update'])->name('me.update');
    });
});
