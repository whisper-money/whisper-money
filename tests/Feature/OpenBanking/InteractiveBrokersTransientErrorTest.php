<?php

use App\Exceptions\Banking\TransientBankingProviderException;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\InteractiveBrokersClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** The helpers in InteractiveBrokersBalanceSyncTest are not loaded when this file runs alone. */
function ibReferenceCodeXml(): string
{
    return '<FlexStatementResponse><Status>Success</Status><ReferenceCode>1234567890</ReferenceCode></FlexStatementResponse>';
}

test('a timeout on SendRequest is reclassified as transient', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    try {
        (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
        $this->fail('Expected a TransientBankingProviderException');
    } catch (TransientBankingProviderException $e) {
        expect($e->provider)->toBe('interactivebrokers')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

test('a timeout on GetStatement is reclassified as transient', function () {
    Http::fake([
        '*SendRequest*' => Http::response(ibReferenceCodeXml()),
        '*GetStatement*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    try {
        (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
        $this->fail('Expected a TransientBankingProviderException');
    } catch (TransientBankingProviderException $e) {
        expect($e->provider)->toBe('interactivebrokers')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

test('a flex 5xx is reclassified as transient', function () {
    Http::fake(['*SendRequest*' => Http::response('Service Unavailable', 503)]);

    try {
        (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
        $this->fail('Expected a TransientBankingProviderException');
    } catch (TransientBankingProviderException $e) {
        expect($e->provider)->toBe('interactivebrokers')
            ->and($e->statusCode)->toBe(503);
    }
});

test('a flex 4xx stays raw so the sync job can act on it', function () {
    Http::fake(['*SendRequest*' => Http::response('Forbidden', 403)]);

    expect(fn () => (new InteractiveBrokersClient('token', '123456'))->fetchStatement())
        ->toThrow(RequestException::class);
});

/**
 * The point of the reclassification: consecutive_sync_failures is the budget of
 * scheduled retries, and running it out drops the connection out of every future
 * scheduled sync. An IB timeout must not spend it.
 */
test('an IB timeout does not spend the connection scheduled-retry budget', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->interactiveBrokers()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => 1,
    ]);
    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'U1234567',
    ]);

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15002 milliseconds'));

    expect(fn () => runSync(finalAttemptJobFor($connection)))
        ->toThrow(TransientBankingProviderException::class);

    $connection->refresh();

    expect($connection->consecutive_sync_failures)->toBe(1)
        ->and($connection->error_message)->toBe('The bank provider is temporarily unavailable. We will try syncing again later.');
});
