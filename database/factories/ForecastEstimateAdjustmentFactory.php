<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Category;
use App\Models\ForecastEstimateAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastEstimateAdjustment>
 */
class ForecastEstimateAdjustmentFactory extends Factory
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
            'account_id' => fn (array $attributes) => Account::factory()->state(['user_id' => $attributes['user_id']]),
            'category_id' => fn (array $attributes) => Category::factory()->state(['user_id' => $attributes['user_id']]),
            'starts_on' => now()->startOfMonth()->toDateString(),
            'amount' => fake()->numberBetween(1000, 100000),
        ];
    }
}
