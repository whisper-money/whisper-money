<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Bank;
use App\Models\BankingConnection;
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
            'name_iv' => null,
            'encrypted' => false,
            'bank_id' => Bank::factory(),
            'currency_code' => fake()->randomElement(['USD', 'EUR', 'GBP', 'JPY']),
            'type' => fake()->randomElement(AccountType::cases()),
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'banking_connection_id' => BankingConnection::factory(),
            'external_account_id' => fake()->uuid(),
        ]);
    }

    public function linked(): static
    {
        return $this->state(fn (array $attributes) => [
            'banking_connection_id' => BankingConnection::factory(),
            'external_account_id' => fake()->uuid(),
            'linked_at' => now(),
        ]);
    }

    public function realEstate(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AccountType::RealEstate,
            'bank_id' => null,
        ]);
    }
}
