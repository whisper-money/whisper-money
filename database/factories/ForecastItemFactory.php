<?php

namespace Database\Factories;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Models\Account;
use App\Models\ForecastItem;
use App\Models\ForecastItemVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastItem>
 */
class ForecastItemFactory extends Factory
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
            'account_id' => fn (array $attributes) => Account::factory()->state(['user_id' => $attributes['user_id']]),
            'name' => fake()->words(2, true),
            'kind' => ForecastItemKind::Recurring,
            'frequency' => ForecastFrequency::Monthly,
            'anchor_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'source' => ForecastItemSource::Manual,
            'confidence' => ForecastConfidence::High,
        ];
    }

    public function frequency(ForecastFrequency $frequency): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => $frequency,
            'kind' => $frequency === ForecastFrequency::Once ? ForecastItemKind::OneOff : ForecastItemKind::Recurring,
        ]);
    }

    /**
     * A transfer to another of the same user's accounts.
     */
    public function transfer(?Account $destination = null): static
    {
        return $this->state([
            'destination_account_id' => $destination?->id
                ?? fn (array $attributes) => Account::factory()->state(['user_id' => $attributes['user_id']]),
        ]);
    }

    /**
     * A detected line the user still has to confirm.
     */
    public function suggested(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => ForecastItemSource::Suggested,
            'confidence' => ForecastConfidence::Review,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Gives the line a signed amount, in force from its anchor date.
     */
    public function amount(int $amount): static
    {
        return $this->afterCreating(fn (ForecastItem $item) => ForecastItemVersion::factory()->create([
            'forecast_item_id' => $item->id,
            'effective_from' => $item->anchor_date,
            'amount' => $amount,
        ]));
    }
}
