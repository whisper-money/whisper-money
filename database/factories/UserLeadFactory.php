<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserLead>
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
            'position' => $position,
            'referral_code' => strtoupper(Str::random(8)),
            'locale' => 'en',
        ];
    }
}
