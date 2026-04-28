<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Local projection of an auth_service / Entra user.
 *
 * Why this does NOT extend `Illuminate\Foundation\Auth\User`:
 *   - We never use Laravel's session/eloquent auth provider. Identity is
 *     established by `VerifyEntraJwt` (Phase 4) verifying an external JWT.
 *   - Extending `Authenticatable` would silently expose `Auth::login($user)`
 *     and password helpers we explicitly don't want. Easier to never inherit
 *     them than to remember to forbid them.
 *
 * Stable identity is `$uuid`. Look up by uuid, never by email — email is
 * mutable from the auth side.
 *
 * @property int $id
 * @property string $uuid
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $last_token_jti
 * @property Carbon|null $last_seen_at
 * @property array<string, mixed>|null $claims_snapshot
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
final class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'email',
        'first_name',
        'last_name',
        'last_token_jti',
        'last_seen_at',
        'claims_snapshot',
    ];

    /**
     * Hide nothing in resource serialization — UserResource is the canonical
     * shape for the API. Anything that should not be exposed simply does not
     * appear in the resource.
     *
     * @var list<string>
     */
    protected $hidden = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'claims_snapshot' => 'array',
        ];
    }

    /**
     * Override the factory resolver because the model lives outside the
     * conventional `App\Models` namespace.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
