<?php

namespace Database\Factories;

use App\Models\Budget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetPeriod>
 */
class BudgetPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-1 month', '+1 month');
        $endDate = (clone $startDate)->modify('+1 month');

        return [
            'budget_id' => Budget::factory(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'carried_over_amount' => 0,
        ];
    }
}
