<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

/**
 * Liveness probe for test_api.
 *
 * Phase 1: pure process-alive check, no I/O. Returns 200 as long as PHP
 * can boot and respond. Phase 6 will add a separate /health/ready endpoint
 * that pings the database and the JWKS endpoint.
 *
 * The shape is the success envelope used by the rest of the API:
 *
 *     { "data": { ... } }
 *
 * Keeping this consistent (rather than returning a flat object) means the
 * SPA's response parser does not need a special case for /health.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'service' => 'test_api',
                'status' => 'ok',
                // Phase 6 will replace this with the real release tag.
                'version' => config('app.version', 'dev'),
                'time' => now()->toIso8601String(),
            ],
        ]);
    }
}
