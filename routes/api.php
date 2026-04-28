<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
|
| Mounted under /api by withRouting(api: ..., apiPrefix: 'api') in
| bootstrap/app.php, so the URLs below resolve to /api/v1/*.
|
| Conventions:
|   - One controller per resource. No closures in routes (apart from this
|     comment block, of course).
|   - Authenticated routes sit inside a `['auth.jwt', 'auth.user']` group.
|     `auth.jwt` verifies the token; `auth.user` JIT-creates / touches the
|     local users row and attaches the model to the request.
|
*/

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    Route::middleware(['auth.jwt', 'auth.user'])->group(function (): void {
        Route::get('/me', [MeController::class, 'show'])->name('me.show');
        Route::patch('/me', [MeController::class, 'update'])->name('me.update');
    });
});
