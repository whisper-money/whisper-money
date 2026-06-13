<?php

namespace Database\Factories;

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
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
            'name' => fake()->words(2, true).' Budget',
            'period_type' => fake()->randomElement(BudgetPeriodType::cases()),
            'period_start_day' => 1,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => BudgetPeriodType::Monthly,
            'period_start_day' => fake()->numberBetween(1, 28),
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => BudgetPeriodType::Weekly,
            'period_start_day' => fake()->numberBetween(0, 6),
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => BudgetPeriodType::Yearly,
            'period_start_day' => 1,
        ]);
    }

    public function catchAll(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_catch_all' => true,
        ]);
    }

    /**
     * Attach one or more categories to the budget after creation.
     *
     * @param  Category|array<int, Category|string>  $categories
     */
    public function forCategories(Category|array $categories): static
    {
        $ids = collect(Arr::wrap($categories))
            ->map(fn ($category) => $category instanceof Category ? $category->id : $category)
            ->all();

        return $this->afterCreating(function (Budget $budget) use ($ids) {
            $budget->categories()->syncWithoutDetaching($ids);
        });
    }

    /**
     * Attach one or more labels to the budget after creation.
     *
     * @param  Label|array<int, Label|string>  $labels
     */
    public function forLabels(Label|array $labels): static
    {
        $ids = collect(Arr::wrap($labels))
            ->map(fn ($label) => $label instanceof Label ? $label->id : $label)
            ->all();

        return $this->afterCreating(function (Budget $budget) use ($ids) {
            $budget->labels()->syncWithoutDetaching($ids);
        });
    }
}
