<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;

/*
|--------------------------------------------------------------------------
| Feature tests for the X-Request-Id middleware
|--------------------------------------------------------------------------
|
| Pins down the contract test_frontend's BFF will rely on:
|   - inbound X-Request-Id is reused if it has a sane shape;
|   - otherwise a UUIDv4 is minted;
|   - the chosen id is always echoed in the response, even on errors.
|
*/

it('echoes a sane inbound X-Request-Id back on the response', function (): void {
    $inbound = 'abcdef-1234567890_REQ-id';

    $response = $this->withHeader(AssignRequestId::HEADER, $inbound)
        ->getJson('/api/v1/health');

    expect($response->headers->get(AssignRequestId::HEADER))->toBe($inbound);
});

it('mints a UUIDv4 when the inbound X-Request-Id is missing', function (): void {
    $response = $this->getJson('/api/v1/health');

    $id = $response->headers->get(AssignRequestId::HEADER);
    expect($id)->toBeString()
        ->and($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('rejects a malformed inbound X-Request-Id and mints a fresh one', function (): void {
    // Too short, contains "/", and contains spaces -- all disqualifying.
    $response = $this->withHeader(AssignRequestId::HEADER, 'oops bad/id')
        ->getJson('/api/v1/health');

    $id = $response->headers->get(AssignRequestId::HEADER);
    expect($id)->not->toBe('oops bad/id')
        ->and($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('echoes X-Request-Id even on an error response (404)', function (): void {
    $inbound = 'corr-id-on-error-path-12345';

    $response = $this->withHeader(AssignRequestId::HEADER, $inbound)
        ->getJson('/api/v1/does-not-exist');

    $response->assertStatus(404);
    expect($response->headers->get(AssignRequestId::HEADER))->toBe($inbound);
});
