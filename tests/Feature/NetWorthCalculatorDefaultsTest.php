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

it('backfills loans and real estate on for users who never touched the toggles', function () {
    $untouched = User::factory()->create();
    $optedOut = User::factory()->create();
    UserSetting::factory()->for($optedOut)->create([
        'include_loans_in_net_worth_chart' => false,
        'include_real_estate_in_net_worth_chart' => false,
    ]);

    $migration = require database_path('migrations/2026_09_16_120000_default_net_worth_chart_loans_and_real_estate_off.php');
    $migration->backfillUntouchedUsers();

    expect(app(NetWorthCalculator::class)->excludedTypesFor($untouched->fresh()))->toBe([])
        ->and(app(NetWorthCalculator::class)->excludedTypesFor($optedOut->fresh()))
        ->toBe([AccountType::Loan, AccountType::RealEstate]);
});
