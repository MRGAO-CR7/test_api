<?php

declare(strict_types=1);

namespace Tests\Support\User;

use App\Domain\User\DTOs\AuthClaims;
use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepositoryInterface;
use Illuminate\Support\Carbon;

/**
 * In-memory UserRepositoryInterface for unit tests around UserProvisioner.
 *
 * Records every call so tests can assert *which* repository methods the
 * provisioner exercised (e.g. "exactly one INSERT, one TOUCH" for the JIT
 * code path).
 *
 * NOTE: this fake never goes near the database, so model `id` is faked too.
 * Only properties UserProvisioner / MeController actually read are populated.
 */
/**
 * NOTE: deliberately NOT `final`. Some race-condition tests subclass this
 * with anonymous classes to override `createFromClaims` for one specific
 * call (see UserProvisionerTest). The class is otherwise tightly scoped to
 * the test layer and not part of the production surface area.
 */
class FakeUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    public array $created = [];

    /** @var array<int, User> */
    public array $touched = [];

    /** @var array<int, array{0: User, 1: array<string, mixed>}> */
    public array $updated = [];

    /** @var array<string, User> */
    private array $byUuid = [];

    private int $nextId = 1;

    public function findByUuid(string $uuid): ?User
    {
        return $this->byUuid[$uuid] ?? null;
    }

    public function createFromClaims(AuthClaims $claims): User
    {
        $user = $this->makeUserFromClaims($claims);

        $this->byUuid[$claims->uuid] = $user;
        $this->created[] = $user;

        return $user;
    }

    public function touchFromClaims(User $user, AuthClaims $claims): User
    {
        $user->forceFill([
            'email' => $claims->email,
            'first_name' => $claims->firstName,
            'last_name' => $claims->lastName,
            'last_token_jti' => $claims->jti,
            'last_seen_at' => Carbon::now(),
            'claims_snapshot' => $claims->raw,
        ]);

        $this->touched[] = $user;

        return $user;
    }

    public function updateProfile(User $user, array $attributes): User
    {
        $allowed = array_intersect_key(
            $attributes,
            array_flip(['first_name', 'last_name', 'email']),
        );

        $user->forceFill($allowed);
        $this->updated[] = [$user, $allowed];

        return $user;
    }

    /**
     * Pre-seed a row to simulate "this user already exists".
     */
    public function seed(User $user): void
    {
        if ($user->id === null) {
            $user->id = $this->nextId++;
        }
        $this->byUuid[$user->uuid] = $user;
    }

    private function makeUserFromClaims(AuthClaims $claims): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $this->nextId++,
            'uuid' => $claims->uuid,
            'email' => $claims->email,
            'first_name' => $claims->firstName,
            'last_name' => $claims->lastName,
            'last_token_jti' => $claims->jti,
            'last_seen_at' => Carbon::now(),
            'claims_snapshot' => $claims->raw,
        ]);
        $user->exists = true;

        return $user;
    }
}
