<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\DripEmailType;
use App\Enums\TransactionSource;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Jobs\SyncBinanceHistoricalBalancesJob;
use App\Mail\BankingConnectionAuthFailedEmail;
use App\Mail\BankTransactionsSyncedEmail;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;

it('sets sentry user and banking connection context for sync jobs', function () {
    SentrySdk::setCurrentHub(new Hub);

    $user = User::factory()->create([
        'name' => 'Private Name',
        'email' => 'sync-user@example.com',
    ]);
    $connection = BankingConnection::factory()->awaitingMapping()->create([
        'user_id' => $user->id,
        'provider' => 'binance',
    ]);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job);

    SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use ($user, $connection): void {
        $sentryUser = $scope->getUser();
        $tags = (fn (): array => $this->tags)->call($scope);
        $contexts = (fn (): array => $this->contexts)->call($scope);

        expect($sentryUser)->not->toBeNull()
            ->and($sentryUser->getId())->toBe((string) $user->id)
            ->and($sentryUser->getEmail())->toBe('sync-user@example.com')
            ->and($sentryUser->getUsername())->toBeNull()
            ->and($tags['banking_connection_id'])->toBe((string) $connection->id)
            ->and($contexts['banking_connection'])->toMatchArray([
                'id' => $connection->id,
                'provider' => 'binance',
                'status' => BankingConnectionStatus::AwaitingMapping->value,
            ]);
    });
});

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
    runSync($job, $transactionSync, $balanceSync);
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
    runSync($job, $transactionSync, $balanceSync);
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
    runSync($job, $transactionSync, $balanceSync);
});

test('linked accounts clamp linkedDateFrom to today when last transaction is in future', function () {
    Carbon::setTestNow('2026-05-02 12:00:00');

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
        'transaction_date' => '2026-05-04',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')
        ->once()
        ->withArgs(function ($acct, $dateFrom, $dateTo, $strategy) {
            return $dateFrom === '2026-05-02' && $dateTo === '2026-05-02';
        })
        ->andReturn(0);

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldNotReceive('calculateHistoricalBalances');

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);
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
    runSync($job, $transactionSync, $balanceSync);
});

test('sends email when new transactions are synced on subsequent sync', function () {
    Queue::fake();

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
    runSync($job, $transactionSync, $balanceSync);

    Queue::assertPushed(SendDailyBankTransactionsSyncedEmailJob::class, function ($job) use ($user) {
        return $job->user->is($user)
            && $job->reportDate === now()->toDateString();
    });
});

test('does not send email on first sync', function () {
    Queue::fake();

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
    runSync($job, $transactionSync, $balanceSync);

    Queue::assertNotPushed(SendDailyBankTransactionsSyncedEmailJob::class);

    $connection->refresh();

    expect($connection->bank_transactions_email_cutoff_at)->not->toBeNull();
});

test('first sync cutoff excludes transactions imported during onboarding from later email reports', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 09:00:00'));

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Silent Bank']);
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
    $transactionSync->shouldReceive('sync')->once()->andReturnUsing(function () use ($user, $account) {
        test()->travelTo(Carbon::parse('2026-04-15 09:05:00'));

        Transaction::factory()->count(3)->enableBanking()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 3;
    });

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->once();
    $balanceSync->shouldReceive('calculateHistoricalBalances')->once();

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();

    expect($connection->bank_transactions_email_cutoff_at)
        ->not->toBeNull()
        ->and($connection->bank_transactions_email_cutoff_at->greaterThanOrEqualTo(
            $account->transactions()->latest('created_at')->first()->created_at,
        ))->toBeTrue();

    test()->travelTo(Carbon::parse('2026-04-16 08:00:00'));

    $emailJob = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $emailJob->handle();

    Mail::assertNothingQueued();
});

test('schedules daily bank sync email check when subsequent sync imports zero new transactions', function () {
    Queue::fake();

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
    runSync($job, $transactionSync, $balanceSync);

    Queue::assertPushed(SendDailyBankTransactionsSyncedEmailJob::class);
});

test('aggregates multiple accounts under same bank', function () {
    Queue::fake();

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
    runSync($job, $transactionSync, $balanceSync);

    Queue::assertPushed(SendDailyBankTransactionsSyncedEmailJob::class);
});

test('lists different banks separately in email', function () {
    Queue::fake();

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
    runSync($job, $transactionSync, $balanceSync);

    Queue::assertPushed(SendDailyBankTransactionsSyncedEmailJob::class);
});

test('daily bank sync email job sends pending transactions once per day', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 09:00:00'));

    $user = User::factory()->onboarded()->create();
    $bankA = Bank::factory()->create(['name' => 'Bank A']);
    $bankB = Bank::factory()->create(['name' => 'Bank B']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $accountA = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bankA->id,
    ]);
    $accountB = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bankB->id,
    ]);

    Transaction::factory()->count(2)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $accountA->id,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $accountB->id,
        'source' => TransactionSource::EnableBanking,
        'description_iv' => null,
        'notes_iv' => null,
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) use ($user) {
        return $mail->totalTransactions === 3
            && $mail->transactionsPerBank === ['Bank A' => 2, 'Bank B' => 1]
            && $mail->hasTo($user->email);
    });

    expect(UserMailLog::query()
        ->where('user_id', $user->id)
        ->where('email_type', DripEmailType::BankTransactionsSynced)
        ->where('email_identifier', '2026-04-15')
        ->exists())->toBeTrue();

    $job->handle();

    Mail::assertQueued(BankTransactionsSyncedEmail::class, 1);
});

test('daily bank sync email job does not send when user disabled the notification', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 09:00:00'));

    $user = User::factory()->onboarded()->create();
    $user->setting()->create(['notify_on_bank_transactions_synced' => false]);
    $bank = Bank::factory()->create(['name' => 'Opted Out Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    Transaction::factory()->count(2)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertNothingQueued();
    expect(UserMailLog::query()->count())->toBe(0);
});

test('daily bank sync email job releases during quiet hours in user timezone', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 04:00:00', 'UTC'));

    $user = User::factory()->onboarded()->create(['timezone' => 'Europe/Madrid']);
    $bank = Bank::factory()->create(['name' => 'Quiet Hours Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    Transaction::factory()->count(2)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $job = (new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString()))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(delay: 7200);

    Mail::assertNothingQueued();
    expect(UserMailLog::query()->count())->toBe(0);
});

test('daily bank sync email job sends at first allowed local hour for user timezone', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 02:15:00', 'UTC'));

    $user = User::factory()->onboarded()->create(['timezone' => 'Asia/Kathmandu']);
    $bank = Bank::factory()->create(['name' => 'Allowed Hours Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    Transaction::factory()->count(3)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) use ($user) {
        return $mail->totalTransactions === 3
            && $mail->transactionsPerBank === ['Allowed Hours Bank' => 3]
            && $mail->hasTo($user->email);
    });

    expect(UserMailLog::query()
        ->where('user_id', $user->id)
        ->where('email_type', DripEmailType::BankTransactionsSynced)
        ->where('email_identifier', '2026-04-15')
        ->exists())->toBeTrue();
});

test('daily bank sync email job ignores quiet hours when user timezone is missing', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 02:00:00', 'UTC'));

    $user = User::factory()->onboarded()->create(['timezone' => null]);
    $bank = Bank::factory()->create(['name' => 'Timezone Fallback Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    Transaction::factory()->count(2)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) use ($user) {
        return $mail->totalTransactions === 2
            && $mail->transactionsPerBank === ['Timezone Fallback Bank' => 2]
            && $mail->hasTo($user->email);
    });
});

test('daily bank sync email job deduplicates per user local day', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 00:30:00', 'UTC'));

    $user = User::factory()->onboarded()->create(['timezone' => 'America/Los_Angeles']);
    $bank = Bank::factory()->create(['name' => 'Local Day Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    Transaction::factory()->count(2)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subMinutes(15),
        'updated_at' => now()->subMinutes(15),
    ]);

    UserMailLog::create([
        'user_id' => $user->id,
        'email_type' => DripEmailType::BankTransactionsSynced,
        'email_identifier' => '2026-04-14',
        'sent_at' => Carbon::parse('2026-04-14 18:00:00', 'UTC'),
    ]);

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertNothingQueued();
});

test('daily bank sync email job sends unreported transactions next day even when current sync imported none', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Test Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDays(2),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    test()->travelTo(Carbon::parse('2026-04-15 18:00:00'));

    Transaction::factory()->count(4)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    test()->travelTo(Carbon::parse('2026-04-16 08:00:00'));

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) {
        return $mail->totalTransactions === 4
            && $mail->transactionsPerBank === ['Test Bank' => 4];
    });
});

test('daily bank sync email job skips transactions imported during silent first sync', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 09:00:00'));

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Silent Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDays(2),
        'bank_transactions_email_cutoff_at' => now()->subHour(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    Transaction::factory()->count(3)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    test()->travelTo(Carbon::parse('2026-04-16 08:00:00'));

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertNothingQueued();
});

test('daily bank sync email job only reports transactions created after silent first sync cutoff', function () {
    Mail::fake();

    test()->travelTo(Carbon::parse('2026-04-15 09:00:00'));

    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'Mixed Bank']);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDays(2),
        'bank_transactions_email_cutoff_at' => now()->subHour(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'bank_id' => $bank->id,
    ]);

    Transaction::factory()->count(2)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHours(2),
    ]);

    Transaction::factory()->count(4)->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'created_at' => now()->subMinutes(15),
        'updated_at' => now()->subMinutes(15),
    ]);

    test()->travelTo(Carbon::parse('2026-04-16 08:00:00'));

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertQueued(BankTransactionsSyncedEmail::class, function ($mail) use ($user) {
        return $mail->totalTransactions === 4
            && $mail->transactionsPerBank === ['Mixed Bank' => 4]
            && $mail->hasTo($user->email);
    });
});

test('daily bank sync email job skips when no pending transactions', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();

    UserMailLog::create([
        'user_id' => $user->id,
        'email_type' => DripEmailType::BankTransactionsSynced,
        'email_identifier' => now()->subDay()->toDateString(),
        'sent_at' => now()->subDay(),
    ]);

    $job = new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString());
    $job->handle();

    Mail::assertNothingQueued();
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
    runSync($job, $transactionSync, $balanceSync);

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
    runSync($job, $transactionSync, $balanceSync);

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
    runSync($job, $transactionSync, $balanceSync);

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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
    runSync($job, $transactionSync, $balanceSync);

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance)->toBe(5000000);

    Queue::assertPushed(SyncBinanceHistoricalBalancesJob::class, function ($job) use ($account) {
        return $job->account->id === $account->id
            && $job->delay !== null;
    });
});

test('binance subsequent sync does not dispatch historical job', function () {
    Mail::fake();
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
        'api.binance.com/sapi/v1/capital/deposit/hisrec*' => Http::response([]),
        'api.binance.com/sapi/v1/capital/withdraw/history*' => Http::response([]),
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
    runSync($job, $transactionSync, $balanceSync);

    Mail::assertNothingQueued();
});

test('fullSync flag forces first-sync behavior on already-synced connection', function () {
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
    $balanceSync->shouldReceive('calculateHistoricalBalances')->once();

    $job = new SyncBankingConnectionJob($connection, fullSync: true);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();

    expect($connection->bank_transactions_email_cutoff_at)->not->toBeNull();
});

test('bitpanda sync calls balance sync service and updates last_synced_at', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
        'last_synced_at' => null,
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response([
            'data' => [
                [
                    'type' => 'wallet',
                    'attributes' => [
                        'cryptocoin_id' => '1',
                        'cryptocoin_symbol' => 'BTC',
                        'balance' => '0.50000000',
                        'is_default' => true,
                        'name' => 'BTC wallet',
                        'deleted' => false,
                    ],
                    'id' => 'wallet-uuid-1',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response([
            'data' => [
                [
                    'type' => 'fiat_wallet',
                    'attributes' => [
                        'fiat_id' => '1',
                        'fiat_symbol' => 'EUR',
                        'balance' => '200.00000000',
                        'name' => 'EUR Wallet',
                    ],
                    'id' => 'fiat-wallet-uuid-1',
                ],
            ],
        ]),
        'api.bitpanda.com/v1/ticker' => Http::response([
            'BTC' => ['EUR' => '50000.00'],
        ]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldNotReceive('sync');

    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldNotReceive('sync');

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();
    expect($account->balances()->count())->toBe(1);
});

test('bitpanda connections do not expire', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->subDay(),
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/ticker' => Http::response([]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->last_synced_at)->not->toBeNull();
});

test('bitpanda sync does not send email', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->bitpanda()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'bitpanda-portfolio',
        'currency_code' => 'EUR',
    ]);

    Http::fake([
        'api.bitpanda.com/v1/wallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets/transactions*' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/fiatwallets' => Http::response(['data' => []]),
        'api.bitpanda.com/v1/ticker' => Http::response([]),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    Mail::assertNothingQueued();
});

test('sends auth failed email immediately for indexa capital 401 error', function () {
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
        'api.indexacapital.com/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $mockQueueJob = Mockery::mock(Job::class);
    $mockQueueJob->shouldReceive('attempts')->andReturn(1);
    $mockQueueJob->shouldReceive('isReleased')->andReturn(false);
    $mockQueueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $mockQueueJob->shouldReceive('hasFailed')->andReturn(false);
    $mockQueueJob->shouldReceive('fail')->once();
    $job->job = $mockQueueJob;

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected for auth failures after manually failing the job.
    }

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->error_message)->toContain('Authentication failed');
    expect($connection->consecutive_sync_failures)->toBe(SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1);

    Mail::assertQueued(BankingConnectionAuthFailedEmail::class);
});

test('auth error sends email and fails job on any attempt', function () {
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
        'api.indexacapital.com/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    // Simulate attempt 1 of 3 - auth errors should STILL send email immediately
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(1);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);
    $job->job->shouldReceive('fail')->once();

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected for auth failures after manually failing the job.
    }

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->consecutive_sync_failures)->toBe(SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1);

    Mail::assertQueued(BankingConnectionAuthFailedEmail::class);
});

test('does not send auth failed email for non-auth errors', function () {
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
        'api.indexacapital.com/*' => Http::response(['error' => 'Server Error'], 500),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (Throwable) {
        // Expected
    }

    Mail::assertNotQueued(BankingConnectionAuthFailedEmail::class);
});

test('does not send auth failed email for enablebanking connections', function () {
    Mail::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => 'enablebanking',
        'last_synced_at' => now()->subDay(),
    ]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    // For EnableBanking, even if the job throws, it should NOT send the auth failed email
    // because isApiKeyProvider() returns false for enablebanking.
    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(new RuntimeException('Auth error'));

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RuntimeException) {
        // Expected
    }

    Mail::assertNotQueued(BankingConnectionAuthFailedEmail::class);
});

test('sends auth failed email immediately for binance 403 error', function () {
    Mail::fake();

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
        'api.binance.com/*' => Http::response(['error' => 'Forbidden'], 403),
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(1);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);
    $job->job->shouldReceive('fail')->once();

    try {
        runSync($job, $transactionSync, $balanceSync);
    } catch (RequestException) {
        // Expected for auth failures after manually failing the job.
    }

    Mail::assertQueued(BankingConnectionAuthFailedEmail::class, function ($mail) use ($user, $connection) {
        return $mail->hasTo($user->email)
            && $mail->bankingConnection->id === $connection->id;
    });
});

test('failed sync job marks active connection as error so onboarding can continue', function () {
    $connection = BankingConnection::factory()->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => null,
        'consecutive_sync_failures' => 0,
    ]);

    $job = new SyncBankingConnectionJob($connection);
    $job->failed(new RuntimeException('Provider request timed out.'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->last_synced_at)->toBeNull();
    expect($connection->error_message)->toBe('An unexpected error occurred during sync. Please try again later.');
    expect($connection->consecutive_sync_failures)->toBe(1);
});

test('rate limit error sets backoff window without erroring connection', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(
            new Illuminate\Http\Client\Response(
                new Response(429)
            )
        )
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->rate_limited_until)->not->toBeNull();
    expect($connection->rate_limited_until->isFuture())->toBeTrue();
});

test('daily rate limit backoff lasts until next UTC midnight', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $body = json_encode(['code' => 429, 'message' => 'Daily PSU not present consultation limit has been exceeded']);
    $response = new Response(429, ['Content-Type' => 'application/json'], $body);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(new Illuminate\Http\Client\Response($response))
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    $expected = now()->utc()->addDay()->startOfDay();
    expect($connection->rate_limited_until)->not->toBeNull();
    expect($connection->rate_limited_until->equalTo($expected))->toBeTrue();
});

test('rate limit backoff honours Retry-After header', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $response = new Response(429, ['Retry-After' => '1800'], '{}');

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andThrow(
        new RequestException(new Illuminate\Http\Client\Response($response))
    );

    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->rate_limited_until)->not->toBeNull();
    expect($connection->rate_limited_until->diffInMinutes(now(), true))->toBeGreaterThanOrEqual(29)
        ->toBeLessThanOrEqual(31);
});

test('rate limited connection is skipped without calling provider', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'rate_limited_until' => now()->addHour(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldNotReceive('sync');
    $balanceSync = Mockery::mock(BalanceSyncService::class);

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
});

test('successful sync clears rate_limited_until', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'rate_limited_until' => now()->subMinute(),
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('sync')->andReturn(0);
    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('sync')->andReturnNull();

    $job = new SyncBankingConnectionJob($connection);
    runSync($job, $transactionSync, $balanceSync);

    $connection->refresh();
    expect($connection->rate_limited_until)->toBeNull();
});

test('does not report MaxAttemptsExceededException raised for SyncBankingConnectionJob', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('resolveName')->andReturn(SyncBankingConnectionJob::class);

    $exception = MaxAttemptsExceededException::forJob($queueJob);

    expect(app(ExceptionHandler::class)->shouldReport($exception))->toBeFalse();
});

test('still reports MaxAttemptsExceededException raised for other jobs', function () {
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('resolveName')->andReturn(SendDailyBankTransactionsSyncedEmailJob::class);

    $exception = MaxAttemptsExceededException::forJob($queueJob);

    expect(app(ExceptionHandler::class)->shouldReport($exception))->toBeTrue();
});
