<?php

namespace Database\Factories;

use App\Enums\MonthlyTargetType;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoalPeriod>
 */
class SavingsGoalPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'savings_goal_id' => SavingsGoal::factory()->monthly(),
            'month' => now()->startOfMonth()->toDateString(),
            'target_type' => MonthlyTargetType::Amount,
            'target_amount' => 30000,
            'target_rate' => null,
            'resolved_target_amount' => 30000,
        ];
    }
}
