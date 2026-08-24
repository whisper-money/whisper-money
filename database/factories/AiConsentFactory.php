<?php

namespace Database\Factories;

use App\Models\AiConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConsent>
 */
class AiConsentFactory extends Factory
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
            'scope' => AiConsent::SCOPE_FINANCE,
            'version' => (string) config('ai_suggestions.consent_version'),
            'accepted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
