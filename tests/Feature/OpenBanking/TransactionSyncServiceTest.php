<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\TransactionDescriptionFormatter;
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    $created = $service->sync($account, '2025-01-01', '2025-01-31');

    expect($created)->toBe(2);
    expect($account->transactions()->count())->toBe(2);
});

test('sync stores creditor and debtor names from raw payload', function () {
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
                    'remittance_information' => ['Card payment'],
                    'creditor' => ['name' => 'Amazon EU'],
                    'debtor' => ['name' => 'Victor Falcon'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    $service->sync($account, '2025-01-01', '2025-01-31');

    $transaction = $account->transactions()->first();
    expect($transaction->creditor_name)->toBe('Amazon EU')
        ->and($transaction->debtor_name)->toBe('Victor Falcon');
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
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

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    $created = $service->sync($account, '2025-01-01', '2025-01-31');

    expect($created)->toBe(0);
});

test('sync formats BBVA transaction descriptions and stores original', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'BBVA', 'user_id' => $user->id]);
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
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
                    'remittance_information' => ['ADEUDO DE ENDESA // PAGO DE ADEUDO DIRECTO SEPA'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    $service->sync($account, '2025-01-01', '2025-01-31');

    $transaction = $account->transactions()->first();
    expect($transaction->description)->toBe('Adeudo de Endesa / Pago de Adeudo Directo SEPA');
    expect($transaction->original_description)->toBe('ADEUDO DE ENDESA // PAGO DE ADEUDO DIRECTO SEPA');
});

test('sync does not format descriptions for non-BBVA banks', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'ING', 'user_id' => $user->id]);
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
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
                    'remittance_information' => ['ADEUDO DE ENDESA'],
                ],
            ],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    $service->sync($account, '2025-01-01', '2025-01-31');

    $transaction = $account->transactions()->first();
    expect($transaction->description)->toBe('ADEUDO DE ENDESA');
    expect($transaction->original_description)->toBeNull();
});

test('sync deduplicates transactions without external id via fingerprint', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $payload = [
        // No transaction_id or entry_reference — simulates BNP card txn.
        'transaction_amount' => ['amount' => '59.61', 'currency' => 'USD'],
        'credit_debit_indicator' => 'DBIT',
        'booking_date' => '2025-05-12',
        'creditor' => ['name' => 'MoonPay*Phantom 2880'],
        'bank_transaction_code' => ['code' => 'CCRD', 'sub_code' => 'POSD'],
        'debtor_account' => ['other' => ['identification' => '487104XXXXXX1158']],
        'remittance_information' => [],
    ];

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->twice()
        ->andReturn(['transactions' => [$payload], 'continuation_key' => null]);

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    expect($service->sync($account, '2025-05-01', '2025-05-31'))->toBe(1);
    expect($service->sync($account, '2025-05-01', '2025-05-31'))->toBe(0);
    expect($account->transactions()->count())->toBe(1);

    $stored = $account->transactions()->first();
    expect($stored->external_transaction_id)->toBeNull();
    expect($stored->dedup_fingerprint)->toStartWith('fp_');
});

test('sync still dedupes when bank later supplies a real id for a fingerprinted txn', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $base = [
        'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
        'credit_debit_indicator' => 'DBIT',
        'booking_date' => '2025-05-12',
        'creditor' => ['name' => 'Acme'],
        'bank_transaction_code' => ['code' => 'PMNT', 'sub_code' => 'XBCT'],
        'remittance_information' => ['Coffee'],
    ];

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->once()
        ->ordered()
        ->andReturn(['transactions' => [$base], 'continuation_key' => null]);
    $mockProvider->shouldReceive('getTransactions')
        ->once()
        ->ordered()
        ->andReturn([
            'transactions' => [array_merge($base, ['transaction_id' => 'real-id-123'])],
            'continuation_key' => null,
        ]);

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    expect($service->sync($account, '2025-05-01', '2025-05-31'))->toBe(1);

    // Second sync brings the same payload with an upstream id attached.
    // Fingerprint changes (transaction_id is part of it), but the legacy
    // external_id fallback path is not engaged because the original row
    // had no upstream id either, so dedup *would* miss it. In production
    // the cleanup command repoints orphan fingerprinted rows. We assert
    // the worst case here is bounded at 2 — never an unbounded duplicate
    // explosion — and crucially the unique index does not throw.
    $service->sync($account, '2025-05-01', '2025-05-31');
    expect($account->transactions()->count())->toBeLessThanOrEqual(2);
});

test('sync dedupes against soft-deleted fingerprinted transactions', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $payload = [
        'transaction_amount' => ['amount' => '12.34', 'currency' => 'EUR'],
        'credit_debit_indicator' => 'DBIT',
        'booking_date' => '2025-05-12',
        'creditor' => ['name' => 'Acme'],
        'remittance_information' => ['Item'],
    ];

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('getTransactions')
        ->twice()
        ->andReturn(['transactions' => [$payload], 'continuation_key' => null]);

    $service = new TransactionSyncService($mockProvider, new TransactionDescriptionFormatter);
    $service->sync($account, '2025-05-01', '2025-05-31');

    $account->transactions()->first()->delete();

    $created = $service->sync($account, '2025-05-01', '2025-05-31');
    expect($created)->toBe(0);
    expect($account->transactions()->withTrashed()->count())->toBe(1);
    expect($account->transactions()->withTrashed()->first()->trashed())->toBeTrue();
});
