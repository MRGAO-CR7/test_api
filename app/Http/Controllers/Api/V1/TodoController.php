<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Todo\Services\TodoServiceInterface;
use App\Http\Requests\Api\V1\StoreTodoRequest;
use App\Http\Requests\Api\V1\UpdateTodoRequest;
use App\Http\Resources\TodoResource;
use App\Support\Http\ApiErrorEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The todo-list endpoints.
 *
 *   GET    /api/v1/test/todos          -> paginated list, newest first
 *   POST   /api/v1/test/todos          -> create a todo
 *   GET    /api/v1/test/todos/{todo}   -> read a single todo
 *   PATCH  /api/v1/test/todos/{todo}   -> partial update of mutable fields
 *   DELETE /api/v1/test/todos/{todo}   -> soft-delete the todo
 *
 * What this controller is NOT:
 *
 *   - It does NOT run any auth check itself -- by the time we get here,
 *     `auth.jwt` and `auth.user` have already produced a `User`.
 *   - It does NOT know about repositories, the audit log, or the status
 *     enum. Those rules live behind `TodoServiceInterface`; this class
 *     is just the HTTP boundary.
 *   - It does NOT use Laravel's implicit route-model binding because we
 *     want explicit control over the soft-deleted -> 404 envelope shape
 *     (the framework's default 404 page is wrong; our `ApiErrorEnvelope`
 *     `not_found` response is right).
 */
final class TodoController
{
    /** Default page size for the index endpoint. */
    private const DEFAULT_PER_PAGE = 20;

    /** Hard cap on page size so a malicious / careless client can't ask for the whole table. */
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TodoServiceInterface $todos,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = $this->resolvePerPage($request);

        return TodoResource::collection(
            $this->todos->list($perPage),
        );
    }

    public function store(StoreTodoRequest $request): JsonResponse
    {
        $todo = $this->todos->create($request->validated());

        return TodoResource::make($todo)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(int $todo): TodoResource|JsonResponse
    {
        $found = $this->todos->get($todo);
        if ($found === null) {
            return $this->notFound();
        }

        return TodoResource::make($found);
    }

    public function update(UpdateTodoRequest $request, int $todo): TodoResource|JsonResponse
    {
        $found = $this->todos->get($todo);
        if ($found === null) {
            return $this->notFound();
        }

        return TodoResource::make(
            $this->todos->update($found, $request->validated()),
        );
    }

    public function destroy(int $todo): Response|JsonResponse
    {
        $found = $this->todos->get($todo);
        if ($found === null) {
            return $this->notFound();
        }

        $this->todos->delete($found);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * Pull and clamp `?per_page` from the query string. Anything missing,
     * non-numeric, or out of range falls back to the default. We never
     * 422 on a bad pagination hint -- it's a soft preference, not a
     * required input.
     */
    private function resolvePerPage(Request $request): int
    {
        $raw = $request->query('per_page');
        if (! is_numeric($raw)) {
            return self::DEFAULT_PER_PAGE;
        }

        $perPage = (int) $raw;
        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    private function notFound(): JsonResponse
    {
        return ApiErrorEnvelope::notFound(
            code: 'todo_not_found',
            message: 'Todo not found.',
        );
    }
}
