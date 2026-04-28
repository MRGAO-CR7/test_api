<?php

declare(strict_types=1);

it('returns a 200 with the standard data envelope from /api/v1/test/health', function (): void {
    $response = $this->getJson('/api/v1/test/health');

    $response->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('data.service', 'test_api')
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonStructure([
            'data' => ['service', 'status', 'version', 'time'],
        ]);
});

it('still returns JSON even when the client forgets the Accept header', function (): void {
    // Calling get() (not getJson()) means no Accept header is set; the
    // ForceJsonResponse middleware should rewrite it before the handler runs.
    $response = $this->get('/api/v1/test/health');

    $response->assertOk()
        ->assertHeader('content-type', 'application/json');
});
