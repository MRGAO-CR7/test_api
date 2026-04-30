<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Todo\Models\Todo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Todo>
 */
final class TodoFactory extends Factory
{
    /** @var class-string<Todo> */
    protected $model = Todo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_name' => fake()->sentence(4),
            'task_details' => fake()->boolean(70) ? fake()->paragraph() : null,
            'status' => Todo::STATUS_TODO,
        ];
    }

    public function todo(): self
    {
        return $this->state(fn (): array => ['status' => Todo::STATUS_TODO]);
    }

    public function scheduled(): self
    {
        return $this->state(fn (): array => ['status' => Todo::STATUS_SCHEDULED]);
    }

    public function done(): self
    {
        return $this->state(fn (): array => ['status' => Todo::STATUS_DONE]);
    }
}
