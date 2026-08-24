<?php

namespace Database\Factories;

use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Space>
 */
class SpaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->company(),
            'personal' => false,
        ];
    }

    public function personal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'personal' => true,
            'name' => 'Personal',
        ]);
    }
}
