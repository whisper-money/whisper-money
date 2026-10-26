<?php

use App\Models\ExchangeRate;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

test('it converts every amount into the user currency at its own date', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-11',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 4.0, 'gbp' => 0.5],
    ]);

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 12000, 'date' => '2025-11-11'],
            ['from' => 'USD', 'amount' => 12000, 'date' => '2025-11-12'],
            ['from' => 'GBP', 'amount' => -1000, 'date' => '2025-11-12'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [6000, 3000, -2000]]);
});

test('it answers null for an amount whose day holds no rate for its currency', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-11',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 12000, 'date' => '2025-11-11'],
            ['from' => 'GBP', 'amount' => 12000, 'date' => '2025-11-11'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [6000, null]]);
});

test('it hands back amounts already in the user currency untouched', function () {
    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'EUR', 'amount' => 12000, 'date' => '2025-11-11'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [12000]]);

    expect(ExchangeRate::query()->count())->toBe(0);
});

test('it rejects malformed items', function (array $item) {
    actingAs(User::factory()->onboarded()->create())
        ->postJson('/api/converted-amounts', ['items' => [$item]])
        ->assertUnprocessable();
})->with([
    'date not Y-m-d' => [['from' => 'USD', 'amount' => 1, 'date' => '11/11/2025']],
    'amount not an integer' => [['from' => 'USD', 'amount' => 'ten', 'date' => '2025-11-11']],
    'currency not a code' => [['from' => 'DOLLARS', 'amount' => 1, 'date' => '2025-11-11']],
]);

test('it caps how many amounts one request converts', function () {
    $items = array_fill(0, 501, ['from' => 'USD', 'amount' => 1, 'date' => '2025-11-11']);

    actingAs(User::factory()->onboarded()->create())
        ->postJson('/api/converted-amounts', ['items' => $items])
        ->assertUnprocessable();
});

test('it turns strangers away', function () {
    postJson('/api/converted-amounts', ['items' => []])
        ->assertUnauthorized();
});
