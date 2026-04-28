<?php

declare(strict_types=1);

namespace App\Domain\User\Repositories;

use App\Domain\User\DTOs\AuthClaims;
use App\Domain\User\Models\User;

/**
 * Persistence boundary for the User aggregate.
 *
 * Why an interface (and not just a concrete repository class):
 *
 *   - The provisioner / controllers depend on this contract, never on
 *     Eloquent. Swapping to a different store later (or stubbing in tests)
 *     does not ripple into the domain layer.
 *   - Every method here is intentionally narrow and intention-revealing:
 *     callers say *what* they want, not *how* to assemble the query.
 *
 * Lookup contract:
 *
 *   - The single stable identifier is `uuid` (issued by auth_service / Entra).
 *     Email is mutable from upstream and MUST NOT be used as a lookup key.
 *   - Soft-deleted users are NOT returned. If you ever need to revive a
 *     soft-deleted user, add an explicit `findTrashedByUuid` rather than
 *     widening the meaning of `findByUuid`.
 */
interface UserRepository
{
    /**
     * @return User|null null if the uuid is unknown OR the row is soft-deleted
     */
    public function findByUuid(string $uuid): ?User;

    /**
     * Insert a fresh row from a verified set of JWT claims.
     *
     * Caller has already established that no User exists for this uuid.
     * The implementation is allowed to assume that and use a plain INSERT.
     */
    public function createFromClaims(AuthClaims $claims): User;

    /**
     * Touch the bookkeeping columns we keep in sync with the issuer:
     *
     *   - last_seen_at = now()
     *   - last_token_jti = $claims->jti  (nullable)
     *   - claims_snapshot = $claims->raw (full audit trail of what we saw)
     *   - email/first_name/last_name = whatever the latest token says
     *
     * This is the "the user just made an authenticated request" hook. It is
     * intentionally idempotent and side-effect-free apart from the row write.
     */
    public function touchFromClaims(User $user, AuthClaims $claims): User;

    /**
     * Apply a validated, allow-listed patch (`first_name`, `last_name`,
     * `email`) to the user. The caller is responsible for having already
     * validated and uniqued the input — the repo just persists it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(User $user, array $attributes): User;
}
