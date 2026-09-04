<?php

use App\Models\ExchangeRate;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

test('it converts an amount at the given date', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-11',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);

    actingAs(User::factory()->onboarded()->create())
        ->getJson('/api/exchange-rate?from=USD&to=EUR&date=2025-11-11&amount=12000')
        ->assertOk()
        ->assertExactJson(['amount' => 6000]);
});

test('it answers with no amount when the day has no rate for that currency', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-11',
        'rates' => ['eur' => 1.0],
    ]);

    actingAs(User::factory()->onboarded()->create())
        ->getJson('/api/exchange-rate?from=USD&to=EUR&date=2025-11-11&amount=12000')
        ->assertOk()
        ->assertExactJson(['amount' => null]);
});

test('it hands back the same amount when both currencies match', function () {
    actingAs(User::factory()->onboarded()->create())
        ->getJson('/api/exchange-rate?from=EUR&to=EUR&date=2025-11-11&amount=12000')
        ->assertOk()
        ->assertExactJson(['amount' => 12000]);

    // Nothing to look up, so nothing is fetched: the stray-request guard would
    // have failed the test had it tried.
    expect(ExchangeRate::query()->count())->toBe(0);
});

test('it rejects a date that is not a plain Y-m-d', function () {
    actingAs(User::factory()->onboarded()->create())
        ->getJson('/api/exchange-rate?from=USD&to=EUR&date=11/11/2025&amount=12000')
        ->assertUnprocessable();
});

test('it turns strangers away', function () {
    getJson('/api/exchange-rate?from=USD&to=EUR&date=2025-11-11&amount=12000')
        ->assertUnauthorized();
});
