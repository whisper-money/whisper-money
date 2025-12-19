<?php

namespace Database\Factories;

use App\Models\BudgetCategory;
use App\Models\BudgetPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetPeriodAllocation>
 */
class BudgetPeriodAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_period_id' => BudgetPeriod::factory(),
            'budget_category_id' => BudgetCategory::factory(),
            'allocated_amount' => fake()->numberBetween(10000, 100000),
        ];
    }
}
