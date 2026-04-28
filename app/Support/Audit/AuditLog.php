<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Support\Facades\Log;

/**
 * Single typed entry-point for audit-relevant events.
 *
 * Writes one structured record per call to the dedicated `audit` log
 * channel (see config/logging.php). Each record is normalised to:
 *
 *     {
 *       "event": "auth.failed" | "me.updated" | "server.error",
 *       "request_id": "...",
 *       "ip": "1.2.3.4",
 *       "ua": "...",
 *       <event-specific keys>
 *     }
 *
 * Why a single helper class (vs. raw Log::channel('audit')->info(...) at
 * each call site):
 *   - One place to enforce field naming conventions.
 *   - One place to add fan-out later (e.g. SIEM / Splunk shipper).
 *   - Tests can swap or assert on this class without touching the global
 *     log facade.
 */
final class AuditLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function authFailed(string $code, array $context = []): void
    {
        self::write('auth.failed', array_merge(
            ['code' => $code],
            $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function profileUpdated(string $uuid, array $before, array $after): void
    {
        // Only diff the keys that actually changed -- a no-op PATCH should
        // not pollute the audit trail with full snapshots.
        $changed = [];
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ($oldValue !== $newValue) {
                $changed[$key] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        if ($changed === []) {
            return;
        }

        self::write('me.updated', [
            'uuid' => $uuid,
            'changes' => $changed,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function serverError(string $exceptionClass, string $message, array $context = []): void
    {
        self::write('server.error', array_merge(
            [
                'exception' => $exceptionClass,
                'message' => $message,
            ],
            $context,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function write(string $event, array $payload): void
    {
        // AuditLog is only ever called from inside an HTTP request lifecycle
        // (middleware, controller, exception renderer). The container is
        // guaranteed to have a Request bound at that point.
        $request = request();

        $base = [
            'event' => $event,
            'request_id' => $request->attributes->get('request.id'),
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
        ];

        Log::channel('audit')->info($event, array_merge($base, $payload));
    }
}
