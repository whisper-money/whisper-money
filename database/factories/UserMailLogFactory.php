<?php

namespace Database\Factories;

use App\Enums\DripEmailType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserMailLog>
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
        return [
            'user_id' => User::factory(),
            'email_type' => fake()->randomElement(DripEmailType::cases()),
            'sent_at' => now(),
        ];
    }

    public function welcome(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::Welcome,
        ]);
    }

    public function onboardingReminder(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::OnboardingReminder,
        ]);
    }

    public function promoCode(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::PromoCode,
        ]);
    }

    public function importHelp(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::ImportHelp,
        ]);
    }

    public function feedback(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::Feedback,
        ]);
    }

    public function subscriptionCancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_type' => DripEmailType::SubscriptionCancelled,
        ]);
    }
}
