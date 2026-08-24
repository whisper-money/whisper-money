<?php

namespace Database\Factories;

use App\Enums\CategorySource;
use App\Models\CategoryCorrection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryCorrection>
 */
class CategoryCorrectionFactory extends Factory
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
            'transaction_id' => Transaction::factory(),
            'from_category_id' => null,
            'to_category_id' => null,
            'source' => CategorySource::Ai,
            'confidence' => fake()->randomFloat(3, 0, 1),
        ];
    }
}
