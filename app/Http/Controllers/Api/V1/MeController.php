<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepository;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Support\Audit\AuditLog;
use Illuminate\Http\Request;
use LogicException;

/**
 * The "current user" endpoints.
 *
 *   GET   /api/v1/test/me   -> the authenticated user's profile
 *   PATCH /api/v1/test/me   -> partial update of mutable profile fields
 *
 * What this controller is NOT:
 *
 *   - It is NOT a generic /users CRUD. There is no admin layer in this
 *     phase; the only thing a caller can read or change is their own
 *     record, and "themselves" is determined exclusively by the verified
 *     JWT (via the `auth.user` middleware).
 *   - It does NOT run any auth check itself -- by the time we get here,
 *     `auth.jwt` and `auth.user` have already produced a `User`.
 */
final class MeController
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function show(Request $request): UserResource
    {
        return new UserResource($this->currentUser($request));
    }

    public function update(UpdateMeRequest $request): UserResource
    {
        $user = $this->currentUser($request);

        // Snapshot only the columns we are allowed to mutate; that's also
        // exactly the set the audit log cares about. Doing this BEFORE
        // updateProfile keeps the diff calculation honest.
        $before = $user->only(['first_name', 'last_name', 'email']);

        $updated = $this->users->updateProfile(
            $user,
            $request->validated(),
        );

        AuditLog::profileUpdated(
            uuid: $updated->uuid,
            before: $before,
            after: $updated->only(['first_name', 'last_name', 'email']),
        );

        return new UserResource($updated);
    }

    /**
     * Pull the resolved User out of the request. If this throws, an upstream
     * middleware was misconfigured -- never a client problem.
     */
    private function currentUser(Request $request): User
    {
        $user = $request->attributes->get('auth.user');
        if (! $user instanceof User) {
            // Defensive: the only way to reach this is misordering of
            // middlewares. Throw rather than serve a half-broken response.
            throw new LogicException(
                'auth.user middleware must run before MeController.',
            );
        }

        return $user;
    }
}
