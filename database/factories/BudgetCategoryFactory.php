<?php

namespace Database\Factories;

use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetCategory>
 */
class BudgetCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'category_id' => Category::factory(),
            'rollover_type' => fake()->randomElement(RolloverType::cases()),
        ];
    }
}
