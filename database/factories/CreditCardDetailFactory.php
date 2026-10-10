<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CreditCardDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCardDetail>
 */
class CreditCardDetailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $closingDate = now()->startOfMonth()->addDays(fake()->numberBetween(0, 27));

        return [
            'account_id' => Account::factory()->state(['type' => AccountType::CreditCard]),
            'statement_closing_date' => $closingDate->toDateString(),
            'payment_due_date' => $closingDate->copy()->addDays(fake()->numberBetween(5, 20))->toDateString(),
        ];
    }
}
