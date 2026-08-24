<?php

namespace Database\Factories;

use App\Enums\SuggestionRunStatus;
use App\Models\SuggestionRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SuggestionRun>
 */
class SuggestionRunFactory extends Factory
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
            'status' => SuggestionRunStatus::Completed,
            'transactions_considered' => $this->faker->numberBetween(50, 500),
            'suggestions_count' => $this->faker->numberBetween(1, 15),
            'error' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => SuggestionRunStatus::Pending,
            'suggestions_count' => 0,
        ]);
    }

    public function empty(): static
    {
        return $this->state(fn (): array => [
            'status' => SuggestionRunStatus::Empty,
            'suggestions_count' => 0,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => SuggestionRunStatus::Failed,
            'suggestions_count' => 0,
            'error' => 'Generation failed.',
        ]);
    }
}
