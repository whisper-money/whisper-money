<?php

use App\Services\CurrencyConversionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

test('converts crypto to fiat using CDN rates', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
                'eth' => 0.0004,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;

    // 1 EUR = 0.000015 BTC → 1 BTC = 1/0.000015 = 66666.67 EUR
    // 2 BTC = 133333.33 EUR
    $result = $service->convert('BTC', 'EUR', 2.0, '2026-01-15');

    expect($result)->toBe(2.0 / 0.000015);
});

test('returns quantity when source equals target', function () {
    Http::fake();

    $service = new CurrencyConversionService;
    $result = $service->convert('EUR', 'EUR', 150.0, '2026-01-15');

    expect($result)->toBe(150.0);
    Http::assertNothingSent();
});

test('returns zero when source currency not found', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('UNKNOWN', 'EUR', 10.0, '2026-01-15');

    expect($result)->toBe(0.0);
});

test('uses fallback URL when primary fails', function () {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response('Server Error', 500),
        'currency-api.pages.dev/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.00002,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'EUR', 1.0, '2026-01-15');

    expect($result)->toBe(1.0 / 0.00002);
});

test('degrades to zero when both primary and fallback fail', function () {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response('Server Error', 500),
        'currency-api.pages.dev/*' => Http::response('Server Error', 500),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'EUR', 1.0, '2026-01-15');

    expect($result)->toBe(0.0);
});

test('degrades to zero and stops walking dates when the source times out', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'EUR', 1.0, '2026-01-15');

    expect($result)->toBe(0.0);

    // Only the first candidate date is attempted (primary + fallback); a timing
    // out source must not be retried across every historical lookback date.
    expect($attempts)->toBe(2);
});

test('caches rates across service instances so repeat lookups skip HTTP', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => ['btc' => 0.000015],
        ]),
    ]);

    (new CurrencyConversionService)->convert('BTC', 'EUR', 1.0, '2026-01-15');
    (new CurrencyConversionService)->convert('BTC', 'EUR', 1.0, '2026-01-15');

    Http::assertSentCount(1);
});

test('returns zero when base currency is unknown and all sources return 404', function () {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response('Not Found', 404),
        'currency-api.pages.dev/*' => Http::response('Not Found', 404),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'XXX', 1.0, '2026-01-15');

    expect($result)->toBe(0.0);
});

test('falls back to previous historical date when requested release is missing', function () {
    Http::fake([
        'cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@2025-12-10/*' => Http::response('Not Found', 404),
        'currency-api.pages.dev/v1/2025-12-10/*' => Http::response('Not Found', 404),
        'cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@2025-12-09/*' => Http::response([
            'usd' => [
                'eur' => 0.9,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('EUR', 'USD', 90.0, '2025-12-10');

    expect($result)->toBe(100.0);
    Http::assertSentCount(3);
});

test('caches rates so same currency and date makes only one HTTP request', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
                'eth' => 0.0004,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $service->convert('BTC', 'EUR', 1.0, '2026-01-15');
    $service->convert('ETH', 'EUR', 5.0, '2026-01-15');

    Http::assertSentCount(1);
});

test('different dates make separate requests', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $service->convert('BTC', 'EUR', 1.0, '2026-01-15');
    $service->convert('BTC', 'EUR', 1.0, '2026-01-16');

    Http::assertSentCount(2);
});

test('converts new latam fiat currency using CDN rates', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/usd*' => Http::response([
            'usd' => [
                'ars' => 1400.0,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('ARS', 'USD', 2800.0, '2026-01-15');

    expect($result)->toBe(2.0);
});
