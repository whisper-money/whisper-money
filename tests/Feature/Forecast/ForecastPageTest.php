<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\ForecastFrequency;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/**
 * A checking account with 1,000 on 26 Nov 2026, paid 3,000 on the 1st of
 * each month, rent of 1,900 on the 5th, and a yearly insurance of 7,000 every
 * April: April 2027 is the only month it runs short (down to −500).
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);

    $this->checking = forecastPageAccount($this->user, 'Checking', AccountType::Checking, 100000);
    $this->savings = forecastPageAccount($this->user, 'Savings', AccountType::Savings, 900000);

    $this->salary = forecastPageLine($this->user, $this->checking, 'Salary', 300000, '2026-01-01');
    $this->rent = forecastPageLine($this->user, $this->checking, 'Rent', -190000, '2026-01-05');
    $this->insurance = forecastPageLine($this->user, $this->checking, 'Car insurance', -700000, '2026-04-10', ForecastFrequency::Yearly);
});

function forecastPageAccount(User $user, string $name, AccountType $type, int $balance): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-20', 'balance' => $balance]);

    return $account;
}

function forecastPageLine(User $user, Account $account, string $name, int $amount, string $anchor, ForecastFrequency $frequency = ForecastFrequency::Monthly, array $attributes = []): ForecastItem
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
function forecastPageProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

describe('with the Forecast flag off', function () {
    beforeEach(function () {
        Feature::for($this->user)->deactivate(Forecast::class);
    });

    it('does not have the page', function (array $query) {
        $this->actingAs($this->user)->get(route('forecast.index', $query))->assertNotFound();
    })->with([
        'no query' => [[]],
        'a valid query' => [['horizon' => 6]],
        'an invalid query' => [['horizon' => 'nope', 'account' => 'x']],
    ]);

    it('shares the flag as off, so the sidebar shows no Forecast item', function () {
        $this->actingAs($this->user)->get(route('cashflow'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('features.forecast', false));
    });
});

it('sends guests to register', function () {
    $this->get(route('forecast.index'))->assertRedirect(route('register'));
});

it('shares the flag as on, so the sidebar shows the Forecast item', function () {
    $this->actingAs($this->user)->get(route('cashflow'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('features.forecast', true));
});

it('shows a Pro user the month in progress and the twelve after it', function () {
    $response = $this->actingAs($this->user)->get(route('forecast.index'))->assertOk();

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('forecast/index')
        ->where('forecast.isPro', true)
        ->where('forecast.horizon', 12)
        ->where('forecast.lockedAlertCount', null)
        ->has('forecast.months', 13)
        ->has('forecast.projection', 13)
        ->where('forecast.months.0', ['month' => '2026-11', 'status' => 'current'])
        ->where('forecast.months.12', ['month' => '2027-11', 'status' => 'future'])
        ->where('projectionInput.horizonMonths', 13)
        ->where('projectionInput.startDate', '2026-11-26'));
});

it('draws the real months before today only where the balance history reaches', function () {
    forecastCharge($this->checking, '2026-10-03', -4000, 'BAKERY');
    forecastCharge($this->checking, '2026-10-28', 300000, 'PAYROLL', Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]));

    $past = forecastPageProps($this->actingAs($this->user)->get(route('forecast.index')))['forecast']['past'];

    expect(array_column($past, 'month'))->toBe(['2026-09', '2026-10', '2026-11'])
        ->and($past[0])->toMatchArray(['income' => 0, 'expenses' => 0, 'close' => null, 'min' => null])
        ->and($past[1])->toMatchArray(['income' => 300000, 'expenses' => 4000])
        ->and($past[2]['close'])->toBe(100000);
});

it('follows the horizon asked for', function (int $horizon) {
    $this->actingAs($this->user)->get(route('forecast.index', ['horizon' => $horizon]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('forecast.horizon', $horizon)
            ->has('forecast.months', $horizon + 1)
            ->has('forecast.projection', $horizon + 1));
})->with([3, 6, 12]);

it('reads a horizon it does not offer as the default, so a stale link still opens', function (string $horizon) {
    $this->actingAs($this->user)->get(route('forecast.index', ['horizon' => $horizon]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('forecast.horizon', 12));
})->with(['5', 'nope', '']);

it('lets a user without Pro in, like Cashflow', function () {
    $free = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
    Feature::for($free)->activate(Forecast::class);

    $this->actingAs($free)->get(route('cashflow'))->assertOk();
    $this->actingAs($free)->get(route('forecast.index'))->assertOk();
});

describe('without Pro', function () {
    beforeEach(function () {
        $this->user->subscriptions()->delete();
        $this->user->refresh();
    });

    it('sends only the next month, and the later ones without a figure', function () {
        $response = $this->actingAs($this->user)->get(route('forecast.index'))->assertOk();
        $props = forecastPageProps($response);
        $forecast = $props['forecast'];
        $visible = ['2026-11', '2026-12'];

        expect($forecast['isPro'])->toBeFalse()
            ->and(array_column($forecast['months'], 'status'))->toBe(['current', 'future', ...array_fill(0, 11, 'locked')])
            ->and(array_column($forecast['projection'], 'month'))->toBe($visible)
            ->and(collect($forecast['months'])->where('status', 'locked')->every(fn (array $month): bool => array_keys($month) === ['month', 'status']))->toBeTrue();

        $lines = [...$forecast['plan']['transfers'], ...collect([...$forecast['plan']['income'], ...$forecast['plan']['expenses']])->flatMap(fn (array $group): array => $group['items'])];
        foreach ($lines as $line) {
            expect(array_diff(array_keys($line['amounts']), $visible))->toBeEmpty();
        }

        expect($props['projectionInput']['horizonMonths'])->toBe(2)
            ->and(collect($props['projectionInput']['occurrences'])->every(fn (array $occurrence): bool => $occurrence['date'] <= '2026-12-31'))->toBeTrue()
            ->and(collect($props['projectionInput']['estimates'])->every(fn (array $estimate): bool => $estimate['month'] <= '2026-12'))->toBeTrue()
            ->and($forecast['kpis']['end']['month'])->toBe('2026-12');
    });

    it('counts the locked months with a problem without saying which', function () {
        $props = forecastPageProps($this->actingAs($this->user)->get(route('forecast.index')));

        // The insurance takes the balance below zero in April 2027 only.
        expect($props['forecast']['lockedAlertCount'])->toBe(1)
            ->and($props['forecast']['alert']['level'])->toBe('ok')
            ->and(json_encode($props))->not->toContain('2027-04-');
    });
});

describe('the account shown', function () {
    it('is by default the account with a problem', function () {
        $this->actingAs($this->user)->get(route('forecast.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('forecast.account.id', $this->checking->id)
                ->where('forecast.alert.level', 'strong')
                ->where('forecast.alert.month', '2027-04')
                ->where('forecast.alert.min', -50000)
                ->where('forecast.alert.minDate', '2027-04-10')
                ->where('forecast.alert.causes', [['name' => 'Car insurance', 'amount' => 700000]])
                ->where('forecast.accounts', [
                    ['id' => $this->checking->id, 'name' => 'Checking', 'hasAlert' => true],
                    ['id' => $this->savings->id, 'name' => 'Savings', 'hasAlert' => false],
                ]));
    });

    it('is by default the main checking account when none has a problem', function () {
        $this->insurance->delete();
        $card = forecastPageAccount($this->user, 'A card', AccountType::CreditCard, 0);

        $this->actingAs($this->user)->get(route('forecast.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('forecast.account.id', $this->checking->id)
                ->where('forecast.accounts.0.id', $card->id));
    });

    it('is the one asked for', function () {
        $this->actingAs($this->user)->get(route('forecast.index', ['account' => $this->savings->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('forecast.account.id', $this->savings->id)
                ->where('forecast.kpis.balanceToday', 900000)
                ->where('forecast.alert.level', 'ok')
                ->where('projectionInput.accounts.0.id', $this->savings->id));
    });

    it('is never another user\'s, which reads like one that does not exist', function () {
        $other = forecastPageAccount(User::factory()->create(), 'Theirs', AccountType::Checking, 500);

        $this->actingAs($this->user)->get(route('forecast.index', ['account' => $other->id]))->assertNotFound();
        $this->actingAs($this->user)->get(route('forecast.index', ['account' => 'not-a-uuid']))->assertNotFound();
    });

    it('is by default the account whose problem is the deepest', function () {
        $card = forecastPageAccount($this->user, 'A card', AccountType::CreditCard, 0);
        forecastPageLine($this->user, $card, 'Card bill', -1500000, '2026-12-03', ForecastFrequency::Once);

        $this->actingAs($this->user)->get(route('forecast.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('forecast.account.id', $card->id)
                ->where('forecast.accounts.0.hasAlert', true)
                ->where('forecast.accounts.1.hasAlert', true));
    });

    it('is never one outside the forecast or the active space', function () {
        $excluded = forecastPageAccount($this->user, 'Left out', AccountType::Checking, 500);
        ForecastAccountSetting::factory()->create(['account_id' => $excluded->id, 'included' => false]);
        $elsewhere = Account::factory()->create(['user_id' => $this->user->id, 'space_id' => Space::factory()->create()->id]);

        $this->actingAs($this->user)->get(route('forecast.index', ['account' => $excluded->id]))->assertNotFound();
        $this->actingAs($this->user)->get(route('forecast.index', ['account' => $elsewhere->id]))->assertNotFound();
        $this->actingAs($this->user)->get(route('forecast.index', ['account' => '9b1f0000-0000-4000-8000-000000000000']))->assertNotFound();
    });

    it('shows an empty page when there is no account to forecast', function () {
        Account::query()->where('user_id', $this->user->id)->get()->each->delete();

        $this->actingAs($this->user)->get(route('forecast.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('forecast.account', null)
                ->where('projectionInput', null));
    });
});

describe('the plan', function () {
    it('groups the lines and the estimated rest under their parent category', function () {
        $home = forecastExpenseCategory($this->user, name: 'Home');
        $power = forecastExpenseCategory($this->user, $home, 'Power');
        $this->rent->update(['category_id' => $home->id]);
        forecastPageLine($this->user, $this->checking, 'Iberdrola', -7500, '2026-01-05', attributes: ['category_id' => $power->id]);
        foreach (['2026-08-14', '2026-09-14', '2026-10-14'] as $date) {
            forecastCharge($this->checking, $date, -6000, 'HARDWARE STORE', $power);
        }
        forecastCharge($this->checking, '2026-11-14', -2000, 'HARDWARE STORE', $power);

        $plan = forecastPageProps($this->actingAs($this->user)->get(route('forecast.index')))['forecast']['plan'];
        $group = collect($plan['expenses'])->firstWhere('key', $home->id);

        expect($group['name'])->toBe('Home')
            ->and(array_column($group['items'], 'name'))->toBe(['Rent', 'Iberdrola'])
            ->and($group['items'][1]['kind'])->toBe('monthly')
            ->and($group['items'][1]['amounts']['2026-12'])->toBe(-7500)
            ->and($group['estimate']['basis'])->toBe('median')
            ->and($group['estimate']['amounts']['2026-12'])->toBe(-6000)
            ->and($group['estimate']['current'])->toBe(['spent' => 2000, 'planned' => 6000, 'left' => 4000])
            ->and(collect($plan['expenses'])->pluck('key')->all())->not->toContain($power->id)
            ->and(array_column(collect($plan['income'])->flatMap(fn (array $income): array => $income['items'])->all(), 'name'))->toBe(['Salary']);
    });

    it('puts the transfers apart, signed from the account shown', function () {
        forecastPageLine($this->user, $this->checking, 'To savings', -30000, '2026-01-02', attributes: ['destination_account_id' => $this->savings->id]);

        $fromChecking = forecastPageProps($this->actingAs($this->user)->get(route('forecast.index', ['account' => $this->checking->id])))['forecast']['plan']['transfers'];
        $fromSavings = forecastPageProps($this->actingAs($this->user)->get(route('forecast.index', ['account' => $this->savings->id])))['forecast']['plan']['transfers'];

        expect(Arr::only($fromChecking[0], ['kind', 'direction', 'counterpartyName']))->toBe(['kind' => 'transfer', 'direction' => 'out', 'counterpartyName' => 'Savings'])
            ->and($fromChecking[0]['amounts']['2026-12'])->toBe(-30000)
            ->and(Arr::only($fromSavings[0], ['direction', 'counterpartyName']))->toBe(['direction' => 'in', 'counterpartyName' => 'Checking'])
            ->and($fromSavings[0]['amounts']['2026-12'])->toBe(30000);
    });

    it('says what paid each line this month and what is still to come', function () {
        $paid = forecastCharge($this->checking, '2026-11-05', -192000, 'RENT');

        ForecastMatch::factory()->create(['forecast_item_id' => $this->rent->id, 'occurrence_date' => '2026-11-05', 'transaction_id' => $paid->id]);

        $plan = forecastPageProps($this->actingAs($this->user)->get(route('forecast.index')))['forecast']['plan'];
        $lines = collect([...$plan['income'], ...$plan['expenses']])->flatMap(fn (array $group): array => $group['items'])->keyBy('name');

        expect($lines['Rent']['current'])->toBe(['planned' => -190000, 'real' => -192000, 'date' => '2026-11-05', 'status' => 'matched'])
            ->and($lines['Salary']['current'])->toBe(['planned' => 300000, 'real' => null, 'date' => '2026-11-01', 'status' => 'pending'])
            ->and($lines['Car insurance']['current'])->toBe(['planned' => null, 'real' => null, 'date' => null, 'status' => 'none']);
    });
});
