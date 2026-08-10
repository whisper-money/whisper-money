<?php

use App\Exceptions\Banking\TransientBankingProviderException;
use App\Services\Banking\WiseClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('a wise 500 while paginating activities is reclassified as transient', function () {
    Http::fake([
        'api.wise.com/v1/profiles/*/activities*' => Http::response(['error' => 'oops'], 500),
    ]);

    expect(fn () => (new WiseClient('test-token'))->getActivities(
        35242290,
        '2025-08-10T00:00:00Z',
        '2026-08-10T23:59:59Z',
        'cursor-token',
    ))->toThrow(TransientBankingProviderException::class);
});

test('a wise timeout is reclassified as transient', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => (new WiseClient('test-token'))->getProfiles())
        ->toThrow(TransientBankingProviderException::class);
});

test('wise auth and rate-limit responses stay raw so the sync job can act on them', function (int $status) {
    Http::fake(['api.wise.com/v1/profiles' => Http::response(['error' => 'nope'], $status)]);

    expect(fn () => (new WiseClient('test-token'))->getProfiles())
        ->toThrow(RequestException::class);
})->with([401, 403, 429]);

test('an empty wise response body still degrades to an empty result', function () {
    Http::fake(['api.wise.com/v2/borderless-accounts*' => Http::response('', 200)]);

    expect((new WiseClient('test-token'))->getBorderlessAccount(35242290))->toBe([]);
});
