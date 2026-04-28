<?php

declare(strict_types=1);

use App\Domain\User\Models\User;
use App\Support\Jwt\JwksProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Jwt\ArrayJwksProvider;
use Tests\Support\Jwt\JwtTestHelper;

/*
|--------------------------------------------------------------------------
| Feature tests for /api/v1/test/me (Phase 5)
|--------------------------------------------------------------------------
|
| These exercise the full middleware stack:
|
|   auth.jwt  -> verifies a self-signed token
|   auth.user -> JIT-creates / touches the local users row
|   controller -> emits the UserResource shape
|
| We sign tokens with JwtTestHelper and stub the JwksProvider with our local
| public key, exactly like AuthMiddlewareTest. No live network calls.
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->jwt = new JwtTestHelper;
    $this->app->instance(JwksProvider::class, new ArrayJwksProvider($this->jwt->asKeySet()));
});

/**
 * Build an Authorization header from the default test claims with optional
 * overrides. Keeps the individual tests focused on the single thing they
 * are checking.
 *
 * @param  array<string, mixed>  $overrides
 */
function bearerHeader(JwtTestHelper $jwt, array $overrides = []): string
{
    $token = $jwt->sign(JwtTestHelper::defaultClaims($overrides));

    return "Bearer {$token}";
}

it('JIT-creates a local user on the first authenticated request', function (): void {
    expect(User::query()->count())->toBe(0);

    $this->withHeader('Authorization', bearerHeader($this->jwt, [
        'sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'email' => 'first@example.com',
        'given_name' => 'First',
        'family_name' => 'Login',
    ]))
        ->getJson('/api/v1/test/me')
        ->assertOk()
        ->assertJsonPath('data.uuid', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
        ->assertJsonPath('data.email', 'first@example.com')
        ->assertJsonPath('data.first_name', 'First')
        ->assertJsonPath('data.last_name', 'Login');

    $this->assertDatabaseHas('users', [
        'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'email' => 'first@example.com',
        'first_name' => 'First',
        'last_name' => 'Login',
    ]);
    expect(User::query()->count())->toBe(1);
});

it('reuses the same row on the second request and bumps last_seen_at', function (): void {
    $headers = ['Authorization' => bearerHeader($this->jwt)];

    $this->withHeaders($headers)->getJson('/api/v1/test/me')->assertOk();

    /** @var User $afterFirst */
    $afterFirst = User::query()->firstOrFail();
    /** @var Illuminate\Support\Carbon $firstSeen */
    $firstSeen = $afterFirst->last_seen_at;
    expect($firstSeen)->not->toBeNull();

    // Force the clock forward so the second call's `last_seen_at` differs.
    sleep(1);

    $this->withHeaders($headers)->getJson('/api/v1/test/me')->assertOk();

    expect(User::query()->count())->toBe(1);

    /** @var User $afterSecond */
    $afterSecond = User::query()->firstOrFail();
    /** @var Illuminate\Support\Carbon $secondSeen */
    $secondSeen = $afterSecond->last_seen_at;
    expect($secondSeen)->not->toBeNull()
        ->and($secondSeen->greaterThan($firstSeen))->toBeTrue();
});

it('refreshes mutable profile fields from the token on each call', function (): void {
    $uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    $this->withHeader('Authorization', bearerHeader($this->jwt, [
        'sub' => $uuid,
        'email' => 'old@example.com',
        'given_name' => 'Old',
        'family_name' => 'Name',
    ]))->getJson('/api/v1/test/me')->assertOk();

    $this->withHeader('Authorization', bearerHeader($this->jwt, [
        'sub' => $uuid,
        'email' => 'updated@example.com',
        'given_name' => 'Updated',
        'family_name' => 'Name',
    ]))
        ->getJson('/api/v1/test/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'updated@example.com')
        ->assertJsonPath('data.first_name', 'Updated');
});

it('persists the full claims_snapshot for audit purposes (server-side only)', function (): void {
    $this->withHeader('Authorization', bearerHeader($this->jwt))->getJson('/api/v1/test/me')->assertOk();

    $row = User::query()->firstOrFail();

    expect($row->claims_snapshot)
        ->toBeArray()
        ->and($row->claims_snapshot['sub'] ?? null)->toBe('00000000-0000-4000-8000-000000000001')
        ->and($row->claims_snapshot['iss'] ?? null)->not->toBeNull();
});

it('does NOT expose claims_snapshot or last_token_jti in the API response', function (): void {
    $response = $this->withHeader('Authorization', bearerHeader($this->jwt))
        ->getJson('/api/v1/test/me')
        ->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveKeys(['uuid', 'email', 'first_name', 'last_name', 'last_seen_at'])
        ->and($data)->not->toHaveKey('claims_snapshot')
        ->and($data)->not->toHaveKey('last_token_jti')
        ->and($data)->not->toHaveKey('id');
});

it('PATCH /me updates allow-listed mutable fields', function (): void {
    $this->withHeader('Authorization', bearerHeader($this->jwt))->getJson('/api/v1/test/me')->assertOk();

    $this->withHeader('Authorization', bearerHeader($this->jwt))
        ->patchJson('/api/v1/test/me', [
            'first_name' => 'Renamed',
            'last_name' => 'Person',
        ])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Renamed')
        ->assertJsonPath('data.last_name', 'Person');

    $this->assertDatabaseHas('users', [
        'uuid' => '00000000-0000-4000-8000-000000000001',
        'first_name' => 'Renamed',
        'last_name' => 'Person',
    ]);
});

it('PATCH /me ignores attempts to change uuid or other unknown fields', function (): void {
    $this->withHeader('Authorization', bearerHeader($this->jwt))->getJson('/api/v1/test/me')->assertOk();

    $this->withHeader('Authorization', bearerHeader($this->jwt))
        ->patchJson('/api/v1/test/me', [
            // Stable identity: must never be settable from a request body.
            'uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            // Server-side bookkeeping: the auth.user middleware writes this
            // from the verified JWT, never from the request body.
            'last_token_jti' => 'malicious',
            // Audit blob: only the verifier feeds this column.
            'claims_snapshot' => ['totally_made_up' => true],
            'first_name' => 'Allowed',
        ])
        ->assertOk()
        ->assertJsonPath('data.uuid', '00000000-0000-4000-8000-000000000001')
        ->assertJsonPath('data.first_name', 'Allowed');

    $this->assertDatabaseMissing('users', [
        'uuid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
    ]);

    /** @var User $row */
    $row = User::query()->where('uuid', '00000000-0000-4000-8000-000000000001')->firstOrFail();
    expect($row->last_token_jti)->not->toBe('malicious');
    expect($row->claims_snapshot ?? [])->not->toHaveKey('totally_made_up');
});

it('PATCH /me rejects an invalid email with 422 in the standard envelope', function (): void {
    $this->withHeader('Authorization', bearerHeader($this->jwt))->getJson('/api/v1/test/me')->assertOk();

    $this->withHeader('Authorization', bearerHeader($this->jwt))
        ->patchJson('/api/v1/test/me', [
            'email' => 'not-an-email',
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['details' => ['errors' => ['email']]]);
});

it('PATCH /me rejects an email already in use by another user', function (): void {
    User::factory()->create([
        'uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'email' => 'taken@example.com',
    ]);

    $this->withHeader('Authorization', bearerHeader($this->jwt))->getJson('/api/v1/test/me')->assertOk();

    $this->withHeader('Authorization', bearerHeader($this->jwt))
        ->patchJson('/api/v1/test/me', [
            'email' => 'taken@example.com',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['details' => ['errors' => ['email']]]);
});

it('PATCH /me lets the user re-PATCH their OWN current email', function (): void {
    $this->withHeader('Authorization', bearerHeader($this->jwt, [
        'email' => 'self@example.com',
    ]))->getJson('/api/v1/test/me')->assertOk();

    $this->withHeader('Authorization', bearerHeader($this->jwt, [
        'email' => 'self@example.com',
    ]))
        ->patchJson('/api/v1/test/me', [
            'email' => 'self@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.email', 'self@example.com');
});

it('PATCH /me with an empty body is a no-op 200', function (): void {
    $this->withHeader('Authorization', bearerHeader($this->jwt))->getJson('/api/v1/test/me')->assertOk();

    $this->withHeader('Authorization', bearerHeader($this->jwt))
        ->patchJson('/api/v1/test/me', [])
        ->assertOk()
        ->assertJsonPath('data.uuid', '00000000-0000-4000-8000-000000000001');
});

it('GET /me without a valid token still returns 401 missing_bearer', function (): void {
    $this->getJson('/api/v1/test/me')
        ->assertStatus(401)
        ->assertJsonPath('code', 'missing_bearer');
});
