<?php

use App\Models\Account;
use App\Models\User;

test('re-running the backfill resolves XXX accounts left after the first run and is idempotent', function () {
    config(['cashier.currency' => 'eur']);

    $normalUser = User::factory()->create(['currency_code' => 'MXN']);
    $xxxUser = User::factory()->create(['currency_code' => 'XXX']);

    $fromNormalOwner = Account::factory()->create(['user_id' => $normalUser->id, 'currency_code' => 'XXX']);
    $fromXxxOwner = Account::factory()->create(['user_id' => $xxxUser->id, 'currency_code' => 'XXX']);
    $untouched = Account::factory()->create(['user_id' => $normalUser->id, 'currency_code' => 'USD']);

    $migration = require database_path('migrations/2026_11_22_121158_rerun_backfill_xxx_account_currencies.php');
    $migration->up();
    $migration->up();

    expect($xxxUser->refresh()->currency_code)->toBe('EUR');
    expect($fromNormalOwner->refresh()->currency_code)->toBe('MXN');
    expect($fromXxxOwner->refresh()->currency_code)->toBe('EUR');
    expect($untouched->refresh()->currency_code)->toBe('USD');
});
