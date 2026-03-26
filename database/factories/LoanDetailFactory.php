<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\LoanDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanDetail>
 */
class LoanDetailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->state(['type' => AccountType::Loan]),
            'annual_interest_rate' => fake()->randomFloat(3, 1, 8),
            'loan_term_months' => fake()->randomElement([120, 180, 240, 300, 360]),
            'start_date' => fake()->dateTimeBetween('-10 years', 'now'),
            'original_amount' => fake()->numberBetween(5000000, 50000000),
        ];
    }
}
