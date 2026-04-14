<?php

namespace Database\Factories;

use App\Models\UserLead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserLead>
 */
class UserLeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $position = 499;
        $position++;

        return [
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'position' => $position,
            'referral_code' => strtoupper(Str::random(8)),
            'locale' => 'en',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => null,
            'position' => null,
            'referral_code' => null,
        ]);
    }
}
