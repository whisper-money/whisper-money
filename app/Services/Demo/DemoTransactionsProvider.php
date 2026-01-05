<?php

namespace App\Services\Demo;

use App\Enums\TransactionSource;

class DemoTransactionsProvider
{
    /**
     * Get demo transactions data.
     *
     * @return array<int, array{description: string, description_iv: string, transaction_date: string, amount: int, currency_code: string, notes: string|null, notes_iv: string|null, source: TransactionSource}>
     */
    public function getTransactions(): array
    {
        return [
            [
                'description' => 'Grocery Store - Weekly Shopping',
                'description_iv' => 'demo_iv_001',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -8500,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Starbucks Coffee',
                'description_iv' => 'demo_iv_002',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -650,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Shell Gas Station',
                'description_iv' => 'demo_iv_003',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -4520,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Monthly Salary Deposit',
                'description_iv' => 'demo_iv_004',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => 450000,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Electric Bill Payment',
                'description_iv' => 'demo_iv_005',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -12500,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Italian Restaurant Dinner',
                'description_iv' => 'demo_iv_006',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -7800,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Netflix Subscription',
                'description_iv' => 'demo_iv_007',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -1599,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'ATM Cash Withdrawal',
                'description_iv' => 'demo_iv_008',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -20000,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Transfer from Savings',
                'description_iv' => 'demo_iv_009',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => 50000,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
            [
                'description' => 'Spotify Premium',
                'description_iv' => 'demo_iv_010',
                'transaction_date' => now()->subDays(rand(1, 30))->format('Y-m-d'),
                'amount' => -999,
                'currency_code' => 'USD',
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::ManuallyCreated,
            ],
        ];
    }
}
