<?php

declare(strict_types=1);

use App\Support\Jwt\JwksProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\Jwt\ArrayJwksProvider;
use Tests\Support\Jwt\JwtTestHelper;

/*
|--------------------------------------------------------------------------
| Feature tests for the global exception -> ApiErrorEnvelope mapping
|--------------------------------------------------------------------------
|
| Pins down that Phase 6's ExceptionRenderer covers the "Laravel default
| would have leaked detail" cases. Each test forces a different exception
| through the pipeline and asserts the wire shape.
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->jwt = new JwtTestHelper;
    $this->app->instance(JwksProvider::class, new ArrayJwksProvider($this->jwt->asKeySet()));
});

it('returns the standard envelope for an unknown route (404)', function (): void {
    $this->getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'route_not_found')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('message', 'The requested route does not exist.');
});

it('returns the standard envelope for an unsupported HTTP method (405)', function (): void {
    $this->postJson('/api/v1/health', [])
        ->assertStatus(405)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'method_not_allowed');
});

it('does not leak Laravel debug stack traces in JSON responses', function (): void {
    $response = $this->getJson('/api/v1/does-not-exist');

    $body = $response->json();
    expect($body)->toBeArray()
        ->and($body)->not->toHaveKey('exception')
        ->and($body)->not->toHaveKey('file')
        ->and($body)->not->toHaveKey('line')
        ->and($body)->not->toHaveKey('trace');
});

it('wraps an arbitrary Throwable into a server_error envelope', function (): void {
    Route::get('/api/v1/_test/explode', function (): void {
        throw new RuntimeException('boom');
    });

    $response = $this->getJson('/api/v1/_test/explode')
        ->assertStatus(500)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('code', 'server_error')
        ->assertJsonPath('status', 500);

    $body = $response->json();
    expect($body)->not->toHaveKey('trace');
});

it('wraps a ValidationException as 422 in the envelope shape', function (): void {
    Route::get('/api/v1/_test/validate', function (): void {
        throw Illuminate\Validation\ValidationException::withMessages([
            'name' => ['The name field is required.'],
        ]);
    });

    $this->getJson('/api/v1/_test/validate')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('details.errors.name.0', 'The name field is required.');
});
