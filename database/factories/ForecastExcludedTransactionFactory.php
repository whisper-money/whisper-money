<?php

namespace Database\Factories;

use App\Enums\ForecastExclusionReason;
use App\Models\ForecastExcludedTransaction;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastExcludedTransaction>
 */
class ForecastExcludedTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'reason' => ForecastExclusionReason::OneOff,
        ];
    }
}
