<?php

namespace Database\Factories;

use App\Models\ForecastReviewCompletion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastReviewCompletion>
 */
class ForecastReviewCompletionFactory extends Factory
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
            'completed_at' => now(),
        ];
    }
}
