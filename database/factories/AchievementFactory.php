<?php

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Achievement>
 */
class AchievementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'space_id' => Space::factory(),
            'key' => 'net_worth.2',
            'achieved_on' => now()->subMonths(6)->startOfMonth(),
            'value' => 2590000,
            'currency_code' => 'EUR',
        ];
    }

    public function key(string $key): self
    {
        return $this->state(fn (): array => ['key' => $key]);
    }
}
