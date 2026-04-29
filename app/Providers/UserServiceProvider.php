<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\User\Repositories\EloquentUserRepository;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Domain\User\Services\DefaultUserProfileService;
use App\Domain\User\Services\UserProfileServiceInterface;
use App\Domain\User\Services\UserProvisioner;
use App\Domain\User\Services\UserProvisionerInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the User domain layer.
 *
 *   UserRepositoryInterface
 *       └── EloquentUserRepository (default)
 *
 *   UserProvisionerInterface
 *       └── UserProvisioner (default)
 *
 *   UserProfileServiceInterface
 *       └── DefaultUserProfileService (default)
 *
 * Every binding is `bind()` (not `singleton`) — the implementations are
 * stateless wrappers, and a fresh instance per resolution is both cheap
 * and friendlier to tests that swap in a fake.
 */
final class UserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(UserProvisionerInterface::class, UserProvisioner::class);
        $this->app->bind(UserProfileServiceInterface::class, DefaultUserProfileService::class);
    }
}
