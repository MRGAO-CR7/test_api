<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\User\Repositories\EloquentUserRepository;
use App\Domain\User\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the User domain layer.
 *
 *   UserRepository (interface)
 *       └── EloquentUserRepository (default)
 *
 * `UserProvisioner` is intentionally NOT registered here -- it has no
 * configuration of its own and Laravel's auto-resolution picks it up via
 * constructor injection.
 */
final class UserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind (not singleton). Eloquent itself manages connection reuse;
        // the repository is a thin stateless wrapper, so a fresh instance
        // per resolution is both cheap and friendlier to tests that swap
        // it for a fake.
        $this->app->bind(UserRepository::class, EloquentUserRepository::class);
    }
}
