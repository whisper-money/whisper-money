<?php

namespace Database\Factories;

use App\Enums\LabelSource;
use App\Models\Label;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Label>
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

    /**
     * The label the onboarding quick-expenses step puts on the round monthly
     * figures it stores (see EstimateTransactions).
     */
    public function estimate(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Estimate',
            'color' => 'amber',
            'source' => LabelSource::Estimate,
        ]);
    }
}
