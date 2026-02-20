<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base_currency' => 'usd',
            'date' => fake()->date(),
            'rates' => [
                'eur' => fake()->randomFloat(6, 0.8, 1.2),
                'gbp' => fake()->randomFloat(6, 0.7, 0.9),
                'jpy' => fake()->randomFloat(6, 100, 160),
            ],
        ];
    }
}
