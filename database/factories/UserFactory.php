<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'email' => fake()->unique()->safeEmail(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'last_token_jti' => null,
            'last_seen_at' => null,
            'claims_snapshot' => null,
        ];
    }

    /**
     * State helper: simulate a user that has just hit the API.
     */
    public function recentlySeen(): self
    {
        return $this->state(fn (): array => [
            'last_seen_at' => now(),
        ]);
    }
}
