<?php

namespace Database\Factories;

use App\Models\ForecastItem;
use App\Models\ForecastItemOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastItemOverride>
 */
class ForecastItemOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'forecast_item_id' => ForecastItem::factory(),
            'occurrence_date' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'),
            'amount' => -fake()->numberBetween(1000, 100000),
        ];
    }

    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => null,
            'skipped' => true,
        ]);
    }
}
