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
 * Validates the body of `POST /api/v1/test/todos`.
 *
 * Design rules baked into the rule set:
 *
 *   - `task_name` is required on create -- a row with no name is never
 *     useful, and silently defaulting it would just hide a client bug.
 *   - `status` is optional on create. When omitted, the database default
 *     (`Todo::STATUS_TODO`) is used -- the migration and the model agree
 *     on that value, so we don't repeat it here.
 *   - The accepted status values come from `Todo::STATUSES` so the model
 *     is the single source of truth for the lifecycle.
 *
 * Failure handling:
 *
 *   The default Laravel validation failure response is a 422 with Laravel's
 *   own JSON shape. We convert it into our `ApiErrorEnvelope` shape so the
 *   BFF / SPA only ever sees one error contract.
 */
final class StoreTodoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The `auth.user` middleware has already enforced authentication.
        // There is no per-record authorisation in this phase -- todos are
        // a global list that any authenticated caller can write to.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'task_name' => ['required', 'string', 'max:200'],
            'task_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'required', 'string', Rule::in(Todo::STATUSES)],
        ];
    }

    /**
     * Force Laravel's default validator to emit our envelope shape.
     */
    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, array<int, string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(
            ApiErrorEnvelope::validation($errors),
        );
    }
}
