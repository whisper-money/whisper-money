<?php

use App\Enums\AccountType;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ForecastAccountSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\ForecastBalanceTrends;
use App\Services\Forecast\ForecastPlanBuilder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Pennant\Feature;

/**
 * An account the forecast has only balances for (no transaction in the last
 * 90 days) is projected along the line its last 12 month-end balances drew.
 * Today is 26 Nov 2026, so those are the ends of Nov 2025 to Oct 2026.
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);
    $this->spaceId = $this->user->activeSpace()->id;
});

/**
 * @param  array<string, int>  $balances  By date.
 */
function trendAccount(User $user, string $name, AccountType $type, array $balances): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency_code' => 'EUR']);

    foreach ($balances as $date => $balance) {
        AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => $date, 'balance' => $balance]);
    }

    return $account;
}

/**
 * An index fund that grows about 300 € a month, noted mid-month and once
 * more today: 10,000 € in mid Nov 2025 up to 13,600 € today.
 *
 * @return array<string, int>
 */
function indexFundBalances(): array
{
    $wobble = [0, 4000, -3000, 2000, -5000, 1000, 3000, -2000, 0, 5000, -4000, 1000];
    $balances = [];

    foreach (range(0, 11) as $index) {
        $balances[sprintf('%s-15', now()->startOfMonth()->subMonthsNoOverflow(12 - $index)->format('Y-m'))] = 1_000_000 + 30_000 * $index + $wobble[$index];
    }

    return [...$balances, '2026-11-26' => 1_360_000];
}

/**
 * @return array<string, mixed>
 */
function trendForecast(User $user, Account $account): array
{
    return test()->actingAs($user)->get(route('forecast.index', ['account' => $account->id, 'horizon' => 12]))->assertOk()->viewData('page')['props']['forecast'];
}

it('projects an index fund along the trend of its month-end balances, from today and without a jump', function () {
    $fund = trendAccount($this->user, 'Index fund', AccountType::Investment, indexFundBalances());
    ForecastAccountSetting::factory()->create(['account_id' => $fund->id, 'annual_rate_bps' => 1200]);

    $forecast = trendForecast($this->user, $fund);
    $trend = $forecast['account']['trend'];
    $months = $forecast['projection'];

    // About 30,000 a month (29,986.01 before rounding), and the wobble's deviation around it (3,022.67).
    expect($trend)->toBe(['monthly' => 29_986, 'band' => 3_023, 'points' => 12])
        // The month in progress is partial: it stays at today's balance.
        ->and($months[0]['close'])->toBe(1_360_000)
        ->and($months[0]['growth'])->toBe(0)
        // Each whole month adds the slope, not a twelfth of the 12 % rate.
        ->and(array_column(array_slice($months, 1), 'growth'))->each->toBe($trend['monthly'])
        ->and($months[1]['close'])->toBe(1_360_000 + $trend['monthly'])
        ->and($months[12]['close'])->toBe(1_360_000 + 12 * $trend['monthly'])
        ->and($months[12]['band'])->toBe($trend['band'])
        ->and($months[12]['pessimisticMin'])->toBe($months[12]['min'])
        ->and($forecast['alert']['level'])->toBe('ok');
});

it('carries the trend in the projection input, so the page recomputes the same line', function () {
    $fund = trendAccount($this->user, 'Index fund', AccountType::Investment, indexFundBalances());

    $response = $this->actingAs($this->user)->get(route('forecast.index', ['account' => $fund->id]))->assertOk();
    $input = $response->viewData('page')['props']['projectionInput'];
    $trend = $response->viewData('page')['props']['forecast']['account']['trend'];

    expect($input['accounts'][0])->toMatchArray(['id' => $fund->id, 'trendSlope' => $trend['monthly'], 'trendBand' => $trend['band']]);
});

it('keeps an account with fewer than four month ends flat, and says so', function () {
    $fund = trendAccount($this->user, 'Pension', AccountType::Retirement, ['2026-08-31' => 500_000, '2026-09-30' => 520_000, '2026-10-31' => 540_000]);

    $forecast = trendForecast($this->user, $fund);

    expect($forecast['account']['trend'])->toBe(['monthly' => null, 'band' => null, 'points' => 3])
        ->and(array_column($forecast['projection'], 'close'))->each->toBe(540_000)
        ->and(array_column($forecast['projection'], 'band'))->each->toBe(0);
});

it('gives no trend to an account with a transaction in the last 90 days', function () {
    $checking = trendAccount($this->user, 'Checking', AccountType::Checking, indexFundBalances());
    Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $checking->id, 'transaction_date' => '2026-09-01', 'amount' => -1_000]);

    expect(app(ForecastBalanceTrends::class)->for(new Collection([$checking]), '2026-11-26'))->toBe([])
        ->and(trendForecast($this->user, $checking)['account']['trend'])->toBeNull();
});

it('gives a trend to an account whose last transaction is older than 90 days', function () {
    $savings = trendAccount($this->user, 'Savings', AccountType::Savings, indexFundBalances());
    Transaction::factory()->create(['user_id' => $this->user->id, 'account_id' => $savings->id, 'transaction_date' => '2026-08-27', 'amount' => -1_000]);

    expect(trendForecast($this->user, $savings)['account']['trend']['monthly'])->toBeGreaterThan(29_000);
});

it('gives no trend to an account without a balance at any of the month ends', function () {
    $fund = trendAccount($this->user, 'New fund', AccountType::Investment, ['2026-11-20' => 100_000]);

    expect(trendForecast($this->user, $fund)['account']['trend'])->toBeNull();
});

it('never lets the swings of a trend raise a soft warning on a savings account', function () {
    // Hovers around 1,000 € with swings of ±300 €: its band is wide, but its
    // owner's minimum of 800 € is only ever read against the line itself.
    $swings = [0, 30_000, -30_000, 30_000, -30_000, 30_000, -30_000, 30_000, -30_000, 30_000, -30_000, 0];
    $balances = [];

    foreach ($swings as $index => $swing) {
        $balances[now()->startOfMonth()->subMonthsNoOverflow(12 - $index)->endOfMonth()->toDateString()] = 100_000 + $swing;
    }

    $savings = trendAccount($this->user, 'Savings by hand', AccountType::Savings, [...$balances, '2026-11-26' => 100_000]);
    ForecastAccountSetting::factory()->create(['account_id' => $savings->id, 'minimum_balance' => 80_000]);

    $forecast = trendForecast($this->user, $savings);

    expect($forecast['account']['trend']['band'])->toBeGreaterThan(20_000)
        ->and($forecast['projection'][1]['min'] - $forecast['projection'][1]['band'])->toBeLessThan(80_000)
        ->and($forecast['alert']['level'])->toBe('ok')
        ->and($forecast['alert']['softMonths'])->toBe([]);
});

it('adds the trend into the total of every account together, in the user currency', function () {
    $fund = trendAccount($this->user, 'Index fund', AccountType::Investment, indexFundBalances());

    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->spaceId);
    $all = $this->actingAs($this->user)->get(route('forecast.index', ['account' => 'all', 'horizon' => 12]))->assertOk()->viewData('page')['props']['forecast'];

    expect($plan->trends)->toHaveKey($fund->id)
        ->and($all['projection'][12]['close'])->toBe(1_360_000 + 12 * $plan->trends[$fund->id]->monthlySlope)
        ->and($all['accountForecasts'][0]['trend']['monthly'])->toBe($plan->trends[$fund->id]->monthlySlope);
});

it('leaves everything as it was with the Forecast flag off', function () {
    Feature::for($this->user)->deactivate(Forecast::class);
    trendAccount($this->user, 'Index fund', AccountType::Investment, indexFundBalances());

    $this->actingAs($this->user)->get(route('forecast.index'))->assertNotFound();
});
