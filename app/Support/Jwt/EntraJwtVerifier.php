<?php

declare(strict_types=1);

namespace App\Support\Jwt;

use App\Domain\User\DTOs\AuthClaims;
use App\Support\Jwt\Exceptions\InvalidJwtException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use stdClass;
use Throwable;
use UnexpectedValueException;

/**
 * Verifies a JWT against the configured issuer's JWKS and returns a typed
 * `AuthClaims` DTO. **Stateless and side-effect-free** apart from the cache
 * touched by JwksProviderInterface.
 *
 * Verification order — each step bails out with a specific `InvalidJwtException`:
 *
 *   1. firebase/php-jwt parses + verifies the signature against any key in
 *      the cached JWKS, and enforces `exp` / `nbf` / `iat` with `leeway`.
 *      On a SignatureInvalidException we flush the JWKS cache and retry once
 *      to absorb a key rotation.
 *   2. We assert `iss` exactly matches the configured issuer. (firebase/php-jwt
 *      does not validate `iss` for us.)
 *   3. We assert that *any* of the configured audiences appears in the
 *      token's `aud` (string or array). The config accepts a single string
 *      or a list — Entra may issue tokens with `aud` as either the App ID
 *      URI (`api://<guid>`) or the bare client GUID depending on token
 *      version, and operators sometimes need to accept both during a
 *      rollout.
 *   4. We project the payload into `AuthClaims` via the configured
 *      claim-name mapping.
 */
final class EntraJwtVerifier implements JwtVerifierInterface
{
    /**
     * @param  array{issuer: ?string, audience: string|list<string>|null, algorithms: list<string>, leeway: int, claims: array{uuid: string, email: string, first_name: string, last_name: string}}  $config
     */
    public function __construct(
        private readonly JwksProviderInterface $jwks,
        private readonly array $config,
    ) {}

    public function verify(string $jwt): AuthClaims
    {
        if ($jwt === '') {
            throw InvalidJwtException::malformed();
        }

        // Apply leeway *globally* (firebase/php-jwt reads this static).
        // This is process-local and only affects exp/nbf/iat checks.
        JWT::$leeway = $this->config['leeway'];

        $payload = $this->decodeWithRotationRetry($jwt);

        // (2) Issuer
        $expectedIss = $this->config['issuer'];
        if ($expectedIss !== null && $expectedIss !== ''
            && (! isset($payload->iss) || $payload->iss !== $expectedIss)) {
            throw InvalidJwtException::issuerMismatch();
        }

        // (3) Audience — accept any of the configured values
        $expectedAuds = self::normaliseExpectedAudiences($this->config['audience']);
        if ($expectedAuds !== []) {
            $aud = $payload->aud ?? null;
            $audValues = is_array($aud) ? $aud : (is_string($aud) ? [$aud] : []);
            $matched = false;
            foreach ($expectedAuds as $expected) {
                if (in_array($expected, $audValues, true)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                throw InvalidJwtException::audienceMismatch();
            }
        }

        // (4) Project to typed DTO
        return AuthClaims::fromPayload($payload, $this->config['claims']);
    }

    /**
     * Decode + signature-check, with a single rotation retry on:
     *
     *   - SignatureInvalidException (real signature mismatch), or
     *   - UnexpectedValueException whose message mentions "kid" (the cached
     *     JWKS doesn't have the key the token was signed with — almost
     *     always a rotation we haven't picked up yet).
     *
     * After a flush, both cases retry exactly once. If the retry also
     * fails the same way, the final error is `signature_invalid`.
     */
    private function decodeWithRotationRetry(string $jwt): stdClass
    {
        try {
            return JWT::decode($jwt, $this->jwks->getKeys());
        } catch (ExpiredException $e) {
            throw InvalidJwtException::expired($e);
        } catch (BeforeValidException $e) {
            throw InvalidJwtException::notYetValid($e);
        } catch (SignatureInvalidException $e) {
            return $this->retryAfterFlush($jwt, $e);
        } catch (UnexpectedValueException $e) {
            if (self::isKidLookupFailure($e)) {
                return $this->retryAfterFlush($jwt, $e);
            }
            if (self::isAlgorithmRejection($e)) {
                throw InvalidJwtException::disallowedAlgorithm('unknown');
            }
            throw InvalidJwtException::malformed($e);
        } catch (InvalidJwtException $e) {
            // Bubble "auth_not_configured" et al from JwksClient untouched.
            throw $e;
        } catch (Throwable $e) {
            throw InvalidJwtException::badSignature($e);
        }
    }

    private function retryAfterFlush(string $jwt, Throwable $original): stdClass
    {
        $this->jwks->flush();
        try {
            return JWT::decode($jwt, $this->jwks->getKeys());
        } catch (ExpiredException $e) {
            throw InvalidJwtException::expired($e);
        } catch (BeforeValidException $e) {
            throw InvalidJwtException::notYetValid($e);
        } catch (SignatureInvalidException $e) {
            throw InvalidJwtException::badSignature($e);
        } catch (UnexpectedValueException $e) {
            // Even after rotation we still don't have the key — treat as
            // a real signature failure, not a malformed token.
            throw InvalidJwtException::badSignature($e);
        } catch (InvalidJwtException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw InvalidJwtException::badSignature($e);
        }
    }

    private static function isKidLookupFailure(UnexpectedValueException $e): bool
    {
        return stripos($e->getMessage(), 'kid') !== false;
    }

    private static function isAlgorithmRejection(UnexpectedValueException $e): bool
    {
        return stripos($e->getMessage(), 'algorithm') !== false;
    }

    /**
     * Normalise the configured `audience` (string | list<string> | null) into
     * a clean list, dropping nulls and empty strings.
     *
     * @param  string|list<string>|null  $raw
     * @return list<string>
     */
    private static function normaliseExpectedAudiences(string|array|null $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $values = is_array($raw) ? $raw : [$raw];
        $clean = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $clean[] = $value;
            }
        }

        return $clean;
    }
}
