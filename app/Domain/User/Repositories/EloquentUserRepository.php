<?php

declare(strict_types=1);

namespace App\Domain\User\Repositories;

use App\Domain\User\DTOs\AuthClaims;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Default UserRepository backed by Eloquent + the `users` table.
 *
 * Concurrency notes:
 *
 *   - `createFromClaims` runs inside a short transaction and relies on the
 *     `uuid` UNIQUE index to fail fast on a race (two simultaneous "first
 *     login" requests for the same user). The provisioner catches that case
 *     and re-reads, so we don't need any heavier locking here.
 *   - `touchFromClaims` is a single UPDATE — safe to call concurrently;
 *     last writer wins on every column, which is exactly the semantics we
 *     want for "what the most recent token said".
 */
final class EloquentUserRepository implements UserRepository
{
    public function findByUuid(string $uuid): ?User
    {
        /** @var User|null $user */
        $user = User::query()->where('uuid', $uuid)->first();

        return $user;
    }

    public function createFromClaims(AuthClaims $claims): User
    {
        return DB::transaction(function () use ($claims): User {
            /** @var User $user */
            $user = User::query()->create([
                'uuid' => $claims->uuid,
                'email' => $claims->email,
                'first_name' => $claims->firstName,
                'last_name' => $claims->lastName,
                'last_token_jti' => $claims->jti,
                'last_seen_at' => now(),
                'claims_snapshot' => $claims->raw,
            ]);

            return $user;
        });
    }

    public function touchFromClaims(User $user, AuthClaims $claims): User
    {
        $user->fill([
            'email' => $claims->email,
            'first_name' => $claims->firstName,
            'last_name' => $claims->lastName,
            'last_token_jti' => $claims->jti,
            'last_seen_at' => now(),
            'claims_snapshot' => $claims->raw,
        ])->save();

        return $user;
    }

    public function updateProfile(User $user, array $attributes): User
    {
        // Hard-allow-list the columns we accept here so a buggy form request
        // can never persist arbitrary attributes (defense in depth on top of
        // $fillable + the form request's validated() output).
        $allowed = array_intersect_key(
            $attributes,
            array_flip(['first_name', 'last_name', 'email']),
        );

        if ($allowed !== []) {
            $user->fill($allowed)->save();
        }

        return $user;
    }
}
