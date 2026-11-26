<?php

namespace Database\Factories;

use App\Models\ForecastItem;
use App\Models\ForecastItemVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastItemVersion>
 */
class ForecastItemVersionFactory extends Factory
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
            'effective_from' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'amount' => -fake()->numberBetween(1000, 100000),
        ];
    }
}
