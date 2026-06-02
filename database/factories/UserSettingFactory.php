<?php

namespace Database\Factories;

use App\Enums\ChartColorScheme;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSetting>
 */
class UserSettingFactory extends Factory
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
            'chart_color_scheme' => ChartColorScheme::Colorful,
            'include_loans_in_net_worth_chart' => true,
            'include_real_estate_in_net_worth_chart' => true,
            'notify_on_bank_transactions_synced' => true,
        ];
    }

    public function withScheme(ChartColorScheme $scheme): static
    {
        return $this->state(fn (array $attributes) => [
            'chart_color_scheme' => $scheme,
        ]);
    }
}
