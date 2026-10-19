<?php

namespace Database\Factories;

use App\Enums\LabelSource;
use App\Enums\MonthlyTargetType;
use App\Enums\SavingsGoalKind;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsGoal>
 */
class SavingsGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'user_id' => User::factory(),
            'label_id' => fn (array $attributes) => Label::factory()->state([
                'name' => $name,
                'source' => LabelSource::SavingsGoal,
                'user_id' => $attributes['user_id'],
            ]),
            'name' => $name,
            'target_amount' => fake()->numberBetween(100000, 5000000),
            'initial_amount' => 0,
            'target_date' => fake()->optional()->dateTimeBetween('+1 month', '+1 year')?->format('Y-m-d'),
        ];
    }

    /**
     * A goal that starts over every calendar month with a fixed target.
     */
    public function monthly(int $targetAmount = 30000): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => SavingsGoalKind::Monthly,
            'target_amount' => 0,
            'target_date' => null,
            'monthly_target_type' => MonthlyTargetType::Amount,
            'monthly_target_amount' => $targetAmount,
            'monthly_target_rate' => null,
        ]);
    }

    /**
     * A monthly goal whose target is a share of the user's usual income.
     */
    public function incomeRate(float $rate = 20.0): static
    {
        return $this->monthly()->state(fn (array $attributes) => [
            'monthly_target_type' => MonthlyTargetType::IncomeRate,
            'monthly_target_amount' => null,
            'monthly_target_rate' => $rate,
        ]);
    }

    /**
     * A frozen goal: its label is gone and its progress is whatever the snapshot
     * says. Mirrors what SavingsGoalController::archive() leaves behind.
     */
    public function archived(int $savedAmount = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
            'archived_saved_amount' => $savedAmount,
        ])->afterCreating(fn (SavingsGoal $goal) => $goal->label?->delete());
    }
}
