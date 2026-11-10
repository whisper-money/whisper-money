<?php

namespace Database\Factories;

use App\Enums\CancellationOutcome;
use App\Enums\CancellationReason;
use App\Models\CancellationSurveyResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CancellationSurveyResponse>
 */
class CancellationSurveyResponseFactory extends Factory
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
            'stripe_subscription_id' => 'sub_'.fake()->bothify('??????????'),
            'reason' => fake()->randomElement(CancellationReason::cases()),
            'details' => null,
            'offer_shown' => null,
            'offer_accepted' => null,
            'outcome' => null,
            'on_trial' => false,
        ];
    }

    public function canceled(): static
    {
        return $this->state(['outcome' => CancellationOutcome::Canceled]);
    }
}
