<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Support\Jwt\Exceptions\InvalidJwtException;
use App\Support\Jwt\JwksProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Readiness probe.
 *
 * Liveness (`/api/v1/test/health`) answers "is the PHP process up?". Readiness
 * answers the harder question: "are the dependencies this service needs
 * to *actually serve a request* available right now?".
 *
 * We probe two dependencies:
 *
 *   - **Database**: a trivial `SELECT 1`. Catches DB outages, expired
 *     credentials, full disk on the writer, etc.
 *   - **JWKS endpoint**: ask `JwksProvider` for the current key set. The
 *     provider already caches, so this is normally a sub-millisecond
 *     in-memory hit; on a cold cache it does one HTTP fetch which still
 *     completes in well under the typical k8s probe window.
 *
 * Output shape:
 *
 *     200 OK
 *     {
 *       "data": {
 *         "service": "test_api",
 *         "status": "ready",
 *         "checks": {
 *           "database": "ok",
 *           "jwks": "ok"
 *         }
 *       }
 *     }
 *
 *     503 Service Unavailable
 *     {
 *       "ok": false,
 *       "code": "not_ready",
 *       "message": "...",
 *       "status": 503,
 *       "details": { "checks": { "database": "ok", "jwks": "down" } }
 *     }
 *
 * Why 503 (not 200 with a "degraded" flag): we want load balancers and
 * k8s readiness probes to take this instance OUT of rotation when a
 * dependency is broken, not keep routing traffic to it.
 */
final class ReadinessController
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly JwksProvider $jwks,
    ) {}

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'jwks' => $this->checkJwks(),
        ];

        $allOk = ! in_array(false, array_map(
            static fn (array $c): bool => $c['ok'],
            $checks,
        ), true);

        $checksWire = array_map(
            static fn (array $c): string => $c['ok'] ? 'ok' : 'down',
            $checks,
        );

        if ($allOk) {
            return new JsonResponse([
                'data' => [
                    'service' => 'test_api',
                    'status' => 'ready',
                    'checks' => $checksWire,
                ],
            ]);
        }

        return new JsonResponse([
            'ok' => false,
            'code' => 'not_ready',
            'message' => 'One or more dependencies are unavailable.',
            'status' => Response::HTTP_SERVICE_UNAVAILABLE,
            'details' => ['checks' => $checksWire],
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @return array{ok: bool}
     */
    private function checkDatabase(): array
    {
        try {
            $this->db->connection()->select('SELECT 1');

            return ['ok' => true];
        } catch (Throwable) {
            // Don't surface the actual exception to the wire -- it
            // typically contains DSN / credential details. Log channel
            // already has the full trace.
            return ['ok' => false];
        }
    }

    /**
     * @return array{ok: bool}
     */
    private function checkJwks(): array
    {
        try {
            $keys = $this->jwks->getKeys();

            return ['ok' => $keys !== []];
        } catch (InvalidJwtException) {
            // `auth_not_configured` is also surfaced here -- if we have
            // no JWKS URI configured, we can't authenticate anyone, so
            // we are not ready to serve.
            return ['ok' => false];
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
