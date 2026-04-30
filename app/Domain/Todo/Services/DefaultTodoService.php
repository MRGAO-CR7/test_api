<?php

declare(strict_types=1);

namespace App\Domain\Todo\Services;

use App\Domain\Todo\Models\Todo;
use App\Domain\Todo\Repositories\TodoRepositoryInterface;
use App\Support\Audit\AuditLoggerInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Default `TodoServiceInterface`.
 *
 * Exists in the Domain layer (not under `Http`) because the rules it
 * encodes -- "snapshot before, write allow-listed columns, audit the
 * diff" -- are domain rules, not HTTP transport details. Mirrors the
 * shape of `DefaultUserProfileService`.
 */
final class DefaultTodoService implements TodoServiceInterface
{
    /**
     * Columns the audit diff cares about. Kept in lock-step with the
     * repository's own write allow-list (see EloquentTodoRepository).
     *
     * @var list<string>
     */
    private const AUDITED_FIELDS = ['task_name', 'task_details', 'status'];

    public function __construct(
        private readonly TodoRepositoryInterface $todos,
        private readonly AuditLoggerInterface $audit,
    ) {}

    public function list(int $perPage): LengthAwarePaginator
    {
        return $this->todos->paginate($perPage);
    }

    public function get(int $id): ?Todo
    {
        return $this->todos->findById($id);
    }

    public function create(array $attributes): Todo
    {
        $todo = $this->todos->create($attributes);

        $this->audit->todoCreated(
            id: $todo->id,
            attributes: $todo->only(self::AUDITED_FIELDS),
        );

        return $todo;
    }

    public function update(Todo $todo, array $attributes): Todo
    {
        // Snapshot the columns we are allowed to mutate BEFORE writing,
        // so the audit diff is computed against the original values.
        $before = $todo->only(self::AUDITED_FIELDS);

        $updated = $this->todos->update($todo, $attributes);

        $this->audit->todoUpdated(
            id: $updated->id,
            before: $before,
            after: $updated->only(self::AUDITED_FIELDS),
        );

        return $updated;
    }

    public function delete(Todo $todo): void
    {
        $id = $todo->id;

        $this->todos->delete($todo);

        $this->audit->todoDeleted(id: $id);
    }
}
