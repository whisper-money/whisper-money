<?php

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'account_id' => Account::factory(),
            'category_id' => Category::factory(),
            'description' => fake()->sentence(),
            'transaction_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'amount' => fake()->numberBetween(-100000, 100000),
            'currency_code' => fake()->randomElement(['USD', 'EUR', 'GBP', 'CHF']),
            'notes' => fake()->optional()->paragraph(),
            'source' => TransactionSource::ManuallyCreated,
        ];
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::Imported,
        ]);
    }

    public function enableBanking(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::EnableBanking,
            'external_transaction_id' => fake()->uuid(),
        ]);
    }
}
