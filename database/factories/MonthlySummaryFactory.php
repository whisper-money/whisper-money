<?php

namespace Database\Factories;

use App\Enums\MonthlySummaryCard;
use App\Models\MonthlySummary;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlySummary>
 */
class MonthlySummaryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'space_id' => Space::factory(),
            'period' => now()->subMonth()->format('Y-m'),
            'payload' => $this->payload(),
            'card' => MonthlySummaryCard::SavingsRate,
            'complete' => true,
        ];
    }

    public function sent(): self
    {
        return $this->state(fn (): array => ['sent_at' => now()]);
    }

    public function shared(): self
    {
        return $this->state(fn (): array => ['share_token' => str()->random(48), 'shared_at' => now()]);
    }

    /**
     * A payload with every section filled, so a test that renders the email or a
     * card exercises all the rows rather than the empty-state path.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'period' => now()->subMonth()->format('Y-m'),
            'currency' => 'EUR',
            'complete' => true,
            'has_history' => true,
            'net_worth' => [
                'current' => 16022305,
                'previous' => 15705805,
                'diff' => 316500,
                'diff_percent' => 2.0,
                'year_percent' => 18.4,
                'history' => $this->history(),
            ],
            'cashflow' => [
                'income' => 385000,
                'expense' => 248195,
                'net' => 136805,
                'savings_rate' => 35.5,
                'previous' => ['income' => 385000, 'expense' => 258800, 'net' => 126200, 'savings_rate' => 32.8],
                'expense_change_percent' => -4.1,
            ],
            'savings_rate_history' => $this->rates(),
            'streak_months' => 5,
            'best_savings_rate_in_year' => true,
            'categories' => [
                'total' => 248195,
                'count' => 14,
                'top_share' => 69.9,
                'top' => [
                    ['name' => 'Housing', 'amount' => 89000, 'share' => 35.9, 'previous_amount' => 81200, 'change_percent' => 9.6],
                    ['name' => 'Groceries', 'amount' => 50240, 'share' => 20.2, 'previous_amount' => 42000, 'change_percent' => 19.6],
                    ['name' => 'Restaurants', 'amount' => 34115, 'share' => 13.8, 'previous_amount' => 38944, 'change_percent' => -12.4],
                ],
            ],
            'biggest_drop' => ['name' => 'Restaurants', 'amount' => 34115, 'previous_amount' => 38944, 'change_percent' => -12.4],
            'invested' => ['contributed' => 3874025, 'value' => 4698025, 'gain' => 824000, 'currency' => 'EUR'],
            'budgets' => ['total' => 6, 'met' => 4, 'overspent' => [
                ['name' => 'Groceries', 'over_by' => 8240],
                ['name' => 'Leisure', 'over_by' => 3110],
            ]],
            'goal' => ['name' => 'Trip to Japan', 'saved' => 310000, 'target' => 500000, 'percent' => 62.0, 'monthly_pace' => 40000, 'eta_month' => now()->addMonths(5)->format('Y-m')],
            'todos' => [
                'uncategorised' => ['count' => 12, 'amount' => 21480],
                'rule_suggestions' => ['count' => 3, 'transactions' => 41],
                'expiring_connections' => [['bank' => 'BBVA', 'days' => 6]],
            ],
            'account_names' => ['BBVA · Salary account', 'MyInvestor · Funds'],
        ];
    }

    /**
     * @return list<array{month: string, value: int}>
     */
    private function history(): array
    {
        return array_map(fn (int $ago): array => [
            'month' => now()->subMonths($ago + 1)->format('Y-m'),
            'value' => 13500000 + (12 - $ago) * 210000,
        ], range(12, 0));
    }

    /**
     * @return list<array{month: string, rate: float}>
     */
    private function rates(): array
    {
        $rates = [11.2, 18.4, 9.6, 4.1, 12.0, 21.5, 16.8, 24.0, 27.2, 30.4, 32.8, 35.5];

        return array_map(fn (int $index): array => [
            'month' => now()->subMonths(12 - $index)->format('Y-m'),
            'rate' => $rates[$index],
        ], array_keys($rates));
    }
}
