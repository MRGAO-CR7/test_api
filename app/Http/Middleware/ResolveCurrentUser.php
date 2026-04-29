<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\User\DTOs\AuthClaims;
use App\Domain\User\Services\UserProvisionerInterface;
use App\Support\Http\ApiErrorEnvelope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bridge between the verified-token world and the local-user world.
 *
 *   - Reads the typed `AuthClaims` that `VerifyEntraJwt` (auth.jwt) attached
 *     to the request.
 *   - Calls `UserProvisioner` to JIT-create-or-touch the matching local
 *     `users` row.
 *   - Attaches the resulting `User` model to the request under the
 *     `auth.user` attribute, so any controller behind this middleware can
 *     read it via `$request->attributes->get('auth.user')`.
 *
 * This middleware MUST sit *after* `auth.jwt` in the route pipeline. If we
 * see no claims, we treat it as a 500 -- it can only happen by misordering
 * middlewares, never by a real client.
 *
 * Provisioner failures (DB down, etc.) become a generic 503 rather than a
 * 401: the user authenticated correctly, the failure is on our side.
 */
final class ResolveCurrentUser
{
    public function __construct(
        private readonly UserProvisionerInterface $provisioner,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $claims = $request->attributes->get('auth.claims');
        if (! $claims instanceof AuthClaims) {
            // Route mis-configuration. Surface it loudly in dev (server
            // logs will record this); never serve a 500 leaking detail.
            return ApiErrorEnvelope::serverError(
                'auth_pipeline_misconfigured',
                'Authentication middleware is missing.',
            );
        }

        try {
            $user = $this->provisioner->provision($claims);
        } catch (Throwable $e) {
            report($e);

            return ApiErrorEnvelope::make(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'user_provisioning_failed',
                'Could not provision the local user record.',
            );
        }

        // JIT row creation is an internal bookkeeping detail. From the
        // caller's perspective every authenticated request is identical --
        // they never explicitly POSTed a user. Eloquent's
        // `wasRecentlyCreated` flag, however, would cause the resource
        // layer to emit a 201 on the very first request after sign-up,
        // and 200 on every subsequent one. Reset it here so the wire
        // status reflects the *route's* semantics (GET /me is always 200,
        // PATCH /me is always 200), not our DB lifecycle.
        $user->wasRecentlyCreated = false;

        $request->attributes->set('auth.user', $user);

        return $next($request);
    }
}
