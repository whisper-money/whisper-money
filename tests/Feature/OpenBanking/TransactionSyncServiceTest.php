<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\TransactionSyncService;

test('sync creates transactions from provider data', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->with('ext-123', '2025-01-01', '2025-01-31', null, null)
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-001',
                    'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-15',
                    'remittance_information' => ['Grocery Store Purchase'],
                ],
                [
                    'transaction_id' => 'txn-002',
                    'transaction_amount' => ['amount' => '1000.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'CRDT',
                    'booking_date' => '2025-01-20',
                    'remittance_information' => ['Salary Payment'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider);
    $created = $service->sync($account, '2025-01-01', '2025-01-31');

    expect($created)->toBe(2);
    expect($account->transactions()->count())->toBe(2);

    $debit = $account->transactions()->where('external_transaction_id', 'txn-001')->first();
    expect($debit->amount)->toBe(-5000);
    expect($debit->description)->toBe('Grocery Store Purchase');
    expect($debit->source)->toBe(TransactionSource::EnableBanking);
    expect($debit->description_iv)->toBeNull();
    expect($debit->raw_data)->toEqual([
        'booking_date' => '2025-01-15',
        'transaction_id' => 'txn-001',
        'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
        'credit_debit_indicator' => 'DBIT',
        'remittance_information' => ['Grocery Store Purchase'],
    ]);

    $credit = $account->transactions()->where('external_transaction_id', 'txn-002')->first();
    expect($credit->amount)->toBe(100000);
    expect($credit->description)->toBe('Salary Payment');
});

test('sync deduplicates transactions by external_transaction_id', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'external_transaction_id' => 'txn-001',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-001',
                    'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-15',
                    'remittance_information' => ['Duplicate Transaction'],
                ],
                [
                    'transaction_id' => 'txn-003',
                    'transaction_amount' => ['amount' => '25.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-16',
                    'remittance_information' => ['New Transaction'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider);
    $created = $service->sync($account, '2025-01-01', '2025-01-31');

    expect($created)->toBe(1);
    expect($account->transactions()->count())->toBe(2);
});

test('sync handles pagination with continuation key', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);

    $mockProvider->shouldReceive('getTransactions')
        ->with('ext-123', '2025-01-01', '2025-01-31', null, null)
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-001',
                    'transaction_amount' => ['amount' => '10.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-01',
                    'remittance_information' => ['Page 1'],
                ],
            ],
            'continuation_key' => 'page2',
        ]);

    $mockProvider->shouldReceive('getTransactions')
        ->with('ext-123', '2025-01-01', '2025-01-31', 'page2', null)
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-002',
                    'transaction_amount' => ['amount' => '20.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'CRDT',
                    'booking_date' => '2025-01-02',
                    'remittance_information' => ['Page 2'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider);
    $created = $service->sync($account, '2025-01-01', '2025-01-31');

    expect($created)->toBe(2);
    expect($account->transactions()->count())->toBe(2);
});

test('sync uses creditor name as fallback description', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-001',
                    'transaction_amount' => ['amount' => '99.99', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-15',
                    'remittance_information' => [],
                    'creditor' => ['name' => 'Amazon EU'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider);
    $service->sync($account, '2025-01-01', '2025-01-31');

    $transaction = $account->transactions()->first();
    expect($transaction->description)->toBe('Amazon EU');
});

test('sync creates daily balances from balance_after_transaction', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-001',
                    'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-15',
                    'remittance_information' => ['Morning purchase'],
                    'balance_after_transaction' => ['amount' => '950.00', 'currency' => 'EUR'],
                ],
                [
                    'transaction_id' => 'txn-002',
                    'transaction_amount' => ['amount' => '30.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-15',
                    'remittance_information' => ['Evening purchase'],
                    'balance_after_transaction' => ['amount' => '920.00', 'currency' => 'EUR'],
                ],
                [
                    'transaction_id' => 'txn-003',
                    'transaction_amount' => ['amount' => '1000.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'CRDT',
                    'booking_date' => '2025-01-20',
                    'remittance_information' => ['Salary'],
                    'balance_after_transaction' => ['amount' => '1920.00', 'currency' => 'EUR'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider);
    $service->sync($account, '2025-01-01', '2025-01-31');

    expect($account->balances()->count())->toBe(2);

    $jan15 = $account->balances()->where('balance_date', '2025-01-15')->first();
    expect($jan15->balance)->toBe(92000); // Last transaction of the day

    $jan20 = $account->balances()->where('balance_date', '2025-01-20')->first();
    expect($jan20->balance)->toBe(192000);
});

test('sync skips daily balance when balance_after_transaction is missing', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-001',
                    'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-15',
                    'remittance_information' => ['Purchase'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider);
    $service->sync($account, '2025-01-01', '2025-01-31');

    expect($account->balances()->count())->toBe(0);
});

test('sync does not re-create soft-deleted transactions', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'external_transaction_id' => 'txn-001',
    ]);
    $transaction->delete();

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            'transactions' => [
                [
                    'transaction_id' => 'txn-001',
                    'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
                    'credit_debit_indicator' => 'DBIT',
                    'booking_date' => '2025-01-15',
                    'remittance_information' => ['Grocery Store Purchase'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider);
    $created = $service->sync($account, '2025-01-01', '2025-01-31');

    expect($created)->toBe(0);
    expect($account->transactions()->withTrashed()->count())->toBe(1);
    expect($account->transactions()->withTrashed()->first()->trashed())->toBeTrue();
});

test('sync skips accounts without external_account_id', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'external_account_id' => null,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('getTransactions');

    $service = new TransactionSyncService($mockProvider);
    $created = $service->sync($account, '2025-01-01', '2025-01-31');

    expect($created)->toBe(0);
});
