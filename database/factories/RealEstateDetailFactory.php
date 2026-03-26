<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\RealEstateDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RealEstateDetail>
 */
class RealEstateDetailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory()->state(['type' => AccountType::RealEstate]),
            'property_type' => fake()->randomElement(PropertyType::cases()),
            'address' => fake()->address(),
            'purchase_price' => fake()->numberBetween(10000000, 100000000),
            'purchase_date' => fake()->dateTimeBetween('-10 years', 'now'),
            'area_value' => fake()->randomFloat(2, 50, 500),
            'area_unit' => fake()->randomElement(['sqm', 'sqft']),
            'notes' => fake()->optional()->sentence(),
            'revaluation_percentage' => fake()->optional()->randomFloat(2, -5, 10),
        ];
    }

    /**
     * State for a property with a linked loan account.
     */
    public function withLinkedLoan(?Account $loanAccount = null): static
    {
        return $this->state(function (array $attributes) use ($loanAccount) {
            $loan = $loanAccount ?? Account::factory()->state([
                'type' => AccountType::Loan,
                'user_id' => Account::find($attributes['account_id'])?->user_id,
            ]);

            return [
                'linked_loan_account_id' => $loan instanceof Account ? $loan->id : $loan,
            ];
        });
    }
}
