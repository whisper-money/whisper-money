<?php

namespace Database\Factories;

use App\Enums\BudgetPeriodType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Budget>
 */
class BudgetFactory extends Factory
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
            'name' => fake()->words(2, true).' Budget',
            'period_type' => fake()->randomElement(BudgetPeriodType::cases()),
            'period_duration' => null,
            'period_start_day' => 1,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => BudgetPeriodType::Monthly,
            'period_start_day' => fake()->numberBetween(1, 28),
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => BudgetPeriodType::Weekly,
            'period_start_day' => fake()->numberBetween(0, 6),
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => BudgetPeriodType::Custom,
            'period_duration' => fake()->numberBetween(7, 90),
            'period_start_day' => fake()->numberBetween(1, 28),
        ]);
    }
}
