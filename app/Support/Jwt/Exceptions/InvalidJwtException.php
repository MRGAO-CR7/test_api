<?php

declare(strict_types=1);

namespace App\Support\Jwt\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Domain exception for any failure during JWT verification.
 *
 * `$code` here is the *machine* error code that ends up in the
 * `ApiErrorEnvelope`'s `code` field — keep it stable, lowercase, snake_case.
 * The standard PHP `Exception::$code` (int) is unused on purpose.
 */
final class InvalidJwtException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function missingBearer(): self
    {
        return new self('missing_bearer', 'Missing Authorization: Bearer header.');
    }

    public static function malformed(?Throwable $previous = null): self
    {
        return new self('malformed_jwt', 'JWT could not be parsed.', $previous);
    }

    public static function expired(?Throwable $previous = null): self
    {
        return new self('token_expired', 'JWT has expired.', $previous);
    }

    public static function notYetValid(?Throwable $previous = null): self
    {
        return new self('token_not_yet_valid', 'JWT is not valid yet.', $previous);
    }

    public static function badSignature(?Throwable $previous = null): self
    {
        return new self('signature_invalid', 'JWT signature did not verify.', $previous);
    }

    public static function disallowedAlgorithm(string $alg): self
    {
        return new self('alg_not_allowed', sprintf('JWT alg "%s" is not in the allow-list.', $alg));
    }

    public static function issuerMismatch(): self
    {
        return new self('iss_mismatch', 'JWT issuer does not match the configured value.');
    }

    public static function audienceMismatch(): self
    {
        return new self('aud_mismatch', 'JWT audience does not match the configured value.');
    }

    public static function missingClaim(string $name): self
    {
        return new self('missing_claim', sprintf('Required JWT claim "%s" is missing or empty.', $name));
    }

    public static function notConfigured(): self
    {
        return new self('auth_not_configured', 'JWT verification is not configured on this service.');
    }
}
