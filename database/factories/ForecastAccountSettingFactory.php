<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ForecastAccountSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastAccountSetting>
 */
class ForecastAccountSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'included' => true,
            'minimum_balance' => null,
            'annual_rate_bps' => null,
        ];
    }
}
