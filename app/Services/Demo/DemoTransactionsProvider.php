<?php

namespace App\Services\Demo;

use App\Enums\TransactionSource;
use Carbon\Carbon;

class DemoTransactionsProvider
{
    /**
     * @var array<int, array{description: string, amount_min: int, amount_max: int, category_name: string, frequency: string}>
     */
    private const TRANSACTION_TEMPLATES = [
        ['description' => 'Whole Foods Market', 'amount_min' => -15000, 'amount_max' => -8000, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Trader Joe\'s', 'amount_min' => -8500, 'amount_max' => -4500, 'category_name' => 'Groceries', 'frequency' => 'weekly'],
        ['description' => 'Costco Wholesale', 'amount_min' => -25000, 'amount_max' => -12000, 'category_name' => 'Groceries', 'frequency' => 'monthly'],
        ['description' => 'Starbucks Coffee', 'amount_min' => -850, 'amount_max' => -450, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent'],
        ['description' => 'Dunkin Donuts', 'amount_min' => -650, 'amount_max' => -350, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent'],
        ['description' => 'Shell Gas Station', 'amount_min' => -6500, 'amount_max' => -3500, 'category_name' => 'Fuel', 'frequency' => 'biweekly'],
        ['description' => 'Chevron Gas', 'amount_min' => -5800, 'amount_max' => -3200, 'category_name' => 'Fuel', 'frequency' => 'biweekly'],
        ['description' => 'Salary Deposit - ACME Corp', 'amount_min' => 485000, 'amount_max' => 485000, 'category_name' => 'Salary', 'frequency' => 'monthly'],
        ['description' => 'Electric Company - Monthly Bill', 'amount_min' => -18500, 'amount_max' => -9500, 'category_name' => 'Electricity', 'frequency' => 'monthly'],
        ['description' => 'Water & Sewer Utility', 'amount_min' => -7500, 'amount_max' => -4500, 'category_name' => 'Water', 'frequency' => 'monthly'],
        ['description' => 'Natural Gas Bill', 'amount_min' => -12000, 'amount_max' => -4500, 'category_name' => 'Natural gas', 'frequency' => 'monthly'],
        ['description' => 'Comcast Internet & Cable', 'amount_min' => -15999, 'amount_max' => -12999, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly'],
        ['description' => 'T-Mobile Wireless', 'amount_min' => -8500, 'amount_max' => -7500, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly'],
        ['description' => 'Netflix Subscription', 'amount_min' => -1599, 'amount_max' => -1599, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Spotify Premium', 'amount_min' => -1099, 'amount_max' => -1099, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Amazon Prime', 'amount_min' => -1499, 'amount_max' => -1499, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Disney+ Subscription', 'amount_min' => -1099, 'amount_max' => -1099, 'category_name' => 'Online services', 'frequency' => 'monthly'],
        ['description' => 'Chipotle Mexican Grill', 'amount_min' => -1800, 'amount_max' => -1200, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'weekly'],
        ['description' => 'Olive Garden Restaurant', 'amount_min' => -8500, 'amount_max' => -4500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'Thai Palace Dinner', 'amount_min' => -6500, 'amount_max' => -3500, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly'],
        ['description' => 'DoorDash Delivery', 'amount_min' => -4500, 'amount_max' => -2500, 'category_name' => 'Food delivery', 'frequency' => 'weekly'],
        ['description' => 'Uber Eats Order', 'amount_min' => -3800, 'amount_max' => -2200, 'category_name' => 'Food delivery', 'frequency' => 'weekly'],
        ['description' => 'Amazon.com Purchase', 'amount_min' => -15000, 'amount_max' => -2500, 'category_name' => 'Online transactions', 'frequency' => 'weekly'],
        ['description' => 'Target Store', 'amount_min' => -12000, 'amount_max' => -3500, 'category_name' => 'Household goods', 'frequency' => 'biweekly'],
        ['description' => 'Walmart Supercenter', 'amount_min' => -8500, 'amount_max' => -2500, 'category_name' => 'Other groceries', 'frequency' => 'biweekly'],
        ['description' => 'CVS Pharmacy', 'amount_min' => -4500, 'amount_max' => -1500, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'monthly'],
        ['description' => 'Walgreens', 'amount_min' => -3500, 'amount_max' => -1200, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'monthly'],
        ['description' => 'Planet Fitness Monthly', 'amount_min' => -2499, 'amount_max' => -2499, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly'],
        ['description' => 'ATM Cash Withdrawal', 'amount_min' => -30000, 'amount_max' => -10000, 'category_name' => 'Cash withdrawal', 'frequency' => 'biweekly'],
        ['description' => 'State Farm Insurance', 'amount_min' => -15800, 'amount_max' => -12500, 'category_name' => 'Insurance', 'frequency' => 'monthly'],
        ['description' => 'Rent Payment', 'amount_min' => -195000, 'amount_max' => -195000, 'category_name' => 'Rent and maintanence', 'frequency' => 'monthly'],
        ['description' => 'Uber Ride', 'amount_min' => -3500, 'amount_max' => -1200, 'category_name' => 'Transportation expenses', 'frequency' => 'weekly'],
        ['description' => 'Lyft Ride', 'amount_min' => -2800, 'amount_max' => -1000, 'category_name' => 'Transportation expenses', 'frequency' => 'weekly'],
        ['description' => 'Parking Garage', 'amount_min' => -2500, 'amount_max' => -800, 'category_name' => 'Parking', 'frequency' => 'weekly'],
        ['description' => 'H&M Clothing', 'amount_min' => -8500, 'amount_max' => -3500, 'category_name' => 'Clothing and shoes', 'frequency' => 'monthly'],
        ['description' => 'Nike Store', 'amount_min' => -15000, 'amount_max' => -6500, 'category_name' => 'Clothing and shoes', 'frequency' => 'quarterly'],
        ['description' => 'AMC Movie Theater', 'amount_min' => -3500, 'amount_max' => -1500, 'category_name' => 'Theatre, music, cinema', 'frequency' => 'monthly'],
        ['description' => 'Barnes & Noble Books', 'amount_min' => -4500, 'amount_max' => -1500, 'category_name' => 'Books, newspapers, magazines', 'frequency' => 'monthly'],
        ['description' => 'Interest Payment', 'amount_min' => 250, 'amount_max' => 850, 'category_name' => 'Other incoming payments', 'frequency' => 'monthly'],
        ['description' => 'Dividend - VTI ETF', 'amount_min' => 15000, 'amount_max' => 25000, 'category_name' => 'Other incoming payments', 'frequency' => 'quarterly'],
        ['description' => 'Transfer to Savings', 'amount_min' => -50000, 'amount_max' => -25000, 'category_name' => 'Own account', 'frequency' => 'monthly'],
        ['description' => 'Birthday Gift from Mom', 'amount_min' => 10000, 'amount_max' => 25000, 'category_name' => 'From account of relatives', 'frequency' => 'yearly'],
        ['description' => 'Venmo from Friend', 'amount_min' => 2000, 'amount_max' => 8000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly'],
    ];

    /**
     * Generate 12 months of realistic transactions.
     *
     * @return array<int, array{description: string, transaction_date: string, amount: int, currency_code: string, notes: string|null, notes_iv: string|null, source: TransactionSource, category_name: string}>
     */
    public function getTransactions(): array
    {
        $transactions = [];
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subMonths(12);

        foreach (self::TRANSACTION_TEMPLATES as $template) {
            $dates = $this->generateDatesForFrequency($template['frequency'], $startDate, $endDate);

            foreach ($dates as $date) {
                $amount = $template['amount_min'] === $template['amount_max']
                    ? $template['amount_min']
                    : rand($template['amount_min'], $template['amount_max']);

                $transactions[] = [
                    'description' => $template['description'],
                    'transaction_date' => $date->format('Y-m-d'),
                    'amount' => $amount,
                    'currency_code' => 'USD',
                    'notes' => null,
                    'notes_iv' => null,
                    'source' => TransactionSource::ManuallyCreated,
                    'category_name' => $template['category_name'],
                ];
            }
        }

        usort($transactions, fn ($a, $b) => strcmp($b['transaction_date'], $a['transaction_date']));

        return $transactions;
    }

    /**
     * @return array<int, Carbon>
     */
    private function generateDatesForFrequency(string $frequency, Carbon $startDate, Carbon $endDate): array
    {
        $dates = [];
        $current = $startDate->copy();

        switch ($frequency) {
            case 'frequent':
                while ($current->lte($endDate)) {
                    if (rand(1, 100) <= 40) {
                        $dates[] = $current->copy()->addHours(rand(8, 20));
                    }
                    $current->addDays(rand(2, 4));
                }
                break;

            case 'weekly':
                while ($current->lte($endDate)) {
                    $dates[] = $current->copy()->addDays(rand(0, 2))->addHours(rand(8, 20));
                    $current->addWeek();
                }
                break;

            case 'biweekly':
                while ($current->lte($endDate)) {
                    $dates[] = $current->copy()->addDays(rand(0, 3))->addHours(rand(8, 20));
                    $current->addWeeks(2);
                }
                break;

            case 'monthly':
                while ($current->lte($endDate)) {
                    $dayOfMonth = min($current->daysInMonth, rand(1, 28));
                    $dates[] = $current->copy()->day($dayOfMonth)->addHours(rand(8, 20));
                    $current->addMonth();
                }
                break;

            case 'quarterly':
                while ($current->lte($endDate)) {
                    $dates[] = $current->copy()->addDays(rand(0, 14))->addHours(rand(8, 20));
                    $current->addMonths(3);
                }
                break;

            case 'yearly':
                $dates[] = $startDate->copy()->addMonths(rand(0, 11))->addDays(rand(0, 28));
                break;
        }

        return array_filter($dates, fn ($date) => $date->lte($endDate) && $date->gte($startDate));
    }
}
