<?php

declare(strict_types=1);

namespace App\Domain\Todo\Repositories;

use App\Domain\Todo\Models\Todo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Default `TodoRepositoryInterface` backed by Eloquent + the `todos` table.
 *
 * The write methods hard-allow-list the columns they accept on top of the
 * model's `$fillable`. That looks redundant, but it means a buggy form
 * request that lets an unexpected key through (or a future caller that
 * forgets to validate) can never persist arbitrary attributes.
 */
final class EloquentTodoRepository implements TodoRepositoryInterface
{
    /** @var list<string> */
    private const WRITABLE_COLUMNS = ['task_name', 'task_details', 'status'];

    public function paginate(int $perPage): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Todo> $page */
        $page = Todo::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $page;
    }

    public function findById(int $id): ?Todo
    {
        /** @var Todo|null $todo */
        $todo = Todo::query()->find($id);

        return $todo;
    }

    public function create(array $attributes): Todo
    {
        $allowed = $this->onlyWritable($attributes);

        /** @var Todo $todo */
        $todo = Todo::query()->create($allowed);

        return $todo;
    }

    public function update(Todo $todo, array $attributes): Todo
    {
        $allowed = $this->onlyWritable($attributes);

        if ($allowed !== []) {
            $todo->fill($allowed)->save();
        }

        return $todo;
    }

    public function delete(Todo $todo): void
    {
        $todo->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function onlyWritable(array $attributes): array
    {
        return array_intersect_key(
            $attributes,
            array_flip(self::WRITABLE_COLUMNS),
        );
    }
}
