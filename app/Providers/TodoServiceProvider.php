<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Todo\Repositories\EloquentTodoRepository;
use App\Domain\Todo\Repositories\TodoRepositoryInterface;
use App\Domain\Todo\Services\DefaultTodoService;
use App\Domain\Todo\Services\TodoServiceInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Todo domain layer.
 *
 *   TodoRepositoryInterface
 *       └── EloquentTodoRepository (default)
 *
 *   TodoServiceInterface
 *       └── DefaultTodoService (default)
 *
 * Every binding is `bind()` (not `singleton`) -- the implementations are
 * stateless wrappers, and a fresh instance per resolution is both cheap
 * and friendlier to tests that swap in a fake.
 */
final class TodoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TodoRepositoryInterface::class, EloquentTodoRepository::class);
        $this->app->bind(TodoServiceInterface::class, DefaultTodoService::class);
    }
}
