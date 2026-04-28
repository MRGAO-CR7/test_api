<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Append the small set of security-relevant headers that every JSON
 * response in this service should carry by default.
 *
 * Why each header:
 *
 *   - `X-Content-Type-Options: nosniff`
 *       Stops browsers from MIME-sniffing a JSON body as something
 *       executable. We always send Content-Type: application/json, but a
 *       buggy edge cache could in theory rewrite the type header; nosniff
 *       neutralises that.
 *
 *   - `X-Frame-Options: DENY`
 *       This service never serves HTML, but a future error page from the
 *       framework might. DENY makes it explicit that no frame embedding
 *       is allowed under any circumstances. Cheap insurance.
 *
 *   - `Referrer-Policy: no-referrer`
 *       Bearer tokens often appear in URLs of *upstream* requests; we
 *       don't want our responses to encourage browsers to forward referrer
 *       data on links. `no-referrer` is the most conservative choice and
 *       this is a JSON API, so there's nothing to lose.
 *
 *   - `X-Permitted-Cross-Domain-Policies: none`
 *       Disables Adobe / Flash policy file lookups. Largely vestigial,
 *       but free.
 *
 * NOT included:
 *
 *   - `Strict-Transport-Security`: that should be set at the reverse proxy
 *     (nginx) where the TLS terminates, not by the app.
 *   - `Content-Security-Policy`: irrelevant for a JSON API. Adding it
 *     without thinking through reporting endpoints is more harm than good.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only set headers that aren't already present so a controller can
        // override on a per-response basis if it has a real reason.
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
