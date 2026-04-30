<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Todo\Models\Todo;
use App\Support\Http\ApiErrorEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validates the body of `PATCH /api/v1/test/todos/{todo}`.
 *
 * Design rules baked into the rule set:
 *
 *   - All editable fields use `sometimes` so a PATCH can carry any subset.
 *     An empty body is a no-op (200 with the unchanged todo).
 *   - `id`, `created_at`, `updated_at`, `deleted_at` are intentionally NOT
 *     mutable from the client.
 *   - Allowed status values come from `Todo::STATUSES`, the single source
 *     of truth for the lifecycle.
 */
final class UpdateTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'task_name' => ['sometimes', 'required', 'string', 'max:200'],
            'task_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(Todo::STATUSES)],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, array<int, string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(
            ApiErrorEnvelope::validation($errors),
        );
    }
}
