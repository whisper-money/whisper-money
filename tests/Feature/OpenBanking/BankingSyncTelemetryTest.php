<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingSyncLogStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The shape production keeps producing: one bank, one account, transactions the
 * provider serves happily and balances it rate limits. Which of the two hits the
 * limit is the question the logs have never been able to answer.
 */
function telemetryConnection(int $accounts = 1): BankingConnection
{
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Trade Republic',
        'aspsp_country' => 'DE',
        'last_synced_at' => now()->subDay(),
    ]);

    for ($i = 1; $i <= $accounts; $i++) {
        Account::factory()->connected()->create([
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => "ext-{$i}",
        ]);
    }

    app()->instance(BankingProviderInterface::class, enableBankingProviderForTest());

    return $connection;
}

/**
 * On its final attempt, so the reporting gate in the job is open.
 */
function telemetryJob(BankingConnection $connection): SyncBankingConnectionJob
{
    $job = new SyncBankingConnectionJob($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(3);
    $job->job->shouldReceive('isReleased')->andReturn(false);
    $job->job->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $job->job->shouldReceive('hasFailed')->andReturn(false);

    return $job;
}

/**
 * Every line written during the callback, as [message, context] pairs.
 *
 * @return array<int, array{0: string, 1: array<string, mixed>}>
 */
function telemetryLogs(callable $callback): array
{
    $logged = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = [$event->message, $event->context];
    });

    $callback();

    return $logged;
}

/**
 * @param  array<int, array{0: string, 1: array<string, mixed>}>  $logged
 * @return array<string, mixed>
 */
function telemetryLogContext(array $logged, string $message): array
{
    foreach ($logged as [$loggedMessage, $context]) {
        if ($loggedMessage === $message) {
            return $context;
        }
    }

    test()->fail("No log line with message \"{$message}\".");
}

/**
 * @return array<string, mixed>
 */
function telemetryTransactionsPayload(int $count = 2): array
{
    $transactions = [];

    for ($i = 1; $i <= $count; $i++) {
        $transactions[] = [
            'transaction_id' => "txn-{$i}",
            'transaction_amount' => ['amount' => '10.00', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'booking_date' => now()->toDateString(),
            'remittance_information' => ['Coffee'],
        ];
    }

    return ['transactions' => $transactions, 'continuation_key' => null];
}

/**
 * Transactions the bank serves, balances it rate limits: the shape the whole
 * investigation is about.
 *
 * @param  array<string, string>  $headers
 */
function fakeRateLimitedBalances(string $message = 'Too many requests', array $headers = []): void
{
    Http::fake([
        'api.enablebanking.com/accounts/ext-1/transactions*' => Http::response(telemetryTransactionsPayload()),
        'api.enablebanking.com/accounts/ext-1/balances*' => Http::response([
            'code' => 429,
            'message' => $message,
        ], 429, $headers),
    ]);
}

/**
 * The other way round: the limit lands on the transactions of `$account`, which
 * is the shape that still costs the whole run.
 */
function fakeRateLimitedTransactions(string $account = 'ext-1', mixed $body = ['code' => 429, 'message' => 'Too many requests']): void
{
    Http::fake([
        "api.enablebanking.com/accounts/{$account}/transactions*" => Http::response($body, 429),
        'api.enablebanking.com/accounts/*/transactions*' => Http::response(telemetryTransactionsPayload()),
        'api.enablebanking.com/accounts/*/balances*' => Http::response(['balances' => []]),
    ]);
}

test('a rate limit on balances says so in the logs and still keeps the run', function () {
    $connection = telemetryConnection();

    fakeRateLimitedBalances('Maximum daily access exceeded', ['Retry-After' => '1800']);

    $logged = telemetryLogs(fn () => runSync(telemetryJob($connection)));

    // The transactions were already persisted when the 429 arrived, so the run is
    // no longer thrown away: the balance failure is reported and the run goes on.
    $failure = telemetryLogContext($logged, 'EnableBanking balance sync failed, continuing');

    expect($failure['operation'])->toBe('balances')
        ->and($failure['status_code'])->toBe(429)
        ->and($failure['rate_limited'])->toBeTrue()
        ->and($failure['aspsp_name'])->toBe('Trade Republic')
        ->and($failure['aspsp_country'])->toBe('DE');

    // The backoff the provider asked for is applied all the same.
    $backoff = telemetryLogContext($logged, 'Banking connection rate limited, backing off');

    expect($backoff['operation'])->toBe('balances')
        ->and($backoff['status_code'])->toBe(429)
        ->and($backoff['aspsp_name'])->toBe('Trade Republic');

    $connection->refresh();
    $log = BankingSyncLog::query()
        ->where('banking_connection_id', $connection->id)
        ->latest('created_at')
        ->firstOrFail();

    expect($connection->last_synced_at)->not->toBeNull()
        ->and($connection->rate_limited_until->diffInMinutes(now(), true))->toBeGreaterThanOrEqual(29)
        ->and($log->status)->toBe(BankingSyncLogStatus::Success)
        // MySQL normalises the key order of a JSON column, hence toMatchArray.
        ->and($log->metadata['balance_rate_limit'])->toMatchArray([
            'retry_after' => '1800',
            'message' => 'Maximum daily access exceeded',
        ]);
});

test('a rate limit on transactions says so in the log the backoff writes', function () {
    $connection = telemetryConnection();

    Http::fake([
        'api.enablebanking.com/accounts/ext-1/transactions*' => Http::response([
            'code' => 429,
            'message' => 'Too many requests',
        ], 429),
        'api.enablebanking.com/accounts/ext-1/balances*' => Http::response(['balances' => []]),
    ]);

    $logged = telemetryLogs(fn () => runSync(telemetryJob($connection)));

    $context = telemetryLogContext($logged, 'Banking connection rate limited, backing off');

    expect($context['operation'])->toBe('transactions')
        ->and($context['status_code'])->toBe(429)
        ->and($context['aspsp_name'])->toBe('Trade Republic')
        ->and($context['response_body'])->toContain('Too many requests')
        // Nothing told us how long to wait, so the hour is ours, not the bank's.
        ->and($context['rate_limit_headers'])->toBe([]);
});

test('a run killed by the rate limit reports what it had already imported', function () {
    // Two accounts, and the limit lands on the second one's transactions: the run
    // dies there, after the first account's transactions were already imported.
    $connection = telemetryConnection(accounts: 2);

    fakeRateLimitedTransactions('ext-2');

    $logged = telemetryLogs(fn () => runSync(telemetryJob($connection)));

    $context = telemetryLogContext($logged, 'EnableBanking sync aborted mid-run');

    expect($context['transactions_synced'])->toBe(2)
        ->and($context['accounts_attempted'])->toBe(2)
        ->and($context['accounts_total'])->toBe(2)
        ->and($context['operation'])->toBe('transactions')
        ->and($context['status_code'])->toBe(429)
        ->and($context['aspsp_name'])->toBe('Trade Republic');
});

test('the failed sync log row carries the same context as the warning', function () {
    $connection = telemetryConnection();

    fakeRateLimitedTransactions();

    runSync(telemetryJob($connection));

    $log = BankingSyncLog::query()
        ->where('banking_connection_id', $connection->id)
        ->where('status', BankingSyncLogStatus::Failed)
        ->firstOrFail();

    expect($log->metadata['operation'])->toBe('transactions')
        ->and($log->metadata['status_code'])->toBe(429)
        ->and($log->metadata['aspsp_name'])->toBe('Trade Republic');
});

test('an oversized error body is truncated before it reaches the logs', function () {
    $connection = telemetryConnection();

    fakeRateLimitedTransactions('ext-1', str_repeat('x', 5000));

    $logged = telemetryLogs(fn () => runSync(telemetryJob($connection)));

    $context = telemetryLogContext($logged, 'Banking connection rate limited, backing off');

    // Str::limit keeps 500 characters and appends an ellipsis.
    expect(strlen($context['response_body']))->toBeLessThanOrEqual(510);
});

test('a successful payload never reaches the logs', function () {
    $connection = telemetryConnection();

    Http::fake([
        'api.enablebanking.com/accounts/ext-1/transactions*' => Http::response(telemetryTransactionsPayload()),
        'api.enablebanking.com/accounts/ext-1/balances*' => Http::response(['balances' => [[
            'balance_amount' => ['amount' => '1234.56', 'currency' => 'EUR'],
            'balance_type' => 'CLBD',
            'reference_date' => now()->toDateString(),
            'account_reference' => ['iban' => 'DE89370400440532013000'],
        ]]]),
    ]);

    $logged = telemetryLogs(fn () => runSync(telemetryJob($connection)));

    $connection->refresh();
    expect($connection->last_synced_at)->not->toBeNull();

    $serialized = json_encode($logged, JSON_PARTIAL_OUTPUT_ON_ERROR);

    expect($serialized)->not->toContain('DE89370400440532013000')
        ->and($serialized)->not->toContain('response_body');
});
