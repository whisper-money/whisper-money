<?php

namespace Database\Factories;

use App\Models\FailedInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FailedInvoice>
 */
class FailedInvoiceFactory extends Factory
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
            'stripe_invoice_id' => 'in_'.fake()->unique()->bothify('??????????'),
            'stripe_subscription_id' => 'sub_'.fake()->bothify('??????????'),
            'amount_due' => 599,
            'currency' => 'eur',
            'billing_interval' => 'month',
            'is_first_charge_after_trial' => false,
            'attempt_count' => 1,
            'payment_intent_id' => null,
            'first_failed_at' => now(),
            'next_payment_attempt_at' => now()->addDays(2),
            'action_required_at' => null,
            'resolved_at' => null,
        ];
    }

    public function awaitingConfirmation(string $paymentIntentId = 'pi_123'): static
    {
        return $this->state([
            'payment_intent_id' => $paymentIntentId,
            'action_required_at' => now(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(['resolved_at' => now()]);
    }
}
