<?php

declare(strict_types=1);

use App\Support\Jwt\Exceptions\InvalidJwtException;
use App\Support\Jwt\JwksProvider;
use Firebase\JWT\Key;

/*
|--------------------------------------------------------------------------
| Feature tests for /api/v1/ready
|--------------------------------------------------------------------------
|
| The readiness probe must reflect actual dependency state. We stub the
| JwksProvider with happy / sad doubles to assert each branch.
|
*/

beforeEach(function (): void {
    // Default to a healthy JWKS so the probe can pass when we want it to.
    $this->app->instance(JwksProvider::class, new class implements JwksProvider
    {
        /** @return array<string, Key> */
        public function getKeys(): array
        {
            return ['fake-kid' => new Key('fake-pem', 'RS256')];
        }

        public function flush(): void {}
    });
});

it('returns 200 ready when DB and JWKS are healthy', function (): void {
    $this->getJson('/api/v1/ready')
        ->assertOk()
        ->assertJsonPath('data.service', 'test_api')
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.checks.database', 'ok')
        ->assertJsonPath('data.checks.jwks', 'ok');
});

it('returns 503 not_ready when JWKS is down', function (): void {
    $this->app->instance(JwksProvider::class, new class implements JwksProvider
    {
        /** @return array<string, Key> */
        public function getKeys(): array
        {
            throw InvalidJwtException::badSignature(new RuntimeException('upstream JWKS 503'));
        }

        public function flush(): void {}
    });

    $this->getJson('/api/v1/ready')
        ->assertStatus(503)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'not_ready')
        ->assertJsonPath('details.checks.database', 'ok')
        ->assertJsonPath('details.checks.jwks', 'down');
});

it('returns 503 not_ready when JWKS yields an empty key set', function (): void {
    $this->app->instance(JwksProvider::class, new class implements JwksProvider
    {
        /** @return array<string, Key> */
        public function getKeys(): array
        {
            return [];
        }

        public function flush(): void {}
    });

    $this->getJson('/api/v1/ready')
        ->assertStatus(503)
        ->assertJsonPath('details.checks.jwks', 'down');
});
