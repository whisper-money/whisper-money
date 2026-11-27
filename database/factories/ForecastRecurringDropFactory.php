<?php

namespace Database\Factories;

use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastRecurringDrop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastRecurringDrop>
 */
class ForecastRecurringDropFactory extends Factory
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
            'forecast_estimate_adjustment_id' => fn (array $attributes) => ForecastEstimateAdjustment::factory()->state(['user_id' => $attributes['user_id']]),
            'charge_key' => sha1(fake()->unique()->uuid()),
            'reduction' => fake()->numberBetween(500, 5000),
            'adjustment_amount' => fake()->numberBetween(0, 50000),
            'created_adjustment' => true,
        ];
    }
}
