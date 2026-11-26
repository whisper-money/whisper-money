<?php

namespace Database\Factories;

use App\Enums\ForecastMatchedBy;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastMatch>
 */
class ForecastMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'forecast_item_id' => ForecastItem::factory(),
            'occurrence_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'transaction_id' => function (array $attributes) {
                $item = ForecastItem::query()->findOrFail($attributes['forecast_item_id']);

                return Transaction::factory()->state(['user_id' => $item->user_id, 'account_id' => $item->account_id]);
            },
            'matched_by' => ForecastMatchedBy::Auto,
            'deviation' => 0,
        ];
    }
}
