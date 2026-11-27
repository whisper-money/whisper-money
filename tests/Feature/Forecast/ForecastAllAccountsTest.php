<?php

use App\Enums\AccountType;
use App\Enums\ForecastFrequency;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ExchangeRate;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * Checking with 1,000 on 26 Nov 2026, paid 3,000 on the 1st, rent of 1,900 on
 * the 5th and a yearly insurance of 7,000 in April: April 2027 takes it to
 * −500. Savings holds 9,000, enough to cover it.
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);

    $this->checking = allForecastAccount($this->user, 'Checking', AccountType::Checking, 100000);
    $this->savings = allForecastAccount($this->user, 'Savings', AccountType::Savings, 900000);

    allForecastLine($this->user, $this->checking, 'Salary', 300000, '2026-01-01');
    allForecastLine($this->user, $this->checking, 'Rent', -190000, '2026-01-05');
    allForecastLine($this->user, $this->checking, 'Car insurance', -700000, '2026-04-10', ForecastFrequency::Yearly);
});

function allForecastAccount(User $user, string $name, AccountType $type, int $balance, string $currency = 'EUR'): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency_code' => $currency]);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-20', 'balance' => $balance]);

    return $account;
}

function allForecastLine(User $user, Account $account, string $name, int $amount, string $anchor, ForecastFrequency $frequency = ForecastFrequency::Monthly, array $attributes = []): ForecastItem
{
    return ForecastItem::factory()->amount($amount)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'name' => $name,
        'frequency' => $frequency,
        'anchor_date' => $anchor,
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function allForecastProps(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props'];
}

/**
 * @return array<string, mixed>
 */
function allForecast(User $user, array $query = []): array
{
    return allForecastProps(test()->actingAs($user)->get(route('forecast.index', ['account' => 'all', ...$query])))['forecast'];
}

/**
 * @param  array<string, mixed>  $forecast
 * @return array<string, mixed>
 */
function allForecastAccountProjection(array $forecast, Account $account): array
{
    return collect($forecast['accountForecasts'])->firstWhere('id', $account->id);
}

describe('with the Forecast flag off', function () {
    it('does not have the view', function () {
        Feature::for($this->user)->deactivate(Forecast::class);

        $this->actingAs($this->user)->get(route('forecast.index', ['account' => 'all']))->assertNotFound();
    });
});

it('shows every account together in the user’s currency', function () {
    $forecast = allForecast($this->user);

    expect($forecast['scope'])->toBe('all')
        ->and($forecast['account'])->toBeNull()
        ->and($forecast['currencyCode'])->toBe('EUR')
        ->and(array_column($forecast['accounts'], 'name'))->toBe(['Checking', 'Savings'])
        ->and($forecast['months'])->toHaveCount(13)
        ->and($forecast['projection'])->toHaveCount(13)
        ->and($forecast['kpis']['balanceToday'])->toBe(1000000)
        ->and($forecast['kpis']['accountCount'])->toBe(2)
        ->and(array_column($forecast['accountForecasts'], 'name'))->toBe(['Checking', 'Savings']);

    $checking = allForecastAccountProjection($forecast, $this->checking);
    $savings = allForecastAccountProjection($forecast, $this->savings);

    foreach ($forecast['projection'] as $index => $month) {
        expect($month['close'])->toBe($checking['projection'][$index]['close'] + $savings['projection'][$index]['close']);
    }

    expect($forecast['kpis']['end'])->toMatchArray(['month' => '2027-11', 'close' => end($forecast['projection'])['close']])
        ->and($forecast['kpis']['saved'])->toBe(end($forecast['projection'])['close'] - 1000000);
});

it('keeps the single-account view as it was', function () {
    $forecast = allForecastProps($this->actingAs($this->user)->get(route('forecast.index')))['forecast'];

    expect($forecast['scope'])->toBe('account')
        ->and($forecast['account']['id'])->toBe($this->checking->id)
        ->and($forecast)->not->toHaveKey('accountForecasts');
});

it('shows the empty state when there is no account', function () {
    $user = User::factory()->onboarded()->paywallSeen()->subscribed()->create();
    Feature::for($user)->activate(Forecast::class);

    expect(allForecast($user))->toMatchArray(['scope' => 'all', 'account' => null, 'accounts' => []]);
});

it('leaves the transfers between the accounts out of the total', function () {
    $before = allForecast($this->user);
    allForecastLine($this->user, $this->checking, 'To savings', -50000, '2026-01-02', attributes: ['destination_account_id' => $this->savings->id]);

    $after = allForecast($this->user);

    expect(array_column($after['projection'], 'close'))->toBe(array_column($before['projection'], 'close'))
        ->and(array_unique(array_values($after['plan']['transferEffect'])))->toBe([0])
        // December to November 2027: twelve transfers of 500.
        ->and($after['kpis']['moved'])->toBe(12 * 50000)
        ->and($after['plan']['transfers'])->toHaveCount(1)
        ->and($after['plan']['transfers'][0])->toMatchArray(['accountId' => $this->checking->id, 'direction' => 'in', 'counterpartyName' => 'Checking'])
        ->and($after['plan']['transfers'][0]['amounts']['2026-12'])->toBe(50000)
        ->and(allForecastAccountProjection($after, $this->savings)['projection'][1]['close'])->toBe(950000);
});

it('adds the estimated growth of an account with a yearly rate', function () {
    ForecastAccountSetting::factory()->create(['account_id' => $this->savings->id, 'annual_rate_bps' => 1200]);

    $forecast = allForecast($this->user);

    // 1% a month on 9,000, compounded, from December on.
    expect($forecast['plan']['growth']['2026-11'])->toBe(0)
        ->and($forecast['plan']['growth']['2026-12'])->toBe(9000)
        ->and($forecast['plan']['growth']['2027-01'])->toBe(9090)
        ->and($forecast['kpis']['growth'])->toBe(array_sum($forecast['plan']['growth']))
        ->and(allForecastAccountProjection($forecast, $this->savings)['annualRateBps'])->toBe(1200);
});

it('converts an account in another currency for the total, and keeps its own figures in it', function () {
    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-11-26', 'rates' => ['eur' => 1.0, 'usd' => 2.0]]);
    $dollars = allForecastAccount($this->user, 'Dollars', AccountType::Savings, 200000, 'USD');
    allForecastLine($this->user, $dollars, 'Side job', 20000, '2026-01-15');

    $forecast = allForecast($this->user);
    $own = allForecastAccountProjection($forecast, $dollars);

    expect($forecast['kpis']['balanceToday'])->toBe(1000000 + 100000)
        ->and($own['currencyCode'])->toBe('USD')
        ->and($own['projection'][1]['close'])->toBe(220000);

    $income = collect($forecast['plan']['income'])->first(fn (array $group): bool => collect($group['items'])->contains('name', 'Side job'));
    $sideJob = collect($income['items'])->firstWhere('name', 'Side job');

    // The line in dollars, the category's total in euros.
    expect($sideJob['amounts']['2026-12'])->toBe(20000)
        ->and($income['totals']['2026-12'])->toBe(300000 + 10000);
});

it('leaves out of the total an account whose currency has no rate today, and says so', function () {
    Http::fake(['*' => Http::response([], 404)]);
    $pesos = allForecastAccount($this->user, 'Pesos', AccountType::Savings, 50000000, 'COP');

    $forecast = allForecast($this->user);

    expect($forecast['unconvertedCurrencies'])->toBe(['COP'])
        ->and($forecast['kpis']['balanceToday'])->toBe(1000000)
        ->and($forecast['kpis']['accountCount'])->toBe(2)
        ->and(allForecastAccountProjection($forecast, $pesos)['projection'][0]['close'])->toBe(50000000);
});

it('sums each parent category across the accounts, every line saying its account', function () {
    $home = forecastExpenseCategory($this->user, name: 'Home');
    ForecastItem::query()->where('name', 'Rent')->update(['category_id' => $home->id]);
    allForecastLine($this->user, $this->savings, 'Cleaning', -10000, '2026-01-12', attributes: ['category_id' => $home->id]);

    $forecast = allForecast($this->user);
    $group = collect($forecast['plan']['expenses'])->firstWhere('key', $home->id);

    expect(collect($forecast['plan']['expenses'])->where('key', $home->id))->toHaveCount(1)
        ->and(collect($group['items'])->pluck('accountId', 'name')->all())->toBe(['Rent' => $this->checking->id, 'Cleaning' => $this->savings->id])
        ->and($group['totals']['2026-12'])->toBe(-200000)
        ->and($group['estimates'])->toBe([])
        ->and($group)->not->toHaveKey('estimate');
});

it('lists the estimated rest of each account under the category it belongs to', function () {
    $food = forecastExpenseCategory($this->user, name: 'Food');
    forecastMonthlyCharges($this->checking, '2026-08-14', 3, -6000, 'GROCER', $food);
    forecastMonthlyCharges($this->savings, '2026-08-16', 3, -5000, 'BAKERY', $food);

    $group = collect(allForecast($this->user)['plan']['expenses'])->firstWhere('key', $food->id);
    $estimates = collect($group['estimates'])->keyBy('accountId');

    expect($estimates)->toHaveCount(2)
        ->and($estimates[$this->checking->id]['amounts']['2026-12'])->toBe(-6000)
        ->and($estimates[$this->savings->id]['amounts']['2026-12'])->toBe(-5000)
        ->and($group['totals']['2026-12'])->toBe(-11000)
        ->and($group['current']['real'])->toBe(0);
});

describe('the warning', function () {
    it('says the money is in the wrong account when another one can cover it', function () {
        $alert = allForecast($this->user)['alert'];

        expect($alert)->toMatchArray(['level' => 'split', 'accountId' => $this->checking->id, 'month' => '2027-04', 'min' => -50000])
            ->and($alert['transfer'])->toMatchArray(['fromAccountId' => $this->savings->id, 'amount' => 50000, 'month' => '2027-04']);
    });

    it('says the total does not hold when no account can cover it', function () {
        AccountBalance::query()->where('account_id', $this->savings->id)->update(['balance' => 10000]);

        $alert = allForecast($this->user)['alert'];

        expect($alert)->toMatchArray(['level' => 'short', 'accountId' => $this->checking->id, 'month' => '2027-04', 'transfer' => null]);
    });

    it('still says the money is in the wrong place when the others cover it together, with no transfer to offer', function () {
        AccountBalance::query()->where('account_id', $this->savings->id)->update(['balance' => 30000]);
        allForecastAccount($this->user, 'Second savings', AccountType::Savings, 30000);

        $alert = allForecast($this->user)['alert'];

        expect($alert)->toMatchArray(['level' => 'split', 'accountId' => $this->checking->id, 'transfer' => null]);
    });

    it('says all is well when every account holds', function () {
        ForecastItem::query()->where('name', 'Car insurance')->delete();

        expect(allForecast($this->user)['alert'])->toMatchArray(['level' => 'ok', 'accountId' => null, 'softMonths' => []]);
    });
});

describe('without Pro', function () {
    beforeEach(function () {
        $this->user->subscriptions()->delete();
        $this->user->refresh();
    });

    it('sends only the next month, the later ones by name', function () {
        $props = allForecastProps($this->actingAs($this->user)->get(route('forecast.index', ['account' => 'all'])));
        $forecast = $props['forecast'];
        $visible = ['2026-11', '2026-12'];

        expect($forecast['isPro'])->toBeFalse()
            ->and(array_column($forecast['months'], 'status'))->toBe(['current', 'future', ...array_fill(0, 11, 'locked')])
            ->and(array_column($forecast['projection'], 'month'))->toBe($visible)
            ->and(array_keys($forecast['plan']['growth']))->toBe($visible)
            ->and(array_keys($forecast['plan']['transferEffect']))->toBe($visible)
            ->and($forecast['kpis']['end']['month'])->toBe('2026-12')
            ->and($props['projectionInput']['horizonMonths'])->toBe(2);

        foreach ($forecast['accountForecasts'] as $account) {
            expect(array_column($account['projection'], 'month'))->toBe($visible);
        }

        $groups = [...$forecast['plan']['income'], ...$forecast['plan']['expenses']];
        foreach ($groups as $group) {
            expect(array_diff(array_keys($group['totals']), $visible))->toBeEmpty();
        }
    });

    it('counts the locked months with a problem without saying which', function () {
        $props = allForecastProps($this->actingAs($this->user)->get(route('forecast.index', ['account' => 'all'])));

        expect($props['forecast']['lockedAlertCount'])->toBe(1)
            ->and($props['forecast']['alert']['level'])->toBe('ok')
            ->and(json_encode($props))->not->toContain('2027-04-');
    });
});
