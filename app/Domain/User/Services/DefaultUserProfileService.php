<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Support\Audit\AuditLoggerInterface;

/**
 * Default `UserProfileServiceInterface`.
 *
 * Exists in the Domain layer (not under `Http`) because the rules it
 * encodes — "snapshot before, write allow-listed columns, audit the
 * diff" — are domain rules, not HTTP transport details.
 */
final class DefaultUserProfileService implements UserProfileServiceInterface
{
    /**
     * Columns the audit log + diff care about. Kept in lock-step with the
     * repository's own write allow-list (see EloquentUserRepository).
     *
     * @var list<string>
     */
    private const AUDITED_FIELDS = ['first_name', 'last_name', 'email'];

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditLoggerInterface $audit,
    ) {}

    public function updateOwnProfile(User $user, array $patch): User
    {
        // Snapshot the columns we are allowed to mutate BEFORE writing,
        // so the audit diff is computed against the original values.
        $before = $user->only(self::AUDITED_FIELDS);

        $updated = $this->users->updateProfile($user, $patch);

        $this->audit->profileUpdated(
            uuid: $updated->uuid,
            before: $before,
            after: $updated->only(self::AUDITED_FIELDS),
        );

        return $updated;
    }
}
