<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamp every request with an `X-Request-Id` so logs from the BFF
 * (`test_frontend`) and this API can be correlated by a single ID.
 *
 * Behaviour:
 *
 *   - If the inbound request already carries a sane `X-Request-Id`
 *     (8-128 chars, [A-Za-z0-9_-]), reuse it -- the BFF gets to keep
 *     correlating across hops.
 *   - Otherwise, mint a fresh UUIDv4.
 *   - Push the chosen ID into `Log::withContext()` so every log line in
 *     this request's lifecycle carries `request_id=...`.
 *   - Echo it back as a response header so the BFF can record it on its
 *     side of the call.
 *
 * The ID is also kept on `request.attributes['request.id']` for any
 * downstream code that wants to read it without going through the header.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request.id';

    /**
     * Reasonable shape: long enough to be uniquey, short enough not to be
     * abused as a free-text smuggling channel. Permissive char set covers
     * UUIDs, ULIDs, KSUIDs, and the trace ids many APMs emit.
     */
    private const PATTERN = '/^[A-Za-z0-9_-]{8,128}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::extractInbound($request) ?? (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $id);

        // Make this id appear in every Log::* call for the rest of the
        // request lifecycle (Laravel's logger is per-process, so we have to
        // remember to forget when we're done -- see below).
        Log::withContext(['request_id' => $id]);

        try {
            $response = $next($request);
        } finally {
            // Clear the log context so a worker process running multiple
            // requests does not leak the previous request's id into the
            // next one.
            Log::withoutContext();
        }

        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    private static function extractInbound(Request $request): ?string
    {
        $candidate = (string) $request->headers->get(self::HEADER, '');
        if ($candidate === '') {
            return null;
        }

        return preg_match(self::PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
