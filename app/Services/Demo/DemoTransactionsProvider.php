<?php

namespace App\Services\Demo;

use App\Enums\TransactionSource;

class DemoTransactionsProvider
{
    /**
     * Get demo transactions data.
     *
     * @return array<int, array{description: string, transaction_date: string, amount: int, currency_code: string, notes: string|null, notes_iv: string|null, source: TransactionSource, category_name: string}>
     */
    public function getTransactions(): array
    {
        return [
            [
                'description' => 'Grocery Store - Weekly Shopping',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -8500,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Groceries',
            ],
            [
                'description' => 'Starbucks Coffee',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -650,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Cafes, restaurants, bars',
            ],
            [
                'description' => 'Shell Gas Station',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -4520,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Fuel',
            ],
            [
                'description' => 'Monthly Salary Deposit',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => 450000,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Salary',
            ],
            [
                'description' => 'Electric Bill Payment',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -12500,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Electricity',
            ],
            [
                'description' => 'Italian Restaurant Dinner',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -7800,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Cafes, restaurants, bars',
            ],
            [
                'description' => 'Netflix Subscription',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -1599,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Online services',
            ],
            [
                'description' => 'ATM Cash Withdrawal',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -20000,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Cash withdrawal',
            ],
            [
                'description' => 'Transfer from Savings',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => 50000,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Own account',
            ],
            [
                'description' => 'Spotify Premium',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -999,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
                'category_name' => 'Online services',
            ],
        ];
    }
}
