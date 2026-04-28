<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a User in API responses.
 *
 * Notes for callers / future maintainers:
 *   - We expose `uuid`, NOT `id`. The local bigint id is implementation detail.
 *     The SPA / BFF only ever reasons about users via uuid, which is the same
 *     identifier auth_service hands out.
 *   - We never return `last_token_jti` or `claims_snapshot` — those are
 *     server-side audit-only fields.
 *   - Timestamps are emitted as ISO-8601. The frontend already parses that
 *     directly into `Date` objects via its existing axios layer.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }
}
