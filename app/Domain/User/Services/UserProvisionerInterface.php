<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\DTOs\AuthClaims;
use App\Domain\User\Models\User;

/**
 * Just-In-Time user provisioning contract.
 *
 * Production wiring is `UserProvisioner`. The middleware that bridges the
 * verified-token world to the local-user world (`ResolveCurrentUser`)
 * depends on this contract rather than the concrete class so:
 *
 *   - Tests can bind an in-memory implementation with no DB.
 *   - A future migration (e.g. fronting the local table with a remote
 *     directory) is contained behind one binding.
 */
interface UserProvisionerInterface
{
    /**
     * Turn a verified set of JWT claims into a local `users` row.
     *
     * Implementations MUST be idempotent: repeated calls for the same
     * `uuid` return the same logical row, with bookkeeping columns
     * (`last_seen_at`, `last_token_jti`, `claims_snapshot`, plus the
     * latest mutable profile fields from the token) refreshed each time.
     */
    public function provision(AuthClaims $claims): User;
}
