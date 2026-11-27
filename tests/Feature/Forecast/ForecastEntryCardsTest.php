<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\ForecastAlertLevel;
use App\Enums\ForecastFrequency;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\ForecastAlert;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\ForecastAlertService;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A checking account with 1,000 on 26 Nov 2026, paid 3,000 on the 1st of
 * each month, rent of 1,900 on the 5th, and a yearly car insurance of 7,000
 * every 10 April: April 2027 is the only month it runs short (down to −500
 * on the 10th).
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR', 'timezone' => 'Europe/Madrid']);
    Feature::for($this->user)->activate(Forecast::class);

    $this->checking = entryCardsAccount($this->user, 'Checking', AccountType::Checking, 100000);
    entryCardsLine($this->user, $this->checking, 'Salary', 300000, '2026-01-01');
    entryCardsLine($this->user, $this->checking, 'Rent', -190000, '2026-01-05');
    entryCardsLine($this->user, $this->checking, 'Car insurance', -700000, '2026-04-10', ForecastFrequency::Yearly);
});

function entryCardsAccount(User $user, string $name, AccountType $type, int $balance, string $currency = 'EUR'): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency_code' => $currency]);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-20', 'balance' => $balance]);

    return $account;
}

function entryCardsLine(User $user, Account $account, string $name, int $amount, string $anchor, ForecastFrequency $frequency = ForecastFrequency::Monthly, array $attributes = []): ForecastItem
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

function entryCardsAlert(User $user, Account $account, string $month, array $attributes = []): ForecastAlert
{
    return ForecastAlert::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'month' => $month.'-01',
        'lowest_balance' => -15500,
        'lowest_on' => $month.'-27',
        'causes' => [['name' => 'Car insurance', 'amount' => 70000]],
        ...$attributes,
    ]);
}

/**
 * The dashboard's forecast warning, as the page receives it.
 */
function dashboardForecastAlert(User $user): ?array
{
    $alert = null;

    actingAs($user)->get(route('dashboard'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$alert) {
        $page->has('forecastAlert');
        $alert = $page->toArray()['props']['forecastAlert'];
    });

    return $alert;
}

function cashflowRestOfMonth(User $user): ?array
{
    $rest = null;

    actingAs($user)->get(route('cashflow'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$rest) {
        $page->missing('forecastRestOfMonth')->loadDeferredProps(function (AssertableInertia $reload) use (&$rest) {
            $reload->has('forecastRestOfMonth');
            $rest = $reload->toArray()['props']['forecastRestOfMonth'];
        });
    });

    return $rest;
}

describe('the dashboard card', function () {
    it('is not sent at all without the flag, even with a strong alert', function () {
        Feature::for($this->user)->deactivate(Forecast::class);
        entryCardsAlert($this->user, $this->checking, '2027-04');

        actingAs($this->user)->get(route('dashboard'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('forecastAlert')
            ->loadDeferredProps('dashboard', fn (AssertableInertia $reload) => $reload->missing('forecastAlert')));

        expect(ForecastAlert::query()->sole()->snoozed_until)->toBeNull();
    });

    it('shows the strong alert the refresh stored, with what it found', function () {
        app(ForecastAlertService::class)->refresh($this->user);

        expect(dashboardForecastAlert($this->user))->toBe([
            'id' => ForecastAlert::query()->sole()->id,
            'accountId' => $this->checking->id,
            'accountName' => 'Checking',
            'currencyCode' => 'EUR',
            'month' => '2027-04',
            'lowestBalance' => -50000,
            'lowestOn' => '2027-04-10',
            'causes' => [['name' => 'Car insurance', 'amount' => 700000]],
            'remindOn' => '2027-03-01',
        ]);
    });

    it('is null without a strong alert', function (array $attributes) {
        entryCardsAlert($this->user, $this->checking, '2027-04', $attributes);

        expect(dashboardForecastAlert($this->user))->toBeNull();
    })->with([
        'soft' => [['level' => ForecastAlertLevel::Soft]],
        'resolved' => [['resolved_at' => '2026-11-20 10:00:00']],
        'snoozed' => [['snoozed_until' => '2027-03-01']],
        'past month' => [['month' => '2026-10-01']],
        'beyond twelve months' => [['month' => '2027-12-01']],
    ]);

    it('comes back on the day it was snoozed until', function () {
        entryCardsAlert($this->user, $this->checking, '2027-04', ['snoozed_until' => '2026-11-26']);

        expect(dashboardForecastAlert($this->user)['month'])->toBe('2027-04');
    });

    it('shows the soonest problem month first', function () {
        entryCardsAlert($this->user, $this->checking, '2027-05');
        entryCardsAlert($this->user, $this->checking, '2027-02');

        expect(dashboardForecastAlert($this->user)['month'])->toBe('2027-02');
    });

    it('has nothing to put off when the reminder would already be due', function () {
        entryCardsAlert($this->user, $this->checking, '2026-12');

        expect(dashboardForecastAlert($this->user)['remindOn'])->toBeNull();
    });

    it('covers only next month without Pro', function () {
        $free = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
        Feature::for($free)->activate(Forecast::class);
        $account = entryCardsAccount($free, 'Main', AccountType::Checking, 1000);
        entryCardsAlert($free, $account, '2027-01');

        expect(dashboardForecastAlert($free))->toBeNull();

        entryCardsAlert($free, $account, '2026-12');

        expect(dashboardForecastAlert($free)['month'])->toBe('2026-12');
    });

    it('never shows another user\'s alert', function () {
        $other = User::factory()->onboarded()->create();
        entryCardsAlert($other, entryCardsAccount($other, 'Theirs', AccountType::Checking, 1000), '2027-04');

        expect(dashboardForecastAlert($this->user))->toBeNull();
    });
});

describe('snoozing', function () {
    it('hides the account\'s problem from that month on until the first of the month before', function () {
        $april = entryCardsAlert($this->user, $this->checking, '2027-04');
        $may = entryCardsAlert($this->user, $this->checking, '2027-05');
        $march = entryCardsAlert($this->user, $this->checking, '2027-03');

        actingAs($this->user)->post(route('forecast.alerts.snooze', $april))->assertNoContent();

        expect($april->fresh()->snoozed_until->toDateString())->toBe('2027-03-01')
            ->and($may->fresh()->snoozed_until->toDateString())->toBe('2027-03-01')
            ->and($march->fresh()->snoozed_until)->toBeNull()
            ->and(dashboardForecastAlert($this->user)['month'])->toBe('2027-03');
    });

    it('puts away a month that turns short after the snooze, as the same problem', function () {
        $service = app(ForecastAlertService::class);
        $service->refresh($this->user);
        actingAs($this->user)->post(route('forecast.alerts.snooze', ForecastAlert::query()->sole()))->assertNoContent();
        // A second insurance in May makes that month short too.
        entryCardsLine($this->user, $this->checking, 'Property tax', -900000, '2026-05-20', ForecastFrequency::Yearly);

        $service->refresh($this->user);

        expect(ForecastAlert::query()->where('month', '2027-05-01')->sole()->snoozed_until->toDateString())->toBe('2027-03-01')
            ->and(dashboardForecastAlert($this->user))->toBeNull();
    });

    it('is refused when the reminder would already be due', function () {
        $alert = entryCardsAlert($this->user, $this->checking, '2026-12');

        actingAs($this->user)->post(route('forecast.alerts.snooze', $alert))->assertUnprocessable();

        expect($alert->fresh()->snoozed_until)->toBeNull();
    });

    it('is refused for another user\'s alert', function () {
        $other = User::factory()->onboarded()->paywallSeen()->create();
        Feature::for($other)->activate(Forecast::class);
        $alert = entryCardsAlert($this->user, $this->checking, '2027-04');

        actingAs($other)->post(route('forecast.alerts.snooze', $alert))->assertForbidden();

        expect($alert->fresh()->snoozed_until)->toBeNull();
    });

    it('does not exist without the flag', function () {
        Feature::for($this->user)->deactivate(Forecast::class);
        $alert = entryCardsAlert($this->user, $this->checking, '2027-04');

        actingAs($this->user)->post(route('forecast.alerts.snooze', $alert))->assertNotFound();
    });

    it('survives the hourly refresh, and is dropped when the problem comes back as a new one', function () {
        $service = app(ForecastAlertService::class);
        $service->refresh($this->user);
        $alert = ForecastAlert::query()->sole();
        actingAs($this->user)->post(route('forecast.alerts.snooze', $alert))->assertNoContent();

        $service->refresh($this->user);
        expect($alert->fresh()->snoozed_until->toDateString())->toBe('2027-03-01');

        $alert->update(['resolved_at' => now()->subDays(10)]);
        $service->refresh($this->user);
        expect($alert->fresh()->snoozed_until)->toBeNull();
    });
});

describe('stored alert details', function () {
    it('writes nothing when a refresh finds the same thing', function () {
        $service = app(ForecastAlertService::class);
        $service->refresh($this->user);
        $before = ForecastAlert::query()->sole()->updated_at;

        $this->travel(1)->hours();
        $service->refresh($this->user);

        expect(ForecastAlert::query()->sole()->updated_at->equalTo($before))->toBeTrue();
    });

    it('follows the forecast when the dip changes', function () {
        $service = app(ForecastAlertService::class);
        $service->refresh($this->user);
        ForecastItem::query()->where('name', 'Car insurance')->sole()->versions()->update(['amount' => -680000]);

        $service->refresh($this->user);

        expect(ForecastAlert::query()->sole())
            ->lowest_balance->toBe(-30000)
            ->causes->toBe([['name' => 'Car insurance', 'amount' => 680000]]);
    });
});

describe('the cashflow card', function () {
    it('is not sent at all without the flag', function () {
        Feature::for($this->user)->deactivate(Forecast::class);

        $response = actingAs($this->user)->get(route('cashflow'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('forecastRestOfMonth'));

        expect($response->viewData('page')['deferredProps'] ?? [])->toBe([]);
    });

    it('is null without a forecast line', function () {
        ForecastItem::query()->delete();

        expect(cashflowRestOfMonth($this->user))->toBeNull();
    });

    it('lists what is still to come this month and the balance it closes on', function () {
        $savings = entryCardsAccount($this->user, 'Savings', AccountType::Savings, 900000);
        $broker = entryCardsAccount($this->user, 'Broker', AccountType::Investment, 5000000);
        $dollars = entryCardsAccount($this->user, 'Dollars', AccountType::Checking, 10000, 'USD');
        entryCardsLine($this->user, $this->checking, 'Gym', -4000, '2026-01-28');
        entryCardsLine($this->user, $this->checking, 'Freelance', 50000, '2026-11-29', ForecastFrequency::Once);
        entryCardsLine($this->user, $this->checking, 'To savings', -10000, '2026-01-30', attributes: ['destination_account_id' => $savings->id]);
        entryCardsLine($this->user, $this->checking, 'To broker', -20000, '2026-01-30', attributes: ['destination_account_id' => $broker->id]);
        entryCardsLine($this->user, $dollars, 'Dollar fee', -500, '2026-01-28');
        $water = entryCardsLine($this->user, $this->checking, 'Water', -2000, '2026-01-27');
        ForecastMatch::factory()->create(['forecast_item_id' => $water->id, 'occurrence_date' => '2026-11-27']);
        $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries', 'type' => CategoryType::Expense]);
        ForecastEstimateAdjustment::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->checking->id,
            'category_id' => $groceries->id,
            'starts_on' => '2026-11-01',
            'amount' => 1300,
        ]);

        $rest = cashflowRestOfMonth($this->user);

        expect($rest)->month->toBe('2026-11')
            ->currencyCode->toBe('EUR')
            ->lines->toBe([
                ['name' => 'Gym', 'date' => '2026-11-28', 'amount' => -4000],
                ['name' => 'Freelance', 'date' => '2026-11-29', 'amount' => 50000],
                ['name' => 'To broker', 'date' => '2026-11-30', 'amount' => -20000],
            ])
            ->estimates->toBe([['name' => 'Groceries', 'kind' => 'expense', 'amount' => -1300]])
            ->endBalance->toBe(100000 + 900000 - 4000 + 50000 - 20000 - 1300);
    });
});
