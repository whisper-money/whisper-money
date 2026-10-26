<?php

use App\Enums\AccountType;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\NetWorthCalculator;

/**
 * A `user_settings` row is only written once the user touches a preference, so
 * having no row is what a brand new account looks like. Every existing account
 * was backfilled with an explicit row, which is why the second case has to keep
 * counting loans and real estate.
 */
test('a user with no settings row keeps loans and real estate out of net worth', function () {
    $user = User::factory()->create();

    expect($user->setting)->toBeNull();

    expect(app(NetWorthCalculator::class)->excludedTypesFor($user))
        ->toBe([AccountType::Loan, AccountType::RealEstate]);
});

test('a user who opted both in keeps loans and real estate in net worth', function () {
    $user = User::factory()->create();
    UserSetting::factory()->for($user)->create([
        'include_loans_in_net_worth_chart' => true,
        'include_real_estate_in_net_worth_chart' => true,
    ]);

    expect(app(NetWorthCalculator::class)->excludedTypesFor($user->fresh()))->toBe([]);
});
