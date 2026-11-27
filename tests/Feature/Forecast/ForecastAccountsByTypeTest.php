<?php

use App\Enums\AccountType;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastAlert;
use App\Models\ForecastItem;
use App\Models\User;
use App\Services\Forecast\ForecastAccounts;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * Which accounts the forecast covers when the user said nothing about them:
 * checking, savings and cards; investments, pensions and other accounts as
 * projection only; never a loan or a property.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);
    $this->spaceId = $this->user->activeSpace()->id;
});

function byTypeAccount(User $user, string $name, AccountType $type, int $balance = 100000): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-20', 'balance' => $balance]);

    return $account;
}

function byTypeLine(User $user, Account $account, string $name, int $amount, string $anchor): ForecastItem
{
    return ForecastItem::factory()->amount($amount)->create(['user_id' => $user->id, 'account_id' => $account->id, 'name' => $name, 'anchor_date' => $anchor]);
}

/**
 * @return array<string, mixed>
 */
function byTypeForecast(User $user, array $query = []): array
{
    return test()->actingAs($user)->get(route('forecast.index', $query))->assertOk()->viewData('page')['props']['forecast'];
}

it('decides by type which accounts are in and which are projection only', function (AccountType $type, bool $included, bool $projectionOnly) {
    $account = byTypeAccount($this->user, 'Account', $type);

    $choices = app(ForecastAccounts::class)->choices($this->user, $this->spaceId);

    expect(app(ForecastAccounts::class)->included($this->user, $this->spaceId)->modelKeys())->toBe($included ? [$account->id] : [])
        ->and($choices)->toHaveCount(1)
        ->and($choices[0])->toMatchArray(['id' => $account->id, 'included' => $included, 'projectionOnly' => $projectionOnly]);
})->with([
    'checking' => [AccountType::Checking, true, false],
    'savings' => [AccountType::Savings, true, false],
    'credit card' => [AccountType::CreditCard, true, false],
    'investment' => [AccountType::Investment, true, true],
    'retirement' => [AccountType::Retirement, true, true],
    'others' => [AccountType::Others, true, true],
    'loan' => [AccountType::Loan, false, true],
    'real estate' => [AccountType::RealEstate, false, true],
]);

it('keeps what the user saved over what the type says', function () {
    $loan = byTypeAccount($this->user, 'Mortgage', AccountType::Loan, -15000000);
    $checking = byTypeAccount($this->user, 'Checking', AccountType::Checking);
    ForecastAccountSetting::factory()->create(['account_id' => $loan->id, 'included' => true]);
    ForecastAccountSetting::factory()->create(['account_id' => $checking->id, 'included' => false]);

    expect(app(ForecastAccounts::class)->included($this->user, $this->spaceId)->modelKeys())->toBe([$loan->id]);
});

it('lets the user switch an excluded loan on from the page', function () {
    $checking = byTypeAccount($this->user, 'Checking', AccountType::Checking);
    $house = byTypeAccount($this->user, 'House', AccountType::RealEstate, 30000000);

    $forecast = byTypeForecast($this->user, ['account' => $checking->id]);

    expect(collect($forecast['accountChoices'])->firstWhere('id', $house->id))->toMatchArray(['included' => false, 'projectionOnly' => true])
        ->and(collect($forecast['accounts'])->pluck('id')->all())->toBe([$checking->id]);

    $this->actingAs($this->user)->from(route('forecast.index', ['account' => $checking->id]))
        ->patch(route('forecast.accounts.update', $house), ['included' => true])
        ->assertRedirect(route('forecast.index', ['account' => $checking->id]));

    $tabs = collect(byTypeForecast($this->user, ['account' => $checking->id])['accounts']);

    expect($tabs->firstWhere('id', $house->id))->toMatchArray(['projectionOnly' => true, 'hasAlert' => false]);
});

it('stays on the view the user was on when leaving out another account', function () {
    $checking = byTypeAccount($this->user, 'Checking', AccountType::Checking);
    $savings = byTypeAccount($this->user, 'Savings', AccountType::Savings);
    $all = route('forecast.index', ['account' => 'all']);

    $this->actingAs($this->user)->from($all)->patch(route('forecast.accounts.update', $savings), ['included' => false])->assertRedirect($all);
    $this->actingAs($this->user)->from(route('forecast.index', ['account' => $checking->id]))->patch(route('forecast.accounts.update', $checking), ['included' => false])
        ->assertRedirect(route('forecast.index'));
});

it('shows a card rather than an investment by default when there is no checking account', function () {
    byTypeAccount($this->user, 'A broker', AccountType::Investment);
    $card = byTypeAccount($this->user, 'Card', AccountType::CreditCard, 0);

    expect(byTypeForecast($this->user)['account']['id'])->toBe($card->id);
});

it('offers every excluded account when the forecast covers none', function () {
    $house = byTypeAccount($this->user, 'House', AccountType::RealEstate);

    $forecast = byTypeForecast($this->user);

    expect($forecast['accounts'])->toBe([])
        ->and($forecast['accountChoices'])->toHaveCount(1)
        ->and($forecast['accountChoices'][0])->toMatchArray(['id' => $house->id, 'included' => false]);
});

it('counts a projection-only account in the total', function () {
    byTypeAccount($this->user, 'Checking', AccountType::Checking, 100000);
    byTypeAccount($this->user, 'Broker', AccountType::Investment, 500000);
    byTypeAccount($this->user, 'Mortgage', AccountType::Loan, -15000000);

    expect(byTypeForecast($this->user, ['account' => 'all'])['kpis'])->toMatchArray(['balanceToday' => 600000, 'accountCount' => 2]);
});

describe('a projection-only account that falls below zero', function () {
    beforeEach(function () {
        $this->checking = byTypeAccount($this->user, 'Checking', AccountType::Checking, 100000);
        $this->broker = byTypeAccount($this->user, 'Broker', AccountType::Investment, 10000);
        byTypeLine($this->user, $this->broker, 'Margin interest', -50000, '2026-01-05');
        ForecastAccountSetting::factory()->create(['account_id' => $this->broker->id, 'minimum_balance' => 5000]);
    });

    it('never warns on its page, its tab or the all-accounts view', function () {
        $forecast = byTypeForecast($this->user, ['account' => $this->broker->id]);

        expect($forecast['alert']['level'])->toBe('ok')
            ->and($forecast['account'])->toMatchArray(['projectionOnly' => true, 'alertThreshold' => null])
            ->and(collect($forecast['accounts'])->firstWhere('id', $this->broker->id)['hasAlert'])->toBeFalse()
            ->and(byTypeForecast($this->user, ['account' => 'all'])['alert']['level'])->toBe('ok');
    });

    it('never stores an alert, so no email, bell or dashboard card', function () {
        $this->artisan('forecast:refresh')->assertSuccessful();

        expect(ForecastAlert::query()->count())->toBe(0);
        Mail::assertNothingQueued();
    });
});

describe('the transfer that fixes a short checking account', function () {
    beforeEach(function () {
        $this->checking = byTypeAccount($this->user, 'Checking', AccountType::Checking, 10000);
        byTypeLine($this->user, $this->checking, 'Rent', -100000, '2026-01-05');
    });

    it('is never offered from an investment, a house or another account that is not liquid', function () {
        byTypeAccount($this->user, 'Broker', AccountType::Investment, 90000000);
        byTypeAccount($this->user, 'Pension', AccountType::Retirement, 90000000);
        byTypeAccount($this->user, 'Wallet', AccountType::Others, 90000000);
        $house = byTypeAccount($this->user, 'House', AccountType::RealEstate, 90000000);
        ForecastAccountSetting::factory()->create(['account_id' => $house->id, 'included' => true]);

        $forecast = byTypeForecast($this->user, ['account' => $this->checking->id]);
        $all = byTypeForecast($this->user, ['account' => 'all']);

        expect($forecast['alert'])->toMatchArray(['level' => 'strong', 'transfer' => null])
            ->and($all['alert'])->toMatchArray(['level' => 'short', 'accountId' => $this->checking->id, 'transfer' => null]);
    });

    it('comes from a savings account even when an investment has more to spare', function () {
        byTypeAccount($this->user, 'Broker', AccountType::Investment, 90000000);
        $savings = byTypeAccount($this->user, 'Savings', AccountType::Savings, 5000000);

        $forecast = byTypeForecast($this->user, ['account' => $this->checking->id]);
        $all = byTypeForecast($this->user, ['account' => 'all']);

        expect($forecast['alert']['transfer']['fromAccountId'])->toBe($savings->id)
            ->and($all['alert'])->toMatchArray(['level' => 'split'])
            ->and($all['alert']['transfer']['fromAccountId'])->toBe($savings->id);
    });
});

describe('with the Forecast flag off', function () {
    it('has no forecast page and its settings route is not reached', function () {
        Feature::for($this->user)->deactivate(Forecast::class);
        $house = byTypeAccount($this->user, 'House', AccountType::RealEstate);

        $this->actingAs($this->user)->get(route('forecast.index'))->assertNotFound();
        $this->actingAs($this->user)->patch(route('forecast.accounts.update', $house), ['included' => true])->assertNotFound();

        expect(ForecastAccountSetting::query()->count())->toBe(0);
    });
});
