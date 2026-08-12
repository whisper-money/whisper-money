<?php

namespace Database\Factories;

use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetTransaction>
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
            'budget_period_id' => BudgetPeriod::factory(),
            'amount' => fake()->numberBetween(1000, 50000),
        ];
    }
}
