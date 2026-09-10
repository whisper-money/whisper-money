<?php

use App\Exceptions\Banking\TransientBankingProviderException;
use App\Services\Banking\BinanceClient;
use App\Services\Banking\BitpandaClient;
use App\Services\Banking\CoinbaseClient;
use App\Services\Banking\IndexaCapitalClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

beforeEach(function () {
    // The 429 cases below run through each client's rate-limit backoff.
    Sleep::fake();
});

afterEach(function () {
    Sleep::fake(false);
});

function transientErrorEcPrivateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);

    openssl_pkey_export($key, $pem);

    return $pem;
}

/**
 * The API-key clients that share TranslatesTransportFailures, each paired with
 * the provider slug the translated exception must carry and the name a person
 * reads in its message. Wise and Interactive Brokers translate the same way but
 * keep their own copy for now.
 */
dataset('api key banking clients', [
    'binance' => ['binance', 'Binance', fn () => (new BinanceClient('api-key', 'api-secret'))->getAccount()],
    'coinbase' => ['coinbase', 'Coinbase', fn () => (new CoinbaseClient('organizations/org/apiKeys/key', transientErrorEcPrivateKey()))->getAccounts()],
    'bitpanda' => ['bitpanda', 'Bitpanda', fn () => (new BitpandaClient('api-key'))->getCryptoWallets()],
    'indexacapital' => ['indexacapital', 'Indexa Capital', fn () => (new IndexaCapitalClient('api-token'))->getUser()],
]);

test('a provider timeout is reclassified as transient', function (string $provider, string $label, Closure $call) {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $exception = transientErrorFrom($call);

    expect($exception->provider)->toBe($provider)
        ->and($exception->getMessage())->toStartWith($label)
        ->and($exception->getPrevious())->toBeInstanceOf(ConnectionException::class);
})->with('api key banking clients');

test('a provider 500 is reclassified as transient', function (string $provider, string $label, Closure $call) {
    Http::fake(Http::response(['error' => 'oops'], 500));

    $exception = transientErrorFrom($call);

    expect($exception->provider)->toBe($provider)
        ->and($exception->statusCode)->toBe(500)
        ->and($exception->getMessage())->toStartWith($label)
        ->and($exception->getPrevious())->toBeInstanceOf(RequestException::class);
})->with('api key banking clients');

test('a provider 500 is logged as a warning, so an outage is not an error', function (string $provider, string $label, Closure $call) {
    Http::fake(Http::response(['error' => 'oops'], 500));
    Log::spy();

    transientErrorFrom($call);

    Log::shouldNotHaveReceived('error');
    Log::shouldHaveReceived('log')->with('warning', "{$label} API error", Mockery::any());
})->with('api key banking clients');

test('auth and rate-limit responses stay raw so the sync job can act on them', function (string $provider, string $label, Closure $call, int $status) {
    Http::fake(Http::response(['error' => 'nope'], $status));

    expect($call)->toThrow(RequestException::class);
})->with('api key banking clients')->with([401, 403, 429]);

test('an indexa capital 404 on performance still degrades to an empty result', function () {
    Http::fake([
        'api.indexacapital.com/accounts/*/performance' => Http::response(['error' => 'not found'], 404),
    ]);

    expect((new IndexaCapitalClient('api-token'))->getPerformance('IC-001'))->toBe([]);
});

/**
 * Run a client call that is expected to fail and hand back the transient
 * exception, so its provider and status can be asserted.
 */
function transientErrorFrom(Closure $call): TransientBankingProviderException
{
    try {
        $call();
    } catch (TransientBankingProviderException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a TransientBankingProviderException.');
}
