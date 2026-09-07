<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBalance>
 */
class AccountBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'balance_date' => fake()->date(),
            'balance' => fake()->numberBetween(100000, 10000000),
        ];
    }

    /**
     * Indicate that the balance was produced by the backwards balance walk,
     * which makes it the walk's to overwrite on a later run.
     */
    public function derived(): static
    {
        return $this->state(fn (array $attributes) => [
            'derived' => true,
        ]);
    }

    /**
     * Indicate that the balance has an invested amount.
     */
    public function withInvestedAmount(?int $investedAmount = null): static
    {
        return $this->state(fn (array $attributes) => [
            'invested_amount' => $investedAmount ?? fake()->numberBetween(
                (int) ($attributes['balance'] * 0.5),
                $attributes['balance']
            ),
        ]);
    }
}
