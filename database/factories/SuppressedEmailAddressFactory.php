<?php

namespace Database\Factories;

use App\Enums\SuppressionReason;
use App\Models\SuppressedEmailAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuppressedEmailAddress>
 */
class SuppressedEmailAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'reason' => fake()->randomElement(SuppressionReason::cases()),
            'suppressed_at' => now(),
        ];
    }
}
