<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
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
            'description_iv' => fake()->regexify('[A-Za-z0-9]{16}'),
            'transaction_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'amount' => fake()->randomFloat(2, -1000, 1000),
            'currency_code' => fake()->randomElement(['USD', 'EUR', 'GBP', 'JPY']),
            'notes' => fake()->optional()->paragraph(),
            'notes_iv' => fake()->optional()->regexify('[A-Za-z0-9]{16}'),
        ];
    }
}
