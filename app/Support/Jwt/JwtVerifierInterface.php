<?php

declare(strict_types=1);

namespace App\Support\Jwt;

use App\Domain\User\DTOs\AuthClaims;
use App\Support\Jwt\Exceptions\InvalidJwtException;

/**
 * Verifies an incoming JWT and projects it into a typed `AuthClaims` DTO.
 *
 * Production wiring is `EntraJwtVerifier` (Microsoft Entra External ID).
 * Tests can bind any other implementation without touching the middleware
 * stack — `VerifyEntraJwt` only knows about this contract.
 *
 * Failure model:
 *
 *   Every rejection MUST be raised as `InvalidJwtException` carrying a
 *   stable `errorCode` (token_expired, iss_mismatch, signature_invalid,
 *   …). The middleware translates those codes into the wire envelope; if
 *   an implementation throws anything else the auth pipeline will treat
 *   it as an unexpected 500.
 */
interface JwtVerifierInterface
{
    /**
     * @throws InvalidJwtException on any verification failure.
     */
    public function verify(string $jwt): AuthClaims;
}
