<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\Models\User;

/**
 * "What an authenticated user is allowed to do to their own profile".
 *
 * Centralises the pieces that used to live inline in `MeController` —
 * snapshotting the before-state, applying the patch through the
 * repository, emitting the `me.updated` audit event when something
 * actually changed. The controller itself becomes a thin HTTP boundary.
 *
 * Why an interface (not just a concrete class):
 *
 *   - The controller can be unit-tested against a fake without booting
 *     Eloquent or the audit log channel.
 *   - Future profile-write rules (e.g. "changing email triggers a
 *     re-confirmation flow") live behind this contract instead of
 *     accreting on the controller.
 */
interface UserProfileServiceInterface
{
    /**
     * Apply a validated, allow-listed patch to the caller's own profile,
     * emit the corresponding audit event, and return the updated user.
     *
     * The caller is responsible for having validated `$patch` (typically
     * via `UpdateMeRequest`); this service does NOT re-validate, but the
     * underlying repository still hard-allow-lists writable columns as
     * defence in depth.
     *
     * @param  array<string, mixed>  $patch
     */
    public function updateOwnProfile(User $user, array $patch): User;
}
