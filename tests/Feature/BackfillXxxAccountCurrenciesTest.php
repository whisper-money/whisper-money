<?php

use App\Models\Account;
use App\Models\User;

test('backfill resolves XXX accounts to their owner currency and XXX owners to the app default', function () {
    config(['cashier.currency' => 'eur']);

    $normalUser = User::factory()->create(['currency_code' => 'MXN']);
    $xxxUser = User::factory()->create(['currency_code' => 'XXX']);

    $fromNormalOwner = Account::factory()->create(['user_id' => $normalUser->id, 'currency_code' => 'XXX']);
    $fromXxxOwner = Account::factory()->create(['user_id' => $xxxUser->id, 'currency_code' => 'XXX']);
    $untouched = Account::factory()->create(['user_id' => $normalUser->id, 'currency_code' => 'MXN']);

    (require database_path('migrations/2026_06_27_000000_backfill_xxx_account_currencies.php'))->up();

    expect($xxxUser->refresh()->currency_code)->toBe('EUR');
    expect($fromNormalOwner->refresh()->currency_code)->toBe('MXN');
    expect($fromXxxOwner->refresh()->currency_code)->toBe('EUR');
    expect($untouched->refresh()->currency_code)->toBe('MXN');
});
