<?php

use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('convert returns same amount when source equals target', function () {
    Http::fake();

    $service = app(ExchangeRateService::class);
    $result = $service->convert('USD', 'USD', 500000, '2026-01-15');

    expect($result)->toBe(500000);
    Http::assertNothingSent();
});

test('convert uses cached exchange rates from database', function () {
    Http::fake();

    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => '2026-01-15',
        'rates' => [
            'eur' => 0.85,
            'gbp' => 0.72,
        ],
    ]);

    $service = app(ExchangeRateService::class);

    // Converting EUR -> USD: 500000 / 0.85 = 588235 (rounded)
    $result = $service->convert('EUR', 'USD', 500000, '2026-01-15');

    expect($result)->toBe((int) round(500000 / 0.85));
    Http::assertNothingSent();
});

test('convert fetches from API on cache miss and stores result', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/usd*' => Http::response([
            'usd' => [
                'eur' => 0.92,
                'gbp' => 0.79,
            ],
        ]),
    ]);

    expect(ExchangeRate::count())->toBe(0);

    $service = app(ExchangeRateService::class);
    $result = $service->convert('EUR', 'USD', 300000, '2026-02-10');

    expect($result)->toBe((int) round(300000 / 0.92));

    // Verify the rates were cached in the database
    expect(ExchangeRate::count())->toBe(1);

    $cached = ExchangeRate::first();
    expect($cached->base_currency)->toBe('usd');
    expect($cached->date->format('Y-m-d'))->toBe('2026-02-10');
    expect($cached->rates)->toHaveKey('eur');
    expect($cached->rates['eur'])->toBe(0.92);
});

test('convert uses database cache on second call for same currency and date', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/usd*' => Http::response([
            'usd' => [
                'eur' => 0.90,
                'gbp' => 0.75,
            ],
        ]),
    ]);

    $service = app(ExchangeRateService::class);

    // First call — hits API
    $service->convert('EUR', 'USD', 100000, '2026-01-20');
    Http::assertSentCount(1);

    // Second call with a fresh service instance — should use DB cache
    $freshService = app(ExchangeRateService::class);
    $freshService->convert('GBP', 'USD', 200000, '2026-01-20');

    // Still only 1 HTTP request total (second used DB cache)
    Http::assertSentCount(1);
});

test('convert memoizes exchange rates during service lifetime', function () {
    Http::fake();

    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => '2026-01-15',
        'rates' => [
            'eur' => 0.85,
            'gbp' => 0.72,
        ],
    ]);

    $exchangeRateSelects = [];
    DB::listen(function ($query) use (&$exchangeRateSelects): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'exchange_rates')) {
            $exchangeRateSelects[] = $query->sql;
        }
    });

    $service = app(ExchangeRateService::class);
    $service->convert('EUR', 'USD', 100000, '2026-01-15');
    $service->convert('GBP', 'USD', 200000, '2026-01-15');
    $service->getRates('USD', '2026-01-15');

    expect($exchangeRateSelects)->toHaveCount(1);
    Http::assertNothingSent();
});

test('preloaded missing rates do not trigger per-date database lookups', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/usd*' => Http::response([
            'usd' => [
                'eur' => 0.90,
                'gbp' => 0.75,
            ],
        ]),
    ]);

    $exchangeRateSelects = [];
    DB::listen(function ($query) use (&$exchangeRateSelects): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'exchange_rates')) {
            $exchangeRateSelects[] = $query->sql;
        }
    });

    $service = app(ExchangeRateService::class);
    $service->preloadRates('USD', ['2026-02-10', '2026-02-11']);
    $service->convert('EUR', 'USD', 100000, '2026-02-10');
    $service->convert('GBP', 'USD', 200000, '2026-02-11');

    expect($exchangeRateSelects)->toHaveCount(1);
    Http::assertSentCount(2);
});

test('convert returns unconverted amount when rate is missing', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => '2026-01-15',
        'rates' => [
            'eur' => 0.85,
        ],
    ]);

    $service = app(ExchangeRateService::class);
    $result = $service->convert('XYZ', 'USD', 100000, '2026-01-15');

    expect($result)->toBe(100000);
});

test('convert is case-insensitive for currency codes', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => '2026-01-15',
        'rates' => [
            'eur' => 0.85,
        ],
    ]);

    $service = app(ExchangeRateService::class);

    $result1 = $service->convert('EUR', 'USD', 100000, '2026-01-15');
    $result2 = $service->convert('eur', 'usd', 100000, '2026-01-15');

    expect($result1)->toBe($result2);
});

test('getRates returns cached rates from database', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2026-01-15',
        'rates' => [
            'usd' => 1.10,
            'gbp' => 0.84,
        ],
    ]);

    Http::fake();

    $service = app(ExchangeRateService::class);
    $rates = $service->getRates('EUR', '2026-01-15');

    expect($rates)->toHaveKey('usd');
    expect($rates['usd'])->toBe(1.10);
    expect($rates)->toHaveKey('gbp');
    Http::assertNothingSent();
});

test('getRates fetches and stores when not cached', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'usd' => 1.08,
                'jpy' => 158.5,
            ],
        ]),
    ]);

    $service = app(ExchangeRateService::class);
    $rates = $service->getRates('eur', '2026-01-10');

    expect($rates)->toHaveKey('usd');
    expect($rates['usd'])->toBe(1.08);

    $stored = ExchangeRate::where('base_currency', 'eur')
        ->where('date', '2026-01-10')
        ->first();

    expect($stored)->not->toBeNull();
    expect($stored->rates['usd'])->toBe(1.08);
});

test('getRates tolerates another request storing rates after preload miss', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'usd' => 1.08,
            ],
        ]),
    ]);

    $service = app(ExchangeRateService::class);
    $service->preloadRates('eur', ['2026-03-27']);

    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2026-03-27',
        'rates' => [
            'usd' => 1.07,
        ],
    ]);

    $rates = $service->getRates('eur', '2026-03-27');

    expect($rates['usd'])->toBe(1.08);
    expect(ExchangeRate::where('base_currency', 'eur')->where('date', '2026-03-27')->count())->toBe(1);
});

test('getRates caps future dates to today', function () {
    $today = now()->toDateString();

    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => $today,
        'rates' => [
            'usd' => 1.12,
        ],
    ]);

    Http::fake();

    $service = app(ExchangeRateService::class);
    $rates = $service->getRates('EUR', '2027-06-15');

    expect($rates)->toHaveKey('usd');
    expect($rates['usd'])->toBe(1.12);
    Http::assertNothingSent();
});
