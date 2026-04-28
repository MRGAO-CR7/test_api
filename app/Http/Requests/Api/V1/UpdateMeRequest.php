<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\User\Models\User;
use App\Support\Http\ApiErrorEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validates the body of `PATCH /api/v1/test/me`.
 *
 * Design rules baked into the rule set:
 *
 *   - `uuid` is intentionally NOT mutable from the client. The stable
 *     identity comes from auth_service / Entra and we refuse to forge a
 *     codepath that would let a request rewrite it.
 *   - All editable fields use `sometimes` so a PATCH can carry any subset.
 *     An empty body is a no-op (200 with the unchanged user).
 *   - `email` must remain unique across non-deleted users; we ignore the
 *     current user so they can re-PATCH their own address without tripping
 *     the unique rule.
 *
 * Failure handling:
 *
 *   The default Laravel validation failure response is a 422 with Laravel's
 *   own JSON shape. We convert it into our `ApiErrorEnvelope` shape so the
 *   BFF / SPA only ever sees one error contract.
 */
final class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The `auth.user` middleware has already enforced authentication and
        // attached the current user. There is no per-record authorisation to
        // perform here -- you can only ever PATCH yourself.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->currentUser();

        return [
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:254',
                Rule::unique('users', 'email')
                    ->ignore($user?->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Force Laravel's default validator to emit our envelope shape. We do
     * this here (instead of at the global exception level) so Phase 5 can
     * land without the global handler being wired up yet.
     */
    protected function failedValidation(Validator $validator): void
    {
        /** @var array<string, array<int, string>> $errors */
        $errors = $validator->errors()->toArray();

        throw new HttpResponseException(
            ApiErrorEnvelope::validation($errors),
        );
    }

    private function currentUser(): ?User
    {
        $user = $this->attributes->get('auth.user');

        return $user instanceof User ? $user : null;
    }
}
