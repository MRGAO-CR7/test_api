<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Contract for emitting structured audit events.
 *
 * Production wiring is `LogChannelAuditLogger` (writes to the `audit` log
 * channel). Tests bind a fake recording the calls so controllers and
 * middleware can be exercised without touching the global Log facade.
 *
 * Why an interface (and not a static helper any more):
 *
 *   - Call sites can declare the dependency in their constructor, which
 *     is the same pattern the rest of the service uses (`UserRepositoryInterface`,
 *     `JwksProviderInterface`, `JwtVerifierInterface`, …).
 *   - Tests can swap an in-memory implementation per binding rather than
 *     using `Log::shouldReceive(...)` global state.
 *   - Future fan-out (SIEM / Splunk shipper) is a one-class change behind
 *     this contract, not a sweep across every call site.
 *
 * Field naming and envelope shape are still owned by the implementation —
 * see `LogChannelAuditLogger::write()` for the canonical record format.
 */
interface AuditLoggerInterface
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function authFailed(string $code, array $context = []): void;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function profileUpdated(string $uuid, array $before, array $after): void;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function todoCreated(int $id, array $attributes): void;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function todoUpdated(int $id, array $before, array $after): void;

    public function todoDeleted(int $id): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function serverError(string $exceptionClass, string $message, array $context = []): void;
}
