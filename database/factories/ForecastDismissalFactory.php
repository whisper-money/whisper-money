<?php

namespace Database\Factories;

use App\Enums\ForecastSuggestionKind;
use App\Models\ForecastDismissal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastDismissal>
 */
class ForecastDismissalFactory extends Factory
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
            'fingerprint' => fake()->unique()->words(3, true),
            'kind' => ForecastSuggestionKind::Recurring,
        ];
    }
}
