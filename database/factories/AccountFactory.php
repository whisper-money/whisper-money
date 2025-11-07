<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
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
            'name' => fake()->words(2, true).' Account',
            'name_iv' => fake()->regexify('[A-Za-z0-9]{16}'),
            'bank_id' => Bank::factory(),
            'currency_code' => fake()->randomElement(['USD', 'EUR', 'GBP', 'JPY']),
            'type' => fake()->randomElement(AccountType::cases()),
        ];
    }
}
