<?php

namespace Database\Factories;

use App\Models\BudgetPeriodAllocation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetTransaction>
 */
class BudgetTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'budget_period_allocation_id' => BudgetPeriodAllocation::factory(),
            'amount' => fake()->numberBetween(1000, 50000),
        ];
    }
}
