<?php

namespace Database\Factories;

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankingConnection>
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

    public function indexaCapital(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'indexacapital',
            'authorization_id' => null,
            'session_id' => null,
            'api_token' => 'test-indexa-token-'.fake()->uuid(),
            'aspsp_name' => 'Indexa Capital',
            'aspsp_country' => 'ES',
            'aspsp_logo' => '/images/banks/logos/indexa-capital.jpg',
            'valid_until' => null,
        ]);
    }

    public function binance(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'binance',
            'authorization_id' => null,
            'session_id' => null,
            'api_token' => 'test-binance-api-key-'.fake()->uuid(),
            'api_secret' => 'test-binance-api-secret-'.fake()->uuid(),
            'aspsp_name' => 'Binance',
            'aspsp_country' => 'ES',
            'aspsp_logo' => 'https://whisper.money/storage/banks/logos/t1h5rqi19dJTPl6ZadziPjNwm0lrcdTFBRzB3iCy.png',
            'valid_until' => null,
        ]);
    }

    public function bitpanda(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'bitpanda',
            'authorization_id' => null,
            'session_id' => null,
            'api_token' => 'test-bitpanda-api-key-'.fake()->uuid(),
            'api_secret' => null,
            'aspsp_name' => 'Bitpanda',
            'aspsp_country' => 'ES',
            'aspsp_logo' => 'https://whisper.money/storage/banks/logos/7Y6gl0gaFH1mStJMcUQ9VpgzX1kduyumm0dDhGlf.png',
            'valid_until' => null,
        ]);
    }

    public function coinbase(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'coinbase',
            'authorization_id' => null,
            'session_id' => null,
            'api_token' => 'organizations/org-'.fake()->uuid().'/apiKeys/key-'.fake()->uuid(),
            'api_secret' => "-----BEGIN EC PRIVATE KEY-----\nFAKEKEY\n-----END EC PRIVATE KEY-----",
            'aspsp_name' => 'Coinbase',
            'aspsp_country' => 'ES',
            'aspsp_logo' => 'https://whisper.money/storage/banks/logos/coinbase.png',
            'valid_until' => null,
        ]);
    }

    public function wise(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'wise',
            'authorization_id' => null,
            'session_id' => null,
            'api_token' => 'test-wise-api-token-'.fake()->uuid(),
            'api_secret' => null,
            'aspsp_name' => 'Wise',
            'aspsp_country' => 'BE',
            'aspsp_logo' => 'https://whisper.money/storage/banks/logos/wise.png',
            'valid_until' => null,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankingConnectionStatus::Error,
            'error_message' => 'Connection failed: bank returned an error',
            'consecutive_sync_failures' => 1,
        ]);
    }
}
