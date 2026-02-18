<?php

use App\Services\CurrencyConversionService;
use Illuminate\Support\Facades\Http;

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

test('throws when both primary and fallback fail', function () {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response('Server Error', 500),
        'currency-api.pages.dev/*' => Http::response('Server Error', 500),
    ]);

    $service = new CurrencyConversionService;
    $service->convert('BTC', 'EUR', 1.0, '2026-01-15');
})->throws(RuntimeException::class);

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
