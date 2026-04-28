<?php

declare(strict_types=1);

namespace App\Domain\User\DTOs;

use App\Support\Jwt\Exceptions\InvalidJwtException;
use stdClass;

/**
 * Immutable, validated projection of a verified JWT.
 *
 * Construction is the *only* sanctioned path: `fromPayload()` enforces that
 * every required claim is present and of the right shape. Once an
 * AuthClaims exists, downstream code can rely on `$uuid` and `$email` being
 * non-empty strings.
 *
 * The raw payload is preserved on `$raw` for audit logging (Phase 6) and
 * for storing as `users.claims_snapshot` (Phase 5). Do not return it to the
 * client.
 */
final readonly class AuthClaims
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $uuid,
        public string $email,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $jti,
        public int $expiresAt,
        public array $raw,
    ) {}

    /**
     * Build from the decoded JWT payload (`stdClass`) returned by
     * firebase/php-jwt, applying the configured claim-name mapping.
     *
     * @param  array{uuid: string, email: string, first_name: string, last_name: string}  $claimNames
     *
     * @throws InvalidJwtException if a required claim is missing or empty
     */
    public static function fromPayload(stdClass $payload, array $claimNames): self
    {
        $uuid = self::stringClaim($payload, $claimNames['uuid']);
        if ($uuid === null || $uuid === '') {
            throw InvalidJwtException::missingClaim($claimNames['uuid']);
        }

        $email = self::stringClaim($payload, $claimNames['email']);
        if ($email === null || $email === '') {
            // Some Entra tokens put the address under `preferred_username`
            // when `email` is unscoped — try that as a graceful fallback.
            $email = self::stringClaim($payload, 'preferred_username');
        }
        if ($email === null || $email === '') {
            throw InvalidJwtException::missingClaim($claimNames['email']);
        }

        $exp = isset($payload->exp) && is_int($payload->exp) ? $payload->exp : 0;

        return new self(
            uuid: $uuid,
            email: $email,
            firstName: self::stringClaim($payload, $claimNames['first_name']),
            lastName: self::stringClaim($payload, $claimNames['last_name']),
            jti: self::stringClaim($payload, 'jti'),
            expiresAt: $exp,
            raw: (array) $payload,
        );
    }

    private static function stringClaim(stdClass $payload, string $name): ?string
    {
        if (! property_exists($payload, $name)) {
            return null;
        }
        $value = $payload->{$name};

        return is_string($value) ? $value : null;
    }
}
