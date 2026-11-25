<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NetWorthAnalyticsService;
use Carbon\Carbon;

/*
 * The dashboard's net worth chart draws only what is known: months before an
 * account's first balance or movement read null, and the onboarding estimates
 * never move the balance history.
 */

beforeEach(function () {
    fakeCurrencyApi();
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00'));

    $this->user = User::factory()->onboarded()->create(['timezone' => 'UTC']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => $this->user->currency_code]);

    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-11-24',
        'balance' => 250000,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array<string, int|null>
 */
function dashboardNetWorthByMonth(User $user, Account $account): array
{
    $evolution = test()->actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'netWorthEvolution',
    ])->assertOk()->json('props.netWorthEvolution');

    return collect($evolution['data'])->mapWithKeys(fn (array $point): array => [$point['month'] => $point[$account->id]])->all();
}

function givenMovement(User $user, Account $account, string $day, int $amount): Transaction
{
    return Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'transaction_date' => $day,
        'amount' => $amount,
    ]);
}

it('leaves the months before the first movement empty', function () {
    givenMovement($this->user, $this->account, '2026-06-10', 100000);
    givenMovement($this->user, $this->account, '2026-09-05', -30000);

    $byMonth = dashboardNetWorthByMonth($this->user, $this->account);

    expect(array_slice($byMonth, 0, 7))->each->toBeNull()
        ->and(array_keys($byMonth)[7])->toBe('2026-06')
        ->and(array_slice($byMonth, 7))->toBe([
            '2026-06' => 280000,
            '2026-07' => 280000,
            '2026-08' => 280000,
            '2026-09' => 250000,
            '2026-10' => 250000,
            '2026-11' => 250000,
        ]);
});

it('does not walk the balance back through onboarding estimates', function () {
    givenMovement($this->user, $this->account, '2026-06-10', 100000);
    givenMovement($this->user, $this->account, '2026-10-01', -169500)
        ->labels()->attach(Label::factory()->for($this->user)->estimate()->create());

    $byMonth = dashboardNetWorthByMonth($this->user, $this->account);

    expect(array_slice($byMonth, 7))->each->toBe(250000);
});

it('starts an account with only a balance and estimates at its first balance', function () {
    givenMovement($this->user, $this->account, '2026-10-01', -169500)
        ->labels()->attach(Label::factory()->for($this->user)->estimate()->create());

    $byMonth = dashboardNetWorthByMonth($this->user, $this->account);

    expect(array_slice($byMonth, 0, -1))->each->toBeNull()
        ->and($byMonth['2026-11'])->toBe(250000);
});

it('keeps every month known for an account whose history reaches past the chart', function () {
    givenMovement($this->user, $this->account, '2025-01-10', 50000);
    givenMovement($this->user, $this->account, '2026-06-10', 100000);

    $byMonth = dashboardNetWorthByMonth($this->user, $this->account);

    expect($byMonth)->each->toBeInt()
        ->and($byMonth['2025-11'])->toBe(150000);
});

it('leaves an account with no balance empty instead of reading it as zero', function () {
    givenMovement($this->user, $this->account, '2025-01-10', 50000);
    $mortgage = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => $this->user->currency_code]);

    $byMonth = dashboardNetWorthByMonth($this->user, $mortgage);

    expect($byMonth)->each->toBeNull();
});

it('leaves an account empty once it is archived', function () {
    givenMovement($this->user, $this->account, '2025-01-10', 50000);
    $this->account->forceFill(['archived_at' => '2026-09-15 10:00:00'])->save();

    $byMonth = dashboardNetWorthByMonth($this->user, $this->account);

    expect($byMonth['2026-08'])->toBe(250000)
        ->and($byMonth['2026-09'])->toBeNull()
        ->and($byMonth['2026-11'])->toBeNull();
});

it('leaves the days before the first movement empty in the dashboard daily chart', function () {
    givenMovement($this->user, $this->account, '2026-11-20', -10000);

    $data = $this->actingAs($this->user)->getJson('/api/dashboard/net-worth-daily-evolution?'.http_build_query([
        'from' => '2026-11-18',
        'to' => '2026-11-24',
    ]))->assertOk()->json('data');

    expect(collect($data)->pluck($this->account->id, 'date')->all())->toBe([
        '2026-11-18' => null,
        '2026-11-19' => null,
        '2026-11-20' => 250000,
        '2026-11-21' => 250000,
        '2026-11-22' => 250000,
        '2026-11-23' => 250000,
        '2026-11-24' => 250000,
    ]);
});

it('keeps every period an integer for the API and MCP readers', function (bool $daily) {
    givenMovement($this->user, $this->account, '2026-06-10', 100000);

    $evolution = app(NetWorthAnalyticsService::class)->evolution(
        $this->user,
        Carbon::parse('2025-11-01'),
        Carbon::parse('2026-11-24'),
        $daily,
    );

    expect(collect($evolution['data'])->pluck($this->account->id)->all())->each->toBeInt();
})->with(['monthly' => false, 'daily' => true]);
