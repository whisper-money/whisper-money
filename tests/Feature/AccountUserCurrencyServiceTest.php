<?php

use App\Models\User;
use App\Services\AccountUserCurrencyService;

beforeEach(function () {
    $this->service = app(AccountUserCurrencyService::class);
    config(['cashier.currency' => 'eur']);
});

test('keeps a valid reported currency', function () {
    $user = User::factory()->make(['currency_code' => 'USD']);

    expect($this->service->resolveImportedCurrency('GBP', $user))->toBe('GBP');
});

test('uppercases the reported currency', function () {
    $user = User::factory()->make(['currency_code' => 'USD']);

    expect($this->service->resolveImportedCurrency('gbp', $user))->toBe('GBP');
});

test('falls back to the user currency for XXX, empty or missing codes', function (?string $reported) {
    $user = User::factory()->make(['currency_code' => 'USD']);

    expect($this->service->resolveImportedCurrency($reported, $user))->toBe('USD');
})->with(['XXX', 'xxx', '', null]);

test('falls back to the app default when both the bank and the user lack a currency', function () {
    $user = User::factory()->make(['currency_code' => 'XXX']);

    expect($this->service->resolveImportedCurrency('XXX', $user))->toBe('EUR');
});
