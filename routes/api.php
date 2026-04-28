<?php

declare(strict_types=1);

use App\Domain\User\DTOs\AuthClaims;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — temporary auth debug endpoint
    |--------------------------------------------------------------------------
    | Returns the verified JWT claims. Used to verify the auth pipeline before
    | we have user persistence. Phase 5 deletes this in favour of /me.
    */
    Route::middleware(['auth.jwt'])->group(function (): void {
        Route::get('/_debug/whoami', function (Request $request): JsonResponse {
            /** @var AuthClaims $claims */
            $claims = $request->attributes->get('auth.claims');

            return new JsonResponse([
                'data' => [
                    'uuid' => $claims->uuid,
                    'email' => $claims->email,
                    'first_name' => $claims->firstName,
                    'last_name' => $claims->lastName,
                    'jti' => $claims->jti,
                    'expires_at' => $claims->expiresAt,
                ],
            ]);
        })->name('debug.whoami');
    });
});
