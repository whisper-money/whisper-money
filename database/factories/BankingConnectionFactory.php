<?php

namespace Database\Factories;

use App\Enums\BankingConnectionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankingConnection>
 */
class BankingConnectionFactory extends Factory
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
            'provider' => 'enablebanking',
            'authorization_id' => fake()->uuid(),
            'session_id' => fake()->uuid(),
            'aspsp_name' => fake()->company(),
            'aspsp_country' => fake()->randomElement(['ES', 'DE', 'FR', 'IT', 'NL']),
            'status' => BankingConnectionStatus::Active,
            'valid_until' => now()->addDays(90),
            'last_synced_at' => now(),
            'error_message' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankingConnectionStatus::Pending,
            'session_id' => null,
            'last_synced_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankingConnectionStatus::Expired,
            'valid_until' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankingConnectionStatus::Revoked,
        ]);
    }

    public function awaitingMapping(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankingConnectionStatus::AwaitingMapping,
            'last_synced_at' => null,
            'pending_accounts_data' => [
                [
                    'uid' => fake()->uuid(),
                    'currency' => 'EUR',
                    'name' => 'Test Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankingConnectionStatus::Error,
            'error_message' => 'Connection failed: bank returned an error',
        ]);
    }
}
