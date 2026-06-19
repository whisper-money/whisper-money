<?php

namespace Database\Factories;

use App\Enums\DripEmailType;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserMailLog>
 */
class UserMailLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $emailType = fake()->randomElement(DripEmailType::cases());

        return [
            'user_id' => User::factory(),
            'email_type' => $emailType,
            'email_identifier' => $emailType->value,
            'sent_at' => now(),
        ];
    }

    public function welcome(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::Welcome,
            'email_identifier' => DripEmailType::Welcome->value,
        ]);
    }

    public function onboardingReminder(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::OnboardingReminder,
            'email_identifier' => DripEmailType::OnboardingReminder->value,
        ]);
    }

    public function promoCode(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::PromoCode,
            'email_identifier' => DripEmailType::PromoCode->value,
        ]);
    }

    public function importHelp(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::ImportHelp,
            'email_identifier' => DripEmailType::ImportHelp->value,
        ]);
    }

    public function feedback(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::Feedback,
            'email_identifier' => DripEmailType::Feedback->value,
        ]);
    }

    public function subscriptionCancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::SubscriptionCancelled,
            'email_identifier' => DripEmailType::SubscriptionCancelled->value,
        ]);
    }

    public function paywallFollowUp(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::PaywallFollowUp,
            'email_identifier' => DripEmailType::PaywallFollowUp->value,
        ]);
    }
}
