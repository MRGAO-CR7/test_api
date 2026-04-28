<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centralised JSON error shape for test_api.
 *
 * Contract (kept intentionally identical to test_frontend's BffErrorBody so
 * the BFF catch-all proxy can pass our errors through unchanged):
 *
 *     {
 *       "ok": false,
 *       "code": "snake_case_machine_code",
 *       "message": "Human-readable, end-user-safe text",
 *       "status": <HTTP status int>,
 *       "details": <optional, structured>
 *     }
 *
 * Rules of thumb:
 *   - Never leak server stack traces or DB errors here. Those are logs.
 *   - "code" is a stable contract for the SPA; keep it lowercase snake_case.
 *   - "message" is safe to render to the user; keep it short and neutral.
 *   - "details" is optional and SHOULD only carry structured data we control
 *     (e.g. validation errors keyed by field). No raw exception text.
 *
 * Phase 1 only exposes the static factory; Phase 6 wires the global exception
 * handler to call into this same helper so the contract is enforced site-wide.
 */
final class ApiErrorEnvelope
{
    /**
     * Build a JsonResponse with the standard error envelope.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function make(
        int $status,
        string $code,
        string $message,
        ?array $details = null,
    ): JsonResponse {
        $body = [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'status' => $status,
        ];

        if ($details !== null) {
            $body['details'] = $details;
        }

        return new JsonResponse($body, $status);
    }

    public static function unauthorized(string $code = 'unauthorized', string $message = 'Authentication required.'): JsonResponse
    {
        return self::make(Response::HTTP_UNAUTHORIZED, $code, $message);
    }

    public static function forbidden(string $code = 'forbidden', string $message = 'You do not have access to this resource.'): JsonResponse
    {
        return self::make(Response::HTTP_FORBIDDEN, $code, $message);
    }

    public static function notFound(string $code = 'not_found', string $message = 'Resource not found.'): JsonResponse
    {
        return self::make(Response::HTTP_NOT_FOUND, $code, $message);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function validation(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return self::make(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'validation_failed',
            $message,
            ['errors' => $errors],
        );
    }

    public static function serverError(string $code = 'server_error', string $message = 'Something went wrong.'): JsonResponse
    {
        return self::make(Response::HTTP_INTERNAL_SERVER_ERROR, $code, $message);
    }
}
