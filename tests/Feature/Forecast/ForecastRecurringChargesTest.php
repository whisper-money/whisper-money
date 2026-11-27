<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\ForecastEstimateKind;
use App\Enums\ForecastSuggestionKind;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\ForecastDismissal;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastRecurringDrop;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\RecurringCharge;
use App\Services\Forecast\ForecastPlanBuilder;
use App\Services\Forecast\ForecastStartOver;
use App\Services\Forecast\RecurringCharges;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/**
 * "What you pay every month" on 26 Nov 2026: a checking account paying a
 * gym (45.00) and Netflix (14.99, then 15.99) every month in "Leisure", a
 * home insurance (300.00) every quarter, and transferring 500 to savings
 * every month; it is also paid a salary.
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);
    [$this->checking, $this->leisure] = recurringChargesHistory($this->user);
});

/**
 * @return array{0: Account, 1: Category}
 */
function recurringChargesHistory(User $user): array
{
    $checking = Account::factory()->create(['user_id' => $user->id, 'name' => 'Checking', 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $savings = Account::factory()->create(['user_id' => $user->id, 'name' => 'Savings', 'type' => AccountType::Savings, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-11-25', 'balance' => 500000]);

    $leisure = forecastExpenseCategory($user, name: 'Leisure');
    $streaming = forecastExpenseCategory($user, $leisure, 'Streaming');
    $insurance = forecastExpenseCategory($user, name: 'Insurance');
    $salary = forecastIncomeCategory($user, name: 'Salary');
    $transfer = forecastCategory($user, CategoryType::Transfer, name: 'Transfer');

    foreach (['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $month) {
        forecastCharge($checking, "{$month}-02", -4500, 'GYM', $leisure, ['creditor_name' => 'Basic Fit']);
        forecastCharge($checking, "{$month}-05", $month < '2026-10' ? -1499 : -1599, 'NETFLIX.COM', $streaming, ['creditor_name' => 'Netflix']);
        forecastCharge($checking, "{$month}-01", 250000, 'NOMINA', $salary, ['debtor_name' => 'ACME']);
        forecastCharge($checking, "{$month}-10", -50000, 'TO SAVINGS', $transfer, ['creditor_name' => 'Me']);
        forecastCharge($savings, "{$month}-10", 50000, 'FROM CHECKING', $transfer, ['debtor_name' => 'Me']);
    }

    foreach (['2026-02-12', '2026-05-12', '2026-08-12', '2026-11-12'] as $date) {
        forecastCharge($checking, $date, -30000, 'MAPFRE HOGAR', $insurance, ['creditor_name' => 'Mapfre']);
    }

    return [$checking, $leisure];
}

function recurringChargeNamed(User $user, string $name): RecurringCharge
{
    return collect(app(RecurringCharges::class)->for($user, $user->current_space_id))->firstOrFail(fn (RecurringCharge $charge): bool => $charge->name === $name);
}

function leisurePlanned(User $user, Account $account, string $categoryId, string $month): ?int
{
    $estimate = collect(app(ForecastPlanBuilder::class)->build($user, $user->current_space_id, [$account->id], 3)->categoryEstimates)
        ->first(fn (CategoryEstimate $estimate): bool => $estimate->categoryId === $categoryId && $estimate->month === $month && ! $estimate->isIncome());

    return $estimate?->planned;
}

it('lists the outgoing charges only, the most expensive a month first, at the median of their last three charges', function () {
    $charges = app(RecurringCharges::class)->for($this->user, $this->user->current_space_id);

    expect(array_map(fn (RecurringCharge $charge): array => [$charge->name, $charge->frequency->value, $charge->amount, $charge->monthly, $charge->yearly], $charges))->toBe([
        ['Mapfre', 'quarterly', 30000, 10000, 120000],
        ['Basic Fit', 'monthly', 4500, 4500, 54000],
        ['Netflix', 'monthly', 1599, 1599, 19188],
    ])
        ->and($charges[2]->categoryId)->toBe($this->leisure->id)
        ->and($charges[2]->lastChargedOn)->toBe('2026-11-05');
});

it('sends the card after the page, with its monthly total', function () {
    $this->actingAs($this->user)->get(route('forecast.index', ['account' => $this->checking->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('recurringCharges')
            ->loadDeferredProps('recurring-charges', fn (AssertableInertia $reload) => $reload
                ->has('recurringCharges.charges', 3)
                ->where('recurringCharges.charges.0.name', 'Mapfre')
                ->where('recurringCharges.charges.0.accountName', 'Checking')
                ->where('recurringCharges.charges.0.canDrop', true)
                ->where('recurringCharges.charges.0.drop', null)
                ->where('recurringCharges.charges.0.kept', false)
                ->where('recurringCharges.totals', [['currencyCode' => 'EUR', 'monthly' => 16099]])));
});

it('lowers next month’s category estimate by the charge when dropped, and leaves this month’s alone', function () {
    $netflix = recurringChargeNamed($this->user, 'Netflix');

    expect(leisurePlanned($this->user, $this->checking, $this->leisure->id, '2026-12'))->toBe(5999);

    $this->actingAs($this->user)
        ->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key])
        ->assertRedirect();

    $adjustment = ForecastEstimateAdjustment::query()->sole();

    expect($adjustment)->account_id->toBe($this->checking->id)
        ->kind->toBe(ForecastEstimateKind::Expense)
        ->category_id->toBe($this->leisure->id)
        ->amount->toBe(4400)
        ->and($adjustment->starts_on->toDateString())->toBe('2026-12-01')
        ->and($adjustment->ends_on)->toBeNull()
        ->and(leisurePlanned($this->user, $this->checking, $this->leisure->id, '2026-11'))->toBe(5999)
        ->and(leisurePlanned($this->user, $this->checking, $this->leisure->id, '2026-12'))->toBe(4400)
        ->and(leisurePlanned($this->user, $this->checking, $this->leisure->id, '2027-01'))->toBe(4400);

    $this->actingAs($this->user)->get(route('forecast.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('recurring-charges', fn (AssertableInertia $reload) => $reload
            ->where('recurringCharges.charges.2.drop.month', '2026-12')
            ->where('recurringCharges.charges.2.drop.reduction', 1599)
            ->where('recurringCharges.charges.1.drop', null)));
});

it('undoes a drop, which deletes its adjustment', function () {
    $netflix = recurringChargeNamed($this->user, 'Netflix');
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key]);

    $this->actingAs($this->user)
        ->delete(route('forecast.recurring-charges.drops.destroy', ForecastRecurringDrop::query()->sole()))
        ->assertRedirect();

    expect(ForecastEstimateAdjustment::query()->count())->toBe(0)
        ->and(ForecastRecurringDrop::query()->count())->toBe(0)
        ->and(leisurePlanned($this->user, $this->checking, $this->leisure->id, '2026-12'))->toBe(5999);
});

it('stacks two drops on one category, never below zero, and undoes each by what it took', function () {
    $netflix = recurringChargeNamed($this->user, 'Netflix');
    $gym = recurringChargeNamed($this->user, 'Basic Fit');
    ForecastEstimateAdjustment::factory()->create([
        'user_id' => $this->user->id, 'account_id' => $this->checking->id, 'category_id' => $this->leisure->id,
        'starts_on' => '2026-12-01', 'ends_on' => null, 'amount' => 5000,
    ]);

    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $gym->key]);
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key]);

    expect(ForecastEstimateAdjustment::query()->sole()->amount)->toBe(0)
        ->and(ForecastRecurringDrop::query()->pluck('reduction', 'charge_key')->all())->toBe([$gym->key => 4500, $netflix->key => 500]);

    $this->actingAs($this->user)->delete(route('forecast.recurring-charges.drops.destroy', ForecastRecurringDrop::query()->where('charge_key', $gym->key)->sole()));

    expect(ForecastEstimateAdjustment::query()->sole()->amount)->toBe(4500);

    $this->actingAs($this->user)->delete(route('forecast.recurring-charges.drops.destroy', ForecastRecurringDrop::query()->sole()));

    expect(ForecastEstimateAdjustment::query()->sole()->amount)->toBe(5000);
});

it('keeps the adjustment the drops made until the last of them is undone', function () {
    $netflix = recurringChargeNamed($this->user, 'Netflix');
    $gym = recurringChargeNamed($this->user, 'Basic Fit');
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key]);
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $gym->key]);

    $this->actingAs($this->user)->delete(route('forecast.recurring-charges.drops.destroy', ForecastRecurringDrop::query()->where('charge_key', $netflix->key)->sole()));

    expect(ForecastEstimateAdjustment::query()->sole()->amount)->toBe(1599);

    $this->actingAs($this->user)->delete(route('forecast.recurring-charges.drops.destroy', ForecastRecurringDrop::query()->sole()));

    expect(ForecastEstimateAdjustment::query()->count())->toBe(0);
});

it('leaves a figure the user set by hand since the drop alone on undo', function () {
    $netflix = recurringChargeNamed($this->user, 'Netflix');
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key]);
    ForecastEstimateAdjustment::query()->sole()->update(['amount' => 3000]);

    $this->actingAs($this->user)->delete(route('forecast.recurring-charges.drops.destroy', ForecastRecurringDrop::query()->sole()));

    expect(ForecastRecurringDrop::query()->count())->toBe(0)
        ->and(ForecastEstimateAdjustment::query()->sole()->amount)->toBe(3000);
});

it('refuses to drop a charge on a category set for next month only', function () {
    ForecastEstimateAdjustment::factory()->create([
        'user_id' => $this->user->id, 'account_id' => $this->checking->id, 'category_id' => $this->leisure->id,
        'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31', 'amount' => 5000,
    ]);

    $this->actingAs($this->user)
        ->post(route('forecast.recurring-charges.drops.store'), ['key' => recurringChargeNamed($this->user, 'Netflix')->key])
        ->assertSessionHasErrors('key');

    expect(ForecastRecurringDrop::query()->count())->toBe(0)
        ->and(ForecastEstimateAdjustment::query()->sole()->amount)->toBe(5000);
});

it('stops keeping a charge once it is dropped, and does not keep a dropped one', function () {
    $netflix = recurringChargeNamed($this->user, 'Netflix');
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.keeps.store'), ['key' => $netflix->key]);
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key]);

    expect(ForecastDismissal::query()->count())->toBe(0);

    $this->actingAs($this->user)->post(route('forecast.recurring-charges.keeps.store'), ['key' => $netflix->key]);

    expect(ForecastDismissal::query()->count())->toBe(0);
});

it('lets go of a kept charge that no longer repeats', function () {
    $key = RecurringCharge::keyOf($this->checking->id, 'gone');
    ForecastDismissal::factory()->create(['user_id' => $this->user->id, 'fingerprint' => $key, 'kind' => ForecastSuggestionKind::Kept]);

    $this->actingAs($this->user)->delete(route('forecast.recurring-charges.keeps.destroy'), ['key' => $key])->assertRedirect();

    expect(ForecastDismissal::query()->count())->toBe(0);
});

it('lowers "Other spending" for an uncategorised charge, leaving "Other income" alone', function () {
    foreach (['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $month) {
        forecastCharge($this->checking, "{$month}-15", -6000, 'PADEL CLUB', null, ['creditor_name' => 'Padel Club']);
    }
    $income = ForecastEstimateAdjustment::factory()->create([
        'user_id' => $this->user->id, 'account_id' => $this->checking->id, 'category_id' => null,
        'kind' => ForecastEstimateKind::Income, 'starts_on' => '2026-12-01', 'ends_on' => null, 'amount' => 20000,
    ]);

    $this->actingAs($this->user)
        ->post(route('forecast.recurring-charges.drops.store'), ['key' => recurringChargeNamed($this->user, 'Padel Club')->key])
        ->assertSessionHasNoErrors();

    $spend = ForecastEstimateAdjustment::query()->whereKeyNot($income->id)->sole();

    expect($spend)->category_id->toBeNull()
        ->kind->toBe(ForecastEstimateKind::Expense)
        ->amount->toBe(0)
        ->and($income->fresh()->amount)->toBe(20000);
});

it('drops a charge only once', function () {
    $netflix = recurringChargeNamed($this->user, 'Netflix');
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key]);
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $netflix->key]);

    expect(ForecastRecurringDrop::query()->count())->toBe(1)
        ->and(ForecastEstimateAdjustment::query()->sole()->amount)->toBe(4400);
});

it('lets a user without Pro drop a charge, since next month is the one they see', function () {
    $free = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
    Feature::for($free)->activate(Forecast::class);
    recurringChargesHistory($free);

    $this->actingAs($free)
        ->post(route('forecast.recurring-charges.drops.store'), ['key' => recurringChargeNamed($free, 'Netflix')->key])
        ->assertSessionHasNoErrors();

    expect(ForecastRecurringDrop::query()->where('user_id', $free->id)->count())->toBe(1);
});

it('remembers a kept charge, and forgets it on undo', function () {
    $gym = recurringChargeNamed($this->user, 'Basic Fit');

    $this->actingAs($this->user)->post(route('forecast.recurring-charges.keeps.store'), ['key' => $gym->key])->assertRedirect();

    expect(ForecastDismissal::query()->sole())->fingerprint->toBe($gym->key)->kind->toBe(ForecastSuggestionKind::Kept);

    $this->actingAs($this->user)->get(route('forecast.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->loadDeferredProps('recurring-charges', fn (AssertableInertia $reload) => $reload
            ->where('recurringCharges.charges.1.kept', true)
            ->where('recurringCharges.charges.0.kept', false)));

    $this->actingAs($this->user)->delete(route('forecast.recurring-charges.keeps.destroy'), ['key' => $gym->key])->assertRedirect();

    expect(ForecastDismissal::query()->count())->toBe(0);
});

it('does not let one user drop, keep or undo another user’s charge', function () {
    $other = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($other)->activate(Forecast::class);
    $key = recurringChargeNamed($this->user, 'Netflix')->key;
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $key]);

    $this->actingAs($other)->post(route('forecast.recurring-charges.drops.store'), ['key' => $key])->assertNotFound();
    $this->actingAs($other)->post(route('forecast.recurring-charges.keeps.store'), ['key' => $key])->assertNotFound();
    $this->actingAs($other)->delete(route('forecast.recurring-charges.drops.destroy', ForecastRecurringDrop::query()->sole()))->assertNotFound();

    expect(ForecastRecurringDrop::query()->count())->toBe(1)
        ->and(ForecastDismissal::query()->count())->toBe(0);
});

it('cannot drop a charge booked to an income category, which the estimates do not count as spend', function () {
    $refunds = forecastIncomeCategory($this->user, name: 'Refunds');
    foreach (['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $month) {
        forecastCharge($this->checking, "{$month}-20", -10000, 'DEVOLUCION', $refunds, ['creditor_name' => 'Pension Fund']);
    }
    $pension = recurringChargeNamed($this->user, 'Pension Fund');

    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $pension->key])->assertSessionHasErrors('key');

    expect(ForecastRecurringDrop::query()->count())->toBe(0);
});

it('goes with the forecast when the user starts over', function () {
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => recurringChargeNamed($this->user, 'Netflix')->key]);
    $this->actingAs($this->user)->post(route('forecast.recurring-charges.keeps.store'), ['key' => recurringChargeNamed($this->user, 'Basic Fit')->key]);

    app(ForecastStartOver::class)->startOver($this->user, $this->user->current_space_id);

    expect(ForecastRecurringDrop::query()->count())->toBe(0)
        ->and(ForecastDismissal::query()->count())->toBe(0);
});

describe('with the Forecast flag off', function () {
    beforeEach(function () {
        Feature::for($this->user)->deactivate(Forecast::class);
    });

    it('has none of the card’s endpoints, and no page to send it with', function () {
        $key = recurringChargeNamed($this->user, 'Netflix')->key;
        $drop = ForecastRecurringDrop::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get(route('forecast.index'))->assertNotFound();
        $this->actingAs($this->user)->post(route('forecast.recurring-charges.drops.store'), ['key' => $key])->assertNotFound();
        $this->actingAs($this->user)->delete(route('forecast.recurring-charges.drops.destroy', $drop))->assertNotFound();
        $this->actingAs($this->user)->post(route('forecast.recurring-charges.keeps.store'), ['key' => $key])->assertNotFound();
        $this->actingAs($this->user)->delete(route('forecast.recurring-charges.keeps.destroy'), ['key' => $key])->assertNotFound();

        expect(ForecastRecurringDrop::query()->count())->toBe(1)
            ->and(ForecastDismissal::query()->count())->toBe(0);
    });
});
