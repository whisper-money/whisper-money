<?php

namespace Database\Factories;

use App\Enums\ForecastAlertLevel;
use App\Models\Account;
use App\Models\ForecastAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastAlert>
 */
class ForecastAlertFactory extends Factory
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
            'account_id' => fn (array $attributes) => Account::factory()->state(['user_id' => $attributes['user_id']]),
            'month' => now()->addMonth()->startOfMonth()->toDateString(),
            'level' => ForecastAlertLevel::Strong,
            'first_seen_at' => now(),
        ];
    }

    public function soft(): static
    {
        return $this->state(fn (array $attributes) => [
            'level' => ForecastAlertLevel::Soft,
        ]);
    }
}
