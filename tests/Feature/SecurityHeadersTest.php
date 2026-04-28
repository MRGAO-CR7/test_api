<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feature tests for SecurityHeaders middleware
|--------------------------------------------------------------------------
|
| Belt-and-suspenders: every response should carry the four security
| headers we elected to pin down at the app layer.
|
*/

it('sets the security headers on a successful response', function (): void {
    $response = $this->getJson('/api/v1/test/health')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('X-Permitted-Cross-Domain-Policies'))->toBe('none');
});

it('sets the security headers even on an error response', function (): void {
    $response = $this->getJson('/api/v1/nope')->assertStatus(404);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});
