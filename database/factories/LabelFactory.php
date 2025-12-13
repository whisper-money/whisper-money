<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Label>
 */
class LabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'color' => fake()->randomElement(['amber', 'blue', 'cyan', 'emerald', 'gray', 'green', 'indigo', 'orange', 'pink', 'purple', 'red', 'slate', 'teal', 'yellow']),
            'user_id' => User::factory(),
        ];
    }
}
