<?php

declare(strict_types=1);

namespace App\Domain\Todo\Repositories;

use App\Domain\Todo\Models\Todo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence boundary for the Todo aggregate.
 *
 * Why an interface (and not just a concrete repository class):
 *
 *   - The service / controller depend on this contract, never on Eloquent.
 *     Swapping to a different store later (or stubbing in tests) does not
 *     ripple into the domain layer.
 *   - Every method here is intentionally narrow and intention-revealing:
 *     callers say *what* they want, not *how* to assemble the query.
 *
 * Lookup contract:
 *
 *   - Soft-deleted rows are NEVER returned. If you ever need to surface a
 *     trashed todo (e.g. an "undo" affordance), add an explicit
 *     `findTrashedById` rather than widening the meaning of `findById`.
 *   - `paginate` returns rows newest-first; this is the order the SPA
 *     renders, and pinning it in the repo means individual call sites
 *     can't accidentally drift.
 */
interface TodoRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Todo>
     */
    public function paginate(int $perPage): LengthAwarePaginator;

    /**
     * @return Todo|null null if the id is unknown OR the row is soft-deleted
     */
    public function findById(int $id): ?Todo;

    /**
     * Insert a fresh row. The caller is responsible for having validated
     * the input (typically via `StoreTodoRequest`); the repo just persists
     * the allow-listed columns and returns the resulting row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Todo;

    /**
     * Apply a validated, allow-listed patch (`task_name`, `status`) to the
     * todo. The caller is responsible for having already validated the
     * input -- the repo just persists it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Todo $todo, array $attributes): Todo;

    /**
     * Soft-delete the row. Idempotent on already-deleted rows is not a
     * supported call -- the service layer guarantees the row is live
     * before getting here.
     */
    public function delete(Todo $todo): void;
}
