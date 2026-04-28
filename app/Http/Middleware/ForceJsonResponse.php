<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force every request through this service to be treated as JSON.
 *
 * Why we override the Accept header rather than just *adding* it:
 *
 *   - Laravel's exception handler decides between an HTML or JSON error
 *     response by calling $request->expectsJson(). If a client (or curl
 *     without -H) leaves Accept off, we still want JSON, never an HTML
 *     debug page or a redirect to "login".
 *   - test_api never serves HTML, so there is no downside.
 *
 * Replace, do not append: a few clients send "Accept: * /*" which would
 * still let HTML win on negotiation. By hard-setting "application/json"
 * we get deterministic behaviour from Symfony's content negotiation.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
