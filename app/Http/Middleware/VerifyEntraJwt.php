<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\AuditLog;
use App\Support\Http\ApiErrorEnvelope;
use App\Support\Jwt\EntraJwtVerifier;
use App\Support\Jwt\Exceptions\InvalidJwtException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request via an external JWT (auth_service / Entra).
 *
 *   1. Pull the bearer token off the `Authorization` header. Anything else
 *      is a 401 with a stable machine code.
 *   2. Hand the token to `EntraJwtVerifier`. Any verification failure is
 *      surfaced as a 401 with the verifier's `errorCode` (token_expired,
 *      iss_mismatch, …) so the SPA can react meaningfully.
 *   3. On success, attach the typed `AuthClaims` DTO to the request under
 *      the `auth.claims` attribute. Phase 5's `ResolveCurrentUser` reads
 *      this and turns it into a `User` row.
 *
 * Non-goals:
 *
 *   - This middleware NEVER touches the database. (Phase 5 does that.)
 *   - This middleware NEVER calls auth_service. (Phase 4 design rule.)
 */
final class VerifyEntraJwt
{
    public function __construct(
        private readonly EntraJwtVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = self::extractBearer($request);
        if ($token === null) {
            AuditLog::authFailed('missing_bearer');

            return ApiErrorEnvelope::unauthorized('missing_bearer', 'Missing Authorization: Bearer header.');
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (InvalidJwtException $e) {
            AuditLog::authFailed($e->errorCode);

            // `auth_not_configured` is an operator problem, not a client one
            // — surface it as 503 so the BFF can distinguish it from a real
            // auth failure and avoid a logout loop.
            if ($e->errorCode === 'auth_not_configured') {
                return ApiErrorEnvelope::make(503, 'auth_not_configured', $e->getMessage());
            }

            return ApiErrorEnvelope::unauthorized($e->errorCode, $e->getMessage());
        }

        // Make the verified claims available to downstream middleware /
        // controllers under a stable attribute name.
        $request->attributes->set('auth.claims', $claims);

        return $next($request);
    }

    private static function extractBearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '') ?? '';
        if ($header === '') {
            return null;
        }

        // Use a case-insensitive prefix match — RFC 7235 declares the scheme
        // token case-insensitive, and some HTTP clients send "bearer".
        if (strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
