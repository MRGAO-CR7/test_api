<?php

declare(strict_types=1);

use App\Domain\User\DTOs\AuthClaims;
use App\Support\Jwt\EntraJwtVerifier;
use App\Support\Jwt\Exceptions\InvalidJwtException;
use App\Support\Jwt\JwksProviderInterface;
use Firebase\JWT\Key;
use Tests\Support\Jwt\ArrayJwksProvider;
use Tests\Support\Jwt\JwtTestHelper;

/*
|--------------------------------------------------------------------------
| Unit tests for EntraJwtVerifier
|--------------------------------------------------------------------------
|
| These exercise the verifier directly with a stub JwksProviderInterface. No HTTP,
| no Laravel routing — we want each rejection reason wired to the right
| stable error code.
|
*/

/**
 * @return array{issuer: ?string, audience: ?string, algorithms: list<string>, leeway: int, claims: array{uuid: string, email: string, first_name: string, last_name: string}}
 */
function defaultVerifierConfig(): array
{
    return [
        'issuer' => 'https://test-idp.invalid/',
        'audience' => 'api://test_api',
        'algorithms' => ['RS256'],
        'leeway' => 30,
        'claims' => [
            'uuid' => 'sub',
            'email' => 'email',
            'first_name' => 'given_name',
            'last_name' => 'family_name',
        ],
    ];
}

function makeVerifier(JwtTestHelper $jwt): EntraJwtVerifier
{
    return new EntraJwtVerifier(new ArrayJwksProvider($jwt->asKeySet()), defaultVerifierConfig());
}

it('returns AuthClaims for a well-formed valid token', function (): void {
    $jwt = new JwtTestHelper;
    $token = $jwt->sign(JwtTestHelper::defaultClaims());

    $claims = makeVerifier($jwt)->verify($token);

    expect($claims)->toBeInstanceOf(AuthClaims::class)
        ->and($claims->uuid)->toBe('00000000-0000-4000-8000-000000000001')
        ->and($claims->email)->toBe('verified@example.com')
        ->and($claims->firstName)->toBe('Verified')
        ->and($claims->lastName)->toBe('User')
        ->and($claims->jti)->toStartWith('jti-');
});

it('rejects an expired token with token_expired', function (): void {
    $jwt = new JwtTestHelper;
    $token = $jwt->sign(JwtTestHelper::defaultClaims([
        'iat' => time() - 3600,
        'nbf' => time() - 3600,
        'exp' => time() - 60,
    ]));

    expect(fn () => makeVerifier($jwt)->verify($token))
        ->toThrow(
            InvalidJwtException::class,
            'JWT has expired.'
        );
});

it('rejects a not-yet-valid token with token_not_yet_valid', function (): void {
    $jwt = new JwtTestHelper;
    $token = $jwt->sign(JwtTestHelper::defaultClaims([
        // Far enough in the future that 30s leeway can't bridge it.
        'iat' => time() + 600,
        'nbf' => time() + 600,
        'exp' => time() + 1200,
    ]));

    expect(fn () => makeVerifier($jwt)->verify($token))
        ->toThrow(InvalidJwtException::class);
});

it('rejects when issuer does not match', function (): void {
    $jwt = new JwtTestHelper;
    $token = $jwt->sign(JwtTestHelper::defaultClaims([
        'iss' => 'https://attacker.example.com/',
    ]));

    try {
        makeVerifier($jwt)->verify($token);
        expect()->fail('Expected iss_mismatch');
    } catch (InvalidJwtException $e) {
        expect($e->errorCode)->toBe('iss_mismatch');
    }
});

it('rejects when audience does not match', function (): void {
    $jwt = new JwtTestHelper;
    $token = $jwt->sign(JwtTestHelper::defaultClaims([
        'aud' => 'api://some-other-service',
    ]));

    try {
        makeVerifier($jwt)->verify($token);
        expect()->fail('Expected aud_mismatch');
    } catch (InvalidJwtException $e) {
        expect($e->errorCode)->toBe('aud_mismatch');
    }
});

it('accepts when the configured audience appears in an aud array', function (): void {
    $jwt = new JwtTestHelper;
    $token = $jwt->sign(JwtTestHelper::defaultClaims([
        'aud' => ['api://test_api', 'api://something_else'],
    ]));

    $claims = makeVerifier($jwt)->verify($token);
    expect($claims->uuid)->toBeString();
});

it('rejects when the signing key is unknown', function (): void {
    $real = new JwtTestHelper;
    $attacker = new JwtTestHelper;        // different keypair, different kid

    // Signed by attacker, but the verifier only knows real's public key.
    $token = $attacker->sign(JwtTestHelper::defaultClaims());

    $verifier = new EntraJwtVerifier(new ArrayJwksProvider($real->asKeySet()), defaultVerifierConfig());

    try {
        $verifier->verify($token);
        expect()->fail('Expected signature failure');
    } catch (InvalidJwtException $e) {
        expect($e->errorCode)->toBe('signature_invalid');
    }
});

it('flushes the JWKS cache and retries once when keys rotate', function (): void {
    // Verifier knows about `old` initially. Issuer has rotated to `new` and
    // signed our token under the new key. The verifier should:
    //   1) Fail to find `new`'s kid in the cached keyset,
    //   2) call flush() — at which point production JwksClient would refetch,
    //   3) retry, this time getting the new key, and succeed.
    $oldKeys = new JwtTestHelper(kid: 'old');
    $newKeys = new JwtTestHelper(kid: 'new');

    $token = $newKeys->sign(JwtTestHelper::defaultClaims());

    // Anonymous JwksProviderInterface that flips its keyset on first flush().
    $rotatingProvider = new class($oldKeys->asKeySet(), $newKeys->asKeySet()) implements JwksProviderInterface
    {
        private int $flushes = 0;

        /**
         * @param  array<string, Key>  $old
         * @param  array<string, Key>  $new
         */
        public function __construct(private array $old, private array $new) {}

        public function getKeys(): array
        {
            return $this->flushes === 0 ? $this->old : $this->new;
        }

        public function flush(): void
        {
            $this->flushes++;
        }

        public function flushCount(): int
        {
            return $this->flushes;
        }
    };

    $verifier = new EntraJwtVerifier($rotatingProvider, defaultVerifierConfig());

    $claims = $verifier->verify($token);

    expect($claims->uuid)->toBe('00000000-0000-4000-8000-000000000001')
        ->and($rotatingProvider->flushCount())->toBe(1);
});

it('refuses an empty token string', function (): void {
    $jwt = new JwtTestHelper;
    expect(fn () => makeVerifier($jwt)->verify(''))
        ->toThrow(InvalidJwtException::class);
});

it('rejects a token missing the configured uuid claim', function (): void {
    $jwt = new JwtTestHelper;
    $claims = JwtTestHelper::defaultClaims();
    unset($claims['sub']);
    $token = $jwt->sign($claims);

    try {
        makeVerifier($jwt)->verify($token);
        expect()->fail('Expected missing_claim');
    } catch (InvalidJwtException $e) {
        expect($e->errorCode)->toBe('missing_claim');
    }
});

it('falls back to preferred_username when email is missing', function (): void {
    $jwt = new JwtTestHelper;
    $claims = JwtTestHelper::defaultClaims();
    unset($claims['email']);
    $claims['preferred_username'] = 'fallback@example.com';
    $token = $jwt->sign($claims);

    $authClaims = makeVerifier($jwt)->verify($token);

    expect($authClaims->email)->toBe('fallback@example.com');
});

it('falls back to unique_name (Entra v1.0) when email and preferred_username are missing', function (): void {
    $jwt = new JwtTestHelper;
    $claims = JwtTestHelper::defaultClaims();
    unset($claims['email']);
    $claims['unique_name'] = 'v1user@example.com';
    $token = $jwt->sign($claims);

    $authClaims = makeVerifier($jwt)->verify($token);

    expect($authClaims->email)->toBe('v1user@example.com');
});

it('prefers unique_name over upn for the email fallback', function (): void {
    // Real-world Entra v1.0: `upn` is often `<oid>@<tenant>.onmicrosoft.com`,
    // while `unique_name` carries the actual sign-in address. The DTO must
    // pick the more useful one.
    $jwt = new JwtTestHelper;
    $claims = JwtTestHelper::defaultClaims();
    unset($claims['email']);
    $claims['upn'] = 'opaque-oid@tenant.onmicrosoft.com';
    $claims['unique_name'] = 'real.user@example.com';
    $token = $jwt->sign($claims);

    $authClaims = makeVerifier($jwt)->verify($token);

    expect($authClaims->email)->toBe('real.user@example.com');
});

it('falls back to upn when neither email, preferred_username, nor unique_name are present', function (): void {
    $jwt = new JwtTestHelper;
    $claims = JwtTestHelper::defaultClaims();
    unset($claims['email']);
    $claims['upn'] = 'last.resort@example.com';
    $token = $jwt->sign($claims);

    $authClaims = makeVerifier($jwt)->verify($token);

    expect($authClaims->email)->toBe('last.resort@example.com');
});
