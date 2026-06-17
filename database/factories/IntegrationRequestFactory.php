<?php

namespace Database\Factories;

use App\Enums\IntegrationRequestStatus;
use App\Models\IntegrationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationRequest>
 */
class IntegrationRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'url' => fake()->url(),
            'status' => IntegrationRequestStatus::Pending,
            'user_id' => User::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => IntegrationRequestStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => IntegrationRequestStatus::Rejected]);
    }
}
