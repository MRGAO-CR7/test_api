<?php

declare(strict_types=1);

use App\Support\Jwt\JwksProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Jwt\ArrayJwksProvider;
use Tests\Support\Jwt\JwtTestHelper;

/*
|--------------------------------------------------------------------------
| Feature tests for the throttle middleware
|--------------------------------------------------------------------------
|
| We force the per-uuid limit down to a tiny number so the test runs
| quickly, then make N+1 requests and assert the (N+1)-th gets a 429
| in the standard envelope shape (with Retry-After).
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->jwt = new JwtTestHelper;
    $this->app->instance(JwksProvider::class, new ArrayJwksProvider($this->jwt->asKeySet()));

    // Drop the per-user ceiling for this test only.
    config()->set('rate_limit.api_user_per_minute', 3);

    // Ensure no leftover counters from a previous test cross over.
    RateLimiter::clear('uuid:00000000-0000-4000-8000-000000000001');
});

it('returns 429 in the envelope shape after the per-user ceiling is exceeded', function (): void {
    $token = $this->jwt->sign(JwtTestHelper::defaultClaims());
    $headers = ['Authorization' => "Bearer {$token}"];

    // 3 hits succeed
    foreach (range(1, 3) as $i) {
        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
    }

    // 4th hit must trip the limiter and come back as our envelope
    $blocked = $this->withHeaders($headers)->getJson('/api/v1/me');

    $blocked->assertStatus(429)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertJsonPath('status', 429);

    // Retry-After should be present and a small positive integer
    $retryAfter = $blocked->headers->get('Retry-After');
    expect($retryAfter)->not->toBeNull()
        ->and((int) $retryAfter)->toBeGreaterThan(0)
        ->and((int) $retryAfter)->toBeLessThanOrEqual(60);
});

it('limiter buckets are scoped per-uuid (one user is not throttled by another)', function (): void {
    // Distinct uuids AND distinct emails -- the JIT insert in auth.user
    // would otherwise trip the UNIQUE constraint on email, which would
    // mask whatever the limiter is doing.
    $tokenA = $this->jwt->sign(JwtTestHelper::defaultClaims([
        'sub' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'email' => 'user-a@example.com',
    ]));
    $tokenB = $this->jwt->sign(JwtTestHelper::defaultClaims([
        'sub' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'email' => 'user-b@example.com',
    ]));

    // Burn user A's quota
    foreach (range(1, 3) as $i) {
        $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/me')->assertOk();
    }
    $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/me')->assertStatus(429);

    // User B should still be free to proceed
    $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/me')->assertOk();
});
