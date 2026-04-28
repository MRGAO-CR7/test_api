<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes
|--------------------------------------------------------------------------
|
| Mounted under /api by withRouting(api: ..., apiPrefix: 'api') in
| bootstrap/app.php, so the URLs below resolve to /api/v1/*.
|
| Phase 1 ships only an unauthenticated liveness endpoint. Auth (Phase 4)
| and protected resources (Phase 5) will be added in their own route groups
| nested inside this same prefix.
|
*/

Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');
});
