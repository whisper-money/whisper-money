<?php

namespace Database\Factories;

use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedFilter>
 */
class SavedFilterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'filters' => [
                'category_ids' => [],
                'account_ids' => [],
                'label_ids' => [],
                'search' => fake()->word(),
            ],
        ];
    }
}
