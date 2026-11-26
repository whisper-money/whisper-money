<?php

namespace Database\Factories;

use App\Models\ForecastSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastSnapshot>
 */
class ForecastSnapshotFactory extends Factory
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
            'month' => now()->startOfMonth()->toDateString(),
            'payload' => [],
            'frozen_at' => now(),
        ];
    }
}
