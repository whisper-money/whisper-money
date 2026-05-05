<?php

use App\Contracts\BankingProviderInterface;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;

test('calculateHistoricalBalances derives balances from transactions', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    // Reference balance: end of Feb 10, balance = 100000 (€1,000.00)
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    // Transactions: Feb 10 had -5000 (debit), Feb 8 had +20000 (credit), Feb 5 had -10000 (debit)
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-10',
        'amount' => -5000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => 20000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-05',
        'amount' => -10000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // End of Feb 10: 100000 (reference)
    // End of Feb 8: 100000 - (-5000) = 105000 (before Feb 10 transactions)
    // End of Feb 5: 105000 - 20000 = 85000 (before Feb 8 transactions)
    expect($account->balances()->count())->toBe(3);

    $feb8 = $account->balances()->where('balance_date', '2026-02-08')->first();
    expect($feb8->balance)->toBe(105000);

    $feb5 = $account->balances()->where('balance_date', '2026-02-05')->first();
    expect($feb5->balance)->toBe(85000);
});

test('calculateHistoricalBalances writes missing balances in one query', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    foreach (range(0, 5) as $daysBack) {
        Transaction::factory()->enableBanking()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'transaction_date' => sprintf('2026-02-%02d', 10 - $daysBack),
            'amount' => -1000,
        ]);
    }

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));

    $result = countQueries(fn () => $service->calculateHistoricalBalances($account));
    $balanceWrites = collect($result['queries'])->filter(fn (string $query): bool => str_contains($query, 'account_balances') && str_contains(strtolower($query), 'insert'));

    expect($balanceWrites)->toHaveCount(1)
        ->and($account->balances()->count())->toBe(6);
});

test('calculateHistoricalBalances handles multiple transactions per day', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    // Two transactions on Feb 8: -3000 and -7000 = total -10000
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => -3000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => -7000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // End of Feb 8: 100000 (no transactions between Feb 8 and Feb 10 on the reference date)
    // Wait - there are no transactions on Feb 10, so running_balance stays 100000
    // End of Feb 8: 100000
    expect($account->balances()->count())->toBe(2);

    $feb8 = $account->balances()->where('balance_date', '2026-02-08')->first();
    expect($feb8->balance)->toBe(100000);
});

test('calculateHistoricalBalances skips dates with existing balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    // Existing balance from balance_after_transaction (more accurate)
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-05',
        'balance' => 77777,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => 20000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-05',
        'amount' => -10000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // Feb 8 should be calculated, Feb 5 should NOT be overwritten
    expect($account->balances()->count())->toBe(3);

    $feb5 = $account->balances()->where('balance_date', '2026-02-05')->first();
    expect($feb5->balance)->toBe(77777); // Preserved original value
});

test('calculateHistoricalBalances does nothing without reference balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => -5000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    expect($account->balances()->count())->toBe(0);
});

test('calculateHistoricalBalances does nothing without transactions', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    expect($account->balances()->count())->toBe(1);
});
