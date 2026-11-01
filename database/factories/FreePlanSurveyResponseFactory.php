<?php

namespace Database\Factories;

use App\Enums\FreePlanSurveyReason;
use App\Models\FreePlanSurveyResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FreePlanSurveyResponse>
 */
class FreePlanSurveyResponseFactory extends Factory
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
            'reason' => fake()->randomElement(FreePlanSurveyReason::cases()),
            'details' => null,
            'gate' => fake()->randomElement(FreePlanSurveyResponse::GATES),
        ];
    }
}
