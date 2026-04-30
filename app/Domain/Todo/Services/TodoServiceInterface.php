<?php

declare(strict_types=1);

namespace App\Domain\Todo\Services;

use App\Domain\Todo\Models\Todo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * "What an authenticated caller is allowed to do with the todo list".
 *
 * Centralises the pieces that would otherwise live inline in the
 * controller -- snapshotting the before-state on writes, applying the
 * patch through the repository, emitting the matching audit events.
 * The controller itself stays a thin HTTP boundary.
 *
 * Why an interface (not just a concrete class):
 *
 *   - The controller can be unit-tested against a fake without booting
 *     Eloquent or the audit log channel.
 *   - Future write rules (e.g. "marking a todo done locks `task_name`")
 *     live behind this contract instead of accreting on the controller.
 */
interface TodoServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, Todo>
     */
    public function list(int $perPage): LengthAwarePaginator;

    public function get(int $id): ?Todo;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Todo;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Todo $todo, array $attributes): Todo;

    public function delete(Todo $todo): void;
}
