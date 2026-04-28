<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Audit\AuditLog;
use App\Support\Http\ApiErrorEnvelope;
use App\Support\Jwt\EntraJwtVerifier;
use App\Support\Jwt\Exceptions\InvalidJwtException;
use Closure;
use Illuminate\Http\Request;
use JsonException;
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
            // Surface enough metadata about the rejected token to diagnose
            // signature / issuer / audience mismatches without keeping the
            // token itself. The header + selected claims are unsigned base64
            // segments anyway -- no secret leaks here.
            AuditLog::authFailed($e->errorCode, self::tokenDebug($token));

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

    /**
     * Decode the JWT header + a small whitelist of claims WITHOUT verifying
     * anything. Used purely as breadcrumb context for failed auth audit log
     * entries -- it is the difference between "we got rejected" and "we got
     * rejected because the token was signed with kid X claiming iss Y".
     *
     * Hard rules:
     *   - Returns ONLY non-secret fields (kid / alg / iss / aud / ver / tid).
     *   - Never returns the raw token, sub, oid, or email -- those would
     *     turn the audit log into a mini-PII dump if a misbehaving client
     *     spammed bad tokens.
     *   - On any decoding error, returns an empty array so the audit
     *     pipeline keeps working.
     *
     * @return array<string, mixed>
     */
    private static function tokenDebug(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['token_shape' => 'malformed'];
        }

        $header = self::decodeJwtSegment($parts[0]);
        $payload = self::decodeJwtSegment($parts[1]);

        $aud = $payload['aud'] ?? null;
        if (is_array($aud)) {
            $aud = '['.implode(',', array_map('strval', $aud)).']';
        }

        return array_filter([
            'header_alg' => $header['alg'] ?? null,
            'header_kid' => $header['kid'] ?? null,
            'header_typ' => $header['typ'] ?? null,
            'token_iss' => $payload['iss'] ?? null,
            'token_aud' => $aud,
            'token_ver' => $payload['ver'] ?? null,
            'token_tid' => $payload['tid'] ?? null,
            'token_appid' => $payload['appid'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJwtSegment(string $segment): array
    {
        // base64url -> base64
        $b64 = strtr($segment, '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode($b64, strict: true);
        if ($json === false) {
            return [];
        }

        try {
            $decoded = json_decode($json, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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
