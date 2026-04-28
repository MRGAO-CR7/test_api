<?php

declare(strict_types=1);

use App\Support\Jwt\JwksProvider;
use Tests\Support\Jwt\ArrayJwksProvider;
use Tests\Support\Jwt\JwtTestHelper;

/*
|--------------------------------------------------------------------------
| Feature tests for the auth pipeline (rejection paths only)
|--------------------------------------------------------------------------
|
| All rejection branches short-circuit inside `auth.jwt` BEFORE `auth.user`
| ever runs, so we deliberately route through GET /api/v1/test/me here without
| needing a database refresh: the request never reaches the controller.
|
| The success path (200 + UserResource shape, JIT row creation, etc.) lives
| in MeEndpointTest -- this file is the auth-only contract.
|
*/

beforeEach(function (): void {
    $this->jwt = new JwtTestHelper;

    // Replace the bound JwksProvider with a stub that returns our local
    // public key. Because the provider is bound (not singleton) any verifier
    // instance built downstream will see this stub.
    $this->app->instance(JwksProvider::class, new ArrayJwksProvider($this->jwt->asKeySet()));
});

it('returns 401 missing_bearer when there is no Authorization header', function (): void {
    $this->getJson('/api/v1/test/me')
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'missing_bearer');
});

it('returns 401 missing_bearer when the header has the wrong scheme', function (): void {
    $this->withHeader('Authorization', 'Basic Zm9vOmJhcg==')
        ->getJson('/api/v1/test/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'missing_bearer');
});

it('returns 401 token_expired for an expired JWT', function (): void {
    $token = $this->jwt->sign(JwtTestHelper::defaultClaims([
        'iat' => time() - 3600,
        'nbf' => time() - 3600,
        'exp' => time() - 60,
    ]));

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/test/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'token_expired');
});

it('returns 401 iss_mismatch for a token from the wrong issuer', function (): void {
    $token = $this->jwt->sign(JwtTestHelper::defaultClaims([
        'iss' => 'https://attacker.example.com/',
    ]));

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/test/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'iss_mismatch');
});

it('returns 401 aud_mismatch for a token aimed at another service', function (): void {
    $token = $this->jwt->sign(JwtTestHelper::defaultClaims([
        'aud' => 'api://some-other-service',
    ]));

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/test/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'aud_mismatch');
});

it('returns 401 signature_invalid for a token signed with a foreign key', function (): void {
    $attacker = new JwtTestHelper;
    $token = $attacker->sign(JwtTestHelper::defaultClaims());

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/test/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'signature_invalid');
});
