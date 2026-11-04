<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\LoanDetail;
use App\Models\RealEstateDetail;
use App\Models\User;
use Carbon\Carbon;

/*
 * The server clock is UTC. 2026-11-01 00:30 UTC is still 31 October in Buenos
 * Aires (UTC-3) and 2026-10-31 16:00 UTC is already 1 November in Tokyo
 * (UTC+9): the net worth screens end on, and project from, the owner's month.
 */

beforeEach(function () {
    fakeCurrencyApi();
});

afterEach(function () {
    Carbon::setTestNow();
});

dataset('owner clocks', [
    'Buenos Aires at 00:30 UTC on 1 November' => ['America/Argentina/Buenos_Aires', '2026-11-01 00:30:00', '2026-10'],
    'UTC at 00:30 UTC on 1 November' => ['UTC', '2026-11-01 00:30:00', '2026-11'],
    'Tokyo at 16:00 UTC on 31 October' => ['Asia/Tokyo', '2026-10-31 16:00:00', '2026-11'],
    'no timezone at 00:30 UTC on 1 November' => [null, '2026-11-01 00:30:00', '2026-11'],
]);

function ownerAt(?string $timezone, string $now): User
{
    Carbon::setTestNow(Carbon::parse($now));

    return User::factory()->onboarded()->create(['timezone' => $timezone]);
}

/**
 * The month right after $yearMonth, as Y-m.
 */
function monthAfter(string $yearMonth): string
{
    return Carbon::createFromFormat('Y-m-d', "{$yearMonth}-01")->addMonthNoOverflow()->format('Y-m');
}

/**
 * @return list<array<string, mixed>>
 */
function projectedPointsOf(User $user, Account $account): array
{
    return collect(test()->actingAs($user)->getJson("/api/dashboard/account/{$account->id}/balance-evolution?".http_build_query([
        'from' => '2026-05-01',
        'to' => '2026-10-31',
    ]))->assertOk()->json('data'))->where('projected', true)->values()->all();
}

it('ends the dashboard net worth chart on the owner\'s current month', function (?string $timezone, string $now, string $currentMonth) {
    $user = ownerAt($timezone, $now);
    $account = Account::factory()->create(['user_id' => $user->id]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-06-01',
        'balance' => 5000,
    ]);

    $evolution = $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'netWorthEvolution',
    ])->assertOk()->json('props.netWorthEvolution');

    expect(collect($evolution['data'])->last()['month'])->toBe($currentMonth);
})->with('owner clocks');

it('ends the accounts page history on the owner\'s current month', function (?string $timezone, string $now, string $currentMonth) {
    $user = ownerAt($timezone, $now);
    $account = Account::factory()->create(['user_id' => $user->id]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-06-01',
        'balance' => 5000,
    ]);

    $this->actingAs($user)->get(route('accounts.list'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload
            ->where(
                "accountMetrics.{$account->id}.history.12.date",
                Carbon::createFromFormat('Y-m-d', "{$currentMonth}-01")->format('M'),
            )
        ));
})->with('owner clocks');

it('projects a loan from the month after the owner\'s current one', function (?string $timezone, string $now, string $currentMonth) {
    $user = ownerAt($timezone, $now);
    $account = Account::factory()->loan()->create(['user_id' => $user->id]);
    LoanDetail::factory()->create([
        'account_id' => $account->id,
        'start_date' => '2025-01-15',
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
        'original_amount' => 20_000_000,
    ]);

    expect(projectedPointsOf($user, $account)[0]['month'])->toBe(monthAfter($currentMonth));
})->with('owner clocks');

it('projects real estate from the month after the owner\'s current one', function (?string $timezone, string $now, string $currentMonth) {
    $user = ownerAt($timezone, $now);
    $account = Account::factory()->realEstate()->create(['user_id' => $user->id]);
    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'revaluation_percentage' => 3,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-06-01',
        'balance' => 30_000_000,
    ]);

    expect(projectedPointsOf($user, $account)[0]['month'])->toBe(monthAfter($currentMonth));
})->with('owner clocks');
