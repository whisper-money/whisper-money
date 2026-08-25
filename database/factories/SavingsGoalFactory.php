<?php

namespace Database\Factories;

use App\Enums\LabelSource;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoal>
 */
class SavingsGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'user_id' => User::factory(),
            'label_id' => fn (array $attributes) => Label::factory()->state([
                'name' => $name,
                'source' => LabelSource::SavingsGoal,
                'user_id' => $attributes['user_id'],
            ]),
            'name' => $name,
            'target_amount' => fake()->numberBetween(100000, 5000000),
            'initial_amount' => 0,
            'target_date' => fake()->optional()->dateTimeBetween('+1 month', '+1 year')?->format('Y-m-d'),
        ];
    }
}
