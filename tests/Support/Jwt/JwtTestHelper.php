<?php

declare(strict_types=1);

namespace Tests\Support\Jwt;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Generate RSA keypairs and self-sign JWTs for testing.
 *
 * We do NOT pin a fixture key — fresh keys are generated per test instance,
 * so a leaked key would never let a real client through to test_api anyway.
 */
final class JwtTestHelper
{
    public string $kid;

    private OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPem;

    public function __construct(?string $kid = null)
    {
        $this->kid = $kid ?? 'test-kid-'.bin2hex(random_bytes(4));

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            throw new RuntimeException('openssl_pkey_new failed: '.openssl_error_string());
        }
        $this->privateKey = $resource;

        $details = openssl_pkey_get_details($resource);
        if ($details === false || ! isset($details['key']) || ! is_string($details['key'])) {
            throw new RuntimeException('openssl_pkey_get_details failed.');
        }
        $this->publicKeyPem = $details['key'];
    }

    /**
     * @return array<string, Key>
     */
    public function asKeySet(): array
    {
        return [
            $this->kid => new Key($this->publicKeyPem, 'RS256'),
        ];
    }

    /**
     * Sign a token with this helper's private key.
     *
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims): string
    {
        return JWT::encode($claims, $this->privateKeyPem(), 'RS256', $this->kid);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function defaultClaims(array $overrides = []): array
    {
        $now = time();

        return array_merge([
            'iss' => 'https://test-idp.invalid/',
            'aud' => 'api://test_api',
            'iat' => $now - 60,
            'nbf' => $now - 60,
            'exp' => $now + 600,
            'sub' => '00000000-0000-4000-8000-000000000001',
            'email' => 'verified@example.com',
            'given_name' => 'Verified',
            'family_name' => 'User',
            'jti' => 'jti-'.bin2hex(random_bytes(4)),
        ], $overrides);
    }

    private function privateKeyPem(): string
    {
        if (! openssl_pkey_export($this->privateKey, $pem)) {
            throw new RuntimeException('openssl_pkey_export failed: '.openssl_error_string());
        }

        return $pem;
    }
}
