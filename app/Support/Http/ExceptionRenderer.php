<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Single funnel that turns any uncaught exception into our standard
 * `ApiErrorEnvelope` JSON shape.
 *
 * Why this lives in `Support\Http` rather than inline in `bootstrap/app.php`:
 *
 *   - Keeps the bootstrap file boring (one-liner `render()` callback).
 *   - Lets unit tests cover the mapping table directly without booting an
 *     HTTP request.
 *   - Centralises the rule "no exception ever reaches the wire as Laravel's
 *     debug HTML or default JSON shape" in exactly one place.
 *
 * Design rules:
 *
 *   - Stack traces NEVER appear in the response body. Even in `local`
 *     env we only surface the exception class + message to make
 *     debugging easier; everything else stays in the log channel.
 *   - The envelope `code` is always a stable `snake_case` string. Phase 5's
 *     contract for the SPA depends on these strings not changing across
 *     releases, so add new codes rather than rewording existing ones.
 */
final class ExceptionRenderer
{
    /**
     * Map an exception to a JSON envelope response.
     *
     * Always returns a response: the catch-all 500 branch at the bottom
     * guarantees totality. The bootstrap-level handler chooses whether
     * to call us at all (skipping for non-API requests so the framework
     * defaults still apply there).
     */
    public static function render(Throwable $e, bool $debug): JsonResponse
    {
        // ValidationException carries field-level errors. Bake them into
        // `details.errors` so the SPA can highlight inputs without parsing.
        if ($e instanceof ValidationException) {
            return ApiErrorEnvelope::validation($e->errors());
        }

        if ($e instanceof AuthorizationException) {
            return ApiErrorEnvelope::forbidden(
                'forbidden',
                $e->getMessage() !== '' ? $e->getMessage() : 'You do not have access to this resource.',
            );
        }

        if ($e instanceof ModelNotFoundException) {
            return ApiErrorEnvelope::notFound('resource_not_found');
        }

        if ($e instanceof NotFoundHttpException) {
            return ApiErrorEnvelope::notFound(
                'route_not_found',
                'The requested route does not exist.',
            );
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            $response = ApiErrorEnvelope::methodNotAllowed();
            // Carry through Symfony's `Allow` header so clients can correct.
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        }

        if ($e instanceof TooManyRequestsHttpException) {
            return ApiErrorEnvelope::tooManyRequests(
                $e->getMessage() !== '' ? $e->getMessage() : 'Too many requests, slow down.',
                self::retryAfterFromHeaders($e),
            );
        }

        // Any other HTTP exception: respect the status code, emit a generic
        // code keyed off it. Don't leak `getMessage()` blindly because some
        // framework-thrown HttpExceptions carry implementation detail.
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = match (true) {
                $status >= 500 => 'server_error',
                $status === 401 => 'unauthorized',
                $status === 403 => 'forbidden',
                $status === 404 => 'not_found',
                $status === 405 => 'method_not_allowed',
                $status === 409 => 'conflict',
                $status === 422 => 'validation_failed',
                $status === 429 => 'too_many_requests',
                default => 'http_error',
            };
            $message = $debug && $e->getMessage() !== ''
                ? $e->getMessage()
                : self::defaultMessageFor($status);

            return ApiErrorEnvelope::make($status, $code, $message);
        }

        // Catch-all 500. We deliberately do NOT include $e->getMessage() in
        // production -- many internal errors leak SQL fragments / table
        // names. Logs (with full stack) still record everything.
        $message = $debug
            ? sprintf('%s: %s', $e::class, $e->getMessage())
            : 'Something went wrong.';

        return ApiErrorEnvelope::serverError('server_error', $message);
    }

    private static function defaultMessageFor(int $status): string
    {
        return match (true) {
            $status >= 500 => 'Something went wrong.',
            $status === 401 => 'Authentication required.',
            $status === 403 => 'You do not have access to this resource.',
            $status === 404 => 'Resource not found.',
            $status === 405 => 'HTTP method not allowed for this resource.',
            $status === 409 => 'The request conflicts with current resource state.',
            $status === 422 => 'The given data was invalid.',
            $status === 429 => 'Too many requests, slow down.',
            default => 'Request could not be completed.',
        };
    }

    private static function retryAfterFromHeaders(HttpExceptionInterface $e): ?int
    {
        $headers = $e->getHeaders();
        if (! isset($headers['Retry-After'])) {
            return null;
        }
        $value = $headers['Retry-After'];
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
