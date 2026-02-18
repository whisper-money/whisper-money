<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Jobs\SyncBinanceHistoricalBalancesJob;
use App\Mail\BankTransactionsSyncedEmail;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

test('first sync calculates historical balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->once();

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});

test('subsequent syncs do not calculate historical balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldNotReceive('calculateHistoricalBalances');

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});

test('linked accounts sync from last transaction date and skip historical balances', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->linked()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2025-12-15',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')
        ->once()
        ->withArgs(function ($acct, $dateFrom, $dateTo, $strategy) {
            return $dateFrom === '2025-12-15';
        })
        ->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldNotReceive('calculateHistoricalBalances');

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});

test('mixed linked and new accounts in same connection', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);

    $newAccount = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-new',
    ]);

    $linkedAccount = Account::factory()->linked()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-linked',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->twice()->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->twice();
    $balanceSync->shouldReceive('calculateHistoricalBalances')
        ->once()
        ->with(Mockery::on(fn ($acct) => $acct->id === $newAccount->id));

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);
});

test('sends email when new transactions are synced on subsequent sync', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
        'bank_id' => $bank->id,
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(5);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) use ($user) {
        return $mail->totalTransactions === 5
            && $mail->transactionsPerBank === ['Test Bank' => 5]
            && $mail->hasTo($user->email);
    });
});

test('does not send email on first sync', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
        'bank_id' => $bank->id,
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(10);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->once();

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    Mail::assertNotQueued(BankTransactionsSyncedEmail::class);
});

test('does not send email when zero new transactions', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->once()->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    Mail::assertNotQueued(BankTransactionsSyncedEmail::class);
});

test('aggregates multiple accounts under same bank', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Shared Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);

    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-1',
        'bank_id' => $bank->id,
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-2',
        'bank_id' => $bank->id,
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->twice()->andReturn(3);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->twice();

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) {
        return $mail->totalTransactions === 6
            && $mail->transactionsPerBank === ['Shared Bank' => 6];
    });
});

test('lists different banks separately in email', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $bankA = Bank::factory()->create(['name' => 'Bank A']);
    $bankB = Bank::factory()->create(['name' => 'Bank B']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);

    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-a',
        'bank_id' => $bankA->id,
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-b',
        'bank_id' => $bankB->id,
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->twice()->andReturn(4);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->twice();

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) {
        return $mail->totalTransactions === 8
            && $mail->transactionsPerBank === ['Bank A' => 4, 'Bank B' => 4];
    });
});

test('indexa capital sync only syncs balances, not transactions', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'portfolios' => [
                ['date' => '2026-02-18', 'total_amount' => 10000.00, 'cash_amount' => 0, 'instruments_amount' => 10000.00],
            ],
        ]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldNotReceive('sync');

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldNotReceive('sync');

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();
    expect($account->balances()->count())->toBe(1);
});

test('indexa capital connections do not expire', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->subDay(),
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'portfolios' => [
                ['date' => '2026-02-18', 'total_amount' => 10000.00, 'cash_amount' => 0, 'instruments_amount' => 10000.00],
            ],
        ]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->last_synced_at)->not->toBeNull();
});

test('indexa capital sync does not send email', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'IC-001',
    ]);

    Http::fake([
        'api.indexacapital.com/accounts/IC-001/performance' => Http::response([
            'portfolios' => [
                ['date' => '2026-02-18', 'total_amount' => 10000.00, 'cash_amount' => 0, 'instruments_amount' => 10000.00],
            ],
        ]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    Mail::assertNothingQueued();
});

test('binance first sync gets current balance immediately and dispatches historical job', function () {
    Queue::fake(SyncBinanceHistoricalBalancesJob::class);

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCEUR', 'price' => '50000.00'],
        ]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance)->toBe(5000000);

    Queue::assertPushed(SyncBinanceHistoricalBalancesJob::class, function ($job) use ($account) {
        return $job->account->id === $account->id
            && $job->delay !== null;
    });
});

test('binance subsequent sync does not dispatch historical job', function () {
    Queue::fake(SyncBinanceHistoricalBalancesJob::class);

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->binance()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'binance-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.binance.com/sapi/v1/accountSnapshot*' => Http::response(['snapshotVos' => []]),
        'api.binance.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'BTC', 'free' => '1.0', 'locked' => '0.0'],
            ],
        ]),
        'api.binance.com/api/v3/ticker/price' => Http::response([
            ['symbol' => 'BTCEUR', 'price' => '50000.00'],
        ]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->handle($transactionSync, $balanceSync);

    Queue::assertNotPushed(SyncBinanceHistoricalBalancesJob::class);
});
