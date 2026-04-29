<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;

/**
 * Default `AuditLoggerInterface` — writes one structured record per call to the
 * dedicated `audit` log channel (see config/logging.php).
 *
 * Each record is normalised to:
 *
 *     {
 *       "event": "auth.failed" | "me.updated" | "server.error",
 *       "request_id": "...",
 *       "ip": "1.2.3.4",
 *       "ua": "...",
 *       <event-specific keys>
 *     }
 *
 * The request-context fields (`request_id`, `ip`, `ua`) are pulled from
 * the bound `Request` at write time. Audit events are only ever emitted
 * from inside the HTTP request lifecycle, so a request is guaranteed to
 * be available — outside that lifecycle the fields are simply omitted
 * rather than the call failing.
 */
final class LogChannelAuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly LogManager $logs,
        private readonly Application $app,
    ) {}

    public function authFailed(string $code, array $context = []): void
    {
        $this->write('auth.failed', array_merge(
            ['code' => $code],
            $context,
        ));
    }

    public function profileUpdated(string $uuid, array $before, array $after): void
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

        $this->write('me.updated', [
            'uuid' => $uuid,
            'changes' => $changed,
        ]);
    }

    public function serverError(string $exceptionClass, string $message, array $context = []): void
    {
        $this->write('server.error', array_merge(
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
    private function write(string $event, array $payload): void
    {
        $base = ['event' => $event];

        // Resolve the current Request lazily out of the container. We do
        // not constructor-inject Request because the audit logger itself
        // is bound as a singleton-shaped service while Request is per-call;
        // resolving here keeps us away from a stale Request snapshot.
        if ($this->app->bound('request')) {
            /** @var \Illuminate\Http\Request $request */
            $request = $this->app->make('request');

            $base['request_id'] = $request->attributes->get('request.id');
            $base['ip'] = $request->ip();
            $base['ua'] = $request->userAgent();
        }

        $this->logs->channel('audit')->info($event, array_merge($base, $payload));
    }
}
