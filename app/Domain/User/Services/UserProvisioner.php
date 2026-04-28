<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\DTOs\AuthClaims;
use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepository;
use Illuminate\Database\QueryException;

/**
 * Just-In-Time user provisioning.
 *
 * This is the only place in the application that turns a *verified* set of
 * JWT claims into a local `users` row. Both happy paths converge here:
 *
 *   - First time we ever see a uuid -> INSERT a new row, return it.
 *   - We've seen this uuid before     -> UPDATE bookkeeping columns
 *                                        (`last_seen_at`, `last_token_jti`,
 *                                        `claims_snapshot`, plus latest
 *                                        email / first_name / last_name).
 *
 * Race protection:
 *
 *   Two simultaneous "first" requests for the same brand-new uuid will both
 *   miss `findByUuid`. One INSERT will win, the other will trip the UNIQUE
 *   constraint on `uuid`. We catch that case, re-read, and treat it as the
 *   normal returning-user path.
 *
 * Hard rules:
 *
 *   - The caller MUST pass already-verified `AuthClaims` (constructed by
 *     `EntraJwtVerifier`). This service performs zero token validation.
 *   - This service NEVER calls auth_service. The whole point of JIT is to
 *     stay fully stateless w.r.t. the auth issuer.
 */
final class UserProvisioner
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function provision(AuthClaims $claims): User
    {
        $existing = $this->users->findByUuid($claims->uuid);

        if ($existing !== null) {
            return $this->users->touchFromClaims($existing, $claims);
        }

        try {
            return $this->users->createFromClaims($claims);
        } catch (QueryException $e) {
            // Concurrent first-login race: another request just inserted
            // the row we were about to insert. Re-read and treat it as
            // the normal "user already exists" path. We deliberately do
            // not narrow the exception further -- a UNIQUE-constraint
            // violation manifests differently across DB drivers, but in
            // *every* case `findByUuid` returning a row after the failed
            // insert proves the race interpretation, and re-throwing
            // otherwise preserves real DB errors.
            $reread = $this->users->findByUuid($claims->uuid);
            if ($reread === null) {
                throw $e;
            }

            return $this->users->touchFromClaims($reread, $claims);
        }
    }
}
