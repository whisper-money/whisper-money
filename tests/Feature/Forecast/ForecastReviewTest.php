<?php

use App\Enums\AccountType;
use App\Enums\ForecastEstimateKind;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Enums\ForecastSuggestionKind;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ExchangeRate;
use App\Models\ForecastDismissal;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastItem;
use App\Models\ForecastReviewCompletion;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\ForecastPlanBuilder;
use App\Services\Forecast\ForecastReviewPresenter;
use App\Services\Forecast\ForecastSuggestionCount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/**
 * A checking account paid a salary on the 28th, charged rent on the 1st and
 * a streaming service and a restaurant on the 15th and 16th for the last 12 months, and
 * moved 500 € to savings on the 2nd; a home insurance is charged once a
 * year in June. Everything has a category but the insurance and the
 * transfer, which pairs with its savings leg.
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);
    $this->spaceId = $this->user->activeSpace()->id;

    $this->checking = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'name' => 'Checking', 'currency_code' => 'EUR']);
    $this->savings = Account::factory()->create(['type' => AccountType::Savings, 'user_id' => $this->user->id, 'name' => 'Savings', 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $this->checking->id, 'balance_date' => '2026-11-20', 'balance' => 300000]);

    $this->salary = forecastIncomeCategory($this->user, name: 'Salary');
    $this->housing = forecastExpenseCategory($this->user, name: 'Housing');
    $this->leisure = forecastExpenseCategory($this->user, name: 'Leisure');

    forecastMonthlyCharges($this->checking, '2025-11-28', 12, 250000, 'NOMINA ACME SL', $this->salary);
    forecastMonthlyCharges($this->checking, '2025-12-01', 12, -85000, 'ALQUILER PISO CENTRO', $this->housing);
    forecastMonthlyCharges($this->checking, '2025-12-15', 12, -1299, 'NETFLIX COM', $this->leisure);
    forecastMonthlyCharges($this->checking, '2025-12-16', 12, -4701, 'RESTAURANTE EL PUERTO', $this->leisure);
    forecastMonthlyCharges($this->checking, '2026-08-02', 4, -50000, 'TRASPASO AHORRO');
    forecastMonthlyCharges($this->savings, '2026-08-02', 4, 50000, 'TRASPASO AHORRO');
    forecastCharge($this->checking, '2025-06-10', -32000, 'MAPFRE SEGURO HOGAR POLIZA');
    forecastCharge($this->checking, '2026-06-08', -34500, 'MAPFRE SEGURO HOGAR POLIZA');
});

/**
 * @return array<string, mixed>
 */
function reviewData(User $user, ?string $accountId = null): array
{
    return app(ForecastReviewPresenter::class)->present($user, $user->activeSpace()->id, $accountId);
}

/**
 * The key of the yearly suggestion named like `$name`.
 */
function reviewKey(array $review, string $name): string
{
    return collect($review['annual'])->firstOrFail(fn (array $suggestion): bool => str_contains(strtolower($suggestion['name']), strtolower($name)))['key'];
}

/**
 * @param  array<string, mixed>  $answers
 */
function postReview(User $user, array $answers)
{
    return test()->actingAs($user)->from(route('forecast.index'))->post(route('forecast.review.store'), [
        'annual' => [],
        'dismissed' => [],
        'manual' => [],
        ...$answers,
    ]);
}

/**
 * The rows of one side of step 1, as [name, amount, basis].
 *
 * @return list<array{0: string|null, 1: int, 2: string}>
 */
function reviewRows(array $review, string $side): array
{
    return array_map(fn (array $row): array => [$row['name'], $row['amount'], $row['basis']], $review['estimates'][$side]);
}

describe('with the Forecast flag off', function () {
    beforeEach(function () {
        Feature::for($this->user)->deactivate(Forecast::class);
    });

    it('does not show the review', function () {
        $this->actingAs($this->user)->get(route('forecast.index'))->assertNotFound();
    });

    it('does not take answers', function () {
        postReview($this->user, ['manual' => [['name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $this->checking->id, 'frequency' => 'yearly']]])
            ->assertNotFound();

        expect(ForecastItem::query()->count())->toBe(0)
            ->and(ForecastDismissal::query()->count())->toBe(0)
            ->and(ForecastReviewCompletion::query()->count())->toBe(0);
    });

    it('does not take an edit of step 1', function () {
        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => $this->salary->id, 'month' => '2026-12', 'amount' => 260000, 'scope' => 'from_here',
        ])->assertNotFound();

        expect(ForecastEstimateAdjustment::query()->count())->toBe(0);
    });

    it('does not count suggestions in the weekly refresh', function () {
        $this->travelTo('2026-11-30 06:10:00');
        $this->user->update(['timezone' => 'UTC']);

        $this->artisan('forecast:refresh')->assertSuccessful();

        expect(Cache::has("forecast:new-yearly-suggestions:{$this->user->id}:{$this->spaceId}"))->toBeFalse();
    });
});

describe('the review data', function () {
    it('is only sent when the page asks for it, for every account even when the page shows one', function () {
        $this->actingAs($this->user)->get(route('forecast.index', ['account' => $this->savings->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('review')
                ->missing('newSuggestionCount')
                ->where('reviewPrompt.hasLines', false)
                ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('newSuggestionCount', 1))
                ->reloadOnly('review', fn (AssertableInertia $reload) => $reload
                    ->where('review.accountId', null)
                    ->missing('review.recurring')
                    ->has('review.annual', 1)
                    ->has('review.accounts', 2)));
    });

    it('reviews every account in the "All accounts" view', function () {
        $this->actingAs($this->user)->get(route('forecast.index', ['account' => 'all']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->reloadOnly('review', fn (AssertableInertia $reload) => $reload->where('review.accountId', null)));
    });

    it('shows the charges a yearly suggestion stands on', function () {
        $insurance = reviewData($this->user)['annual'][0];

        expect($insurance['accountName'])->toBe('Checking')
            ->and($insurance['evidence'])->toBe([
                ['date' => '2025-06-10', 'amount' => -32000],
                ['date' => '2026-06-08', 'amount' => -34500],
            ]);
    });

    it('lists only the accounts the forecast includes', function () {
        Account::factory()->create(['type' => AccountType::Checking, 'user_id' => User::factory()->create()->id]);

        expect(collect(reviewData($this->user)['accounts'])->pluck('id')->all())
            ->toEqualCanonicalizing([$this->checking->id, $this->savings->id]);
    });
});

describe('step 1: how next month is estimated', function () {
    it('lists each income category, then each expense, with next month\'s estimate and its basis', function () {
        $estimates = reviewData($this->user)['estimates'];

        expect($estimates['month'])->toBe('2026-12')
            ->and($estimates['currencyCode'])->toBe('EUR')
            ->and($estimates['unusableBudgets'])->toBe([])
            ->and(reviewRows(['estimates' => $estimates], 'income'))->toBe([['Salary', 250000, 'median']])
            ->and(reviewRows(['estimates' => $estimates], 'expenses'))->toBe([['Housing', 85000, 'median'], ['Leisure', 6000, 'median']])
            ->and($estimates['income'][0])->toMatchArray([
                'accountId' => $this->checking->id,
                'accountName' => 'Checking',
                'currencyCode' => 'EUR',
                'categoryId' => $this->salary->id,
                'kind' => 'income',
            ]);
    });

    it('puts "Other income" and "Other spending" last', function () {
        forecastMonthlyCharges($this->checking, '2026-08-20', 3, -150000, fn (int $index): string => "CASH {$index}");
        forecastMonthlyCharges($this->checking, '2026-08-21', 3, 40000, fn (int $index): string => "BIZUM {$index}");

        $review = reviewData($this->user);

        expect(reviewRows($review, 'expenses'))->toBe([['Housing', 85000, 'median'], ['Leisure', 6000, 'median'], [null, 150000, 'median']])
            ->and(reviewRows($review, 'income'))->toBe([['Salary', 250000, 'median'], [null, 40000, 'median']])
            ->and(collect($review['estimates']['expenses'])->last()['categoryId'])->toBeNull();
    });

    it('lists the usual transfers apart and leaves them out of the totals', function () {
        $estimates = reviewData($this->user)['estimates'];

        expect($estimates['transfers'])->toHaveCount(1)
            ->and($estimates['transfers'][0])->toMatchArray([
                'fromAccountName' => 'Checking',
                'toAccountName' => 'Savings',
                'amount' => 50000,
                'currencyCode' => 'EUR',
            ])
            ->and($estimates['totals'])->toBe(['income' => 250000, 'expenses' => 91000, 'net' => 159000]);
    });

    it('narrows to the account reviewed', function () {
        $estimates = reviewData($this->user, $this->savings->id)['estimates'];

        expect($estimates['income'])->toBe([])
            ->and($estimates['expenses'])->toBe([])
            ->and($estimates['transfers'])->toHaveCount(1)
            ->and($estimates['totals'])->toBe(['income' => 0, 'expenses' => 0, 'net' => 0]);
    });

    it('reviews every included account when the one asked for is left out', function () {
        $loan = Account::factory()->create(['type' => AccountType::Loan, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);

        expect(reviewData($this->user, $loan->id)['accountId'])->toBeNull();
    });

    it('adds the totals up in the user\'s currency', function () {
        ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-11-26', 'rates' => ['eur' => 1.0, 'usd' => 2.0]]);
        $dollars = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'name' => 'Dollars', 'currency_code' => 'USD']);
        forecastMonthlyCharges($dollars, '2026-08-10', 3, -20000, 'GROCER', forecastExpenseCategory($this->user, name: 'Groceries'));

        $estimates = reviewData($this->user)['estimates'];
        $groceries = collect($estimates['expenses'])->firstWhere('name', 'Groceries');

        expect($groceries['amount'])->toBe(20000)
            ->and($groceries['currencyCode'])->toBe('USD')
            ->and($estimates['totals']['expenses'])->toBe(91000 + 10000)
            ->and($estimates['unconvertedCurrencies'])->toBe([]);
    });

    it('leaves a currency without a rate out of the totals, and says so', function () {
        Http::fake(['*' => Http::response([], 404)]);
        $pesos = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'MXN']);
        forecastMonthlyCharges($pesos, '2026-08-10', 3, -20000, 'GROCER', forecastExpenseCategory($this->user, name: 'Groceries'));

        $estimates = reviewData($this->user)['estimates'];

        expect($estimates['totals']['expenses'])->toBe(91000)
            ->and($estimates['unconvertedCurrencies'])->toBe(['MXN']);
    });

    it('shows an edit made in place from next month on, on its side', function () {
        $this->actingAs($this->user)->from(route('forecast.index'))->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => $this->salary->id, 'month' => '2026-12', 'amount' => 260000, 'scope' => 'from_here',
        ])->assertSessionHasNoErrors();

        $adjustment = ForecastEstimateAdjustment::query()->sole();
        $review = reviewData($this->user);

        expect($adjustment->kind)->toBe(ForecastEstimateKind::Income)
            ->and($adjustment->starts_on->toDateString())->toBe('2026-12-01')
            ->and($adjustment->ends_on)->toBeNull()
            ->and(reviewRows($review, 'income'))->toBe([['Salary', 260000, 'adjustment']])
            ->and($review['estimates']['totals']['income'])->toBe(260000);
    });

    it('keeps a category the user set to zero, so it can be changed back', function () {
        $this->actingAs($this->user)->from(route('forecast.index'))->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => $this->leisure->id, 'month' => '2026-12', 'amount' => 0, 'scope' => 'from_here',
        ])->assertSessionHasNoErrors();

        expect(reviewRows(reviewData($this->user), 'expenses'))->toBe([['Housing', 85000, 'median'], ['Leisure', 0, 'adjustment']]);
    });

    it('shows a user without Pro next month too', function () {
        $free = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
        Feature::for($free)->activate(Forecast::class);
        $account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $free->id, 'currency_code' => 'EUR']);
        forecastMonthlyCharges($account, '2026-08-01', 3, -85000, 'ALQUILER', forecastExpenseCategory($free, name: 'Housing'));

        $estimates = reviewData($free)['estimates'];

        expect($estimates['month'])->toBe('2026-12')
            ->and(reviewRows(['estimates' => $estimates], 'expenses'))->toBe([['Housing', 85000, 'median']]);
    });

    it('sends nothing to estimate without an account', function () {
        $empty = User::factory()->onboarded()->paywallSeen()->create();

        expect(reviewData($empty))->toMatchArray(['estimates' => null, 'uncategorized' => null, 'annual' => [], 'accounts' => []]);
    });
});

describe('the uncategorized notice', function () {
    /**
     * An account that spent `$categorized` with a category and `$uncategorized`
     * without one in each of August, September and October.
     */
    beforeEach(function () {
        $this->solo = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
        $this->soloAccount = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->solo->id, 'currency_code' => 'EUR']);
        $this->spend = function (int $categorized, int $uncategorized): void {
            $groceries = forecastExpenseCategory($this->solo, name: 'Groceries');
            forecastMonthlyCharges($this->soloAccount, '2026-08-05', 3, -$categorized, 'SUPERMARKET', $groceries);
            forecastMonthlyCharges($this->soloAccount, '2026-08-06', 3, -$uncategorized, fn (int $index): string => "SHOP {$index}");
        };
    });

    it('is not there for the test account, which has everything categorized', function () {
        expect(reviewData($this->user)['uncategorized'])->toBeNull();
    });

    it('says how many transactions have no category and how much a month, above 20 %', function () {
        ($this->spend)(60000, 30000);

        expect(reviewData($this->solo)['uncategorized'])->toBe([
            'count' => 3,
            'monthlyAmount' => 30000,
            'currencyCode' => 'EUR',
            'from' => '2026-08-01',
            'to' => '2026-10-31',
            'accountIds' => [$this->soloAccount->id],
        ]);
    });

    it('is not there at exactly 20 %', function () {
        ($this->spend)(80000, 20000);

        expect(reviewData($this->solo)['uncategorized'])->toBeNull();
    });

    it('is there just above 20 %', function () {
        ($this->spend)(80000, 20001);

        expect(reviewData($this->solo)['uncategorized'])->not->toBeNull();
    });

    it('counts money in as well as money out', function () {
        ($this->spend)(80000, 0);
        forecastMonthlyCharges($this->soloAccount, '2026-08-28', 3, 30000, fn (int $index): string => "BIZUM {$index}");

        expect(reviewData($this->solo)['uncategorized']['monthlyAmount'])->toBe(30000);
    });

    it('leaves transfers between the user\'s own accounts out', function () {
        ($this->spend)(80000, 10000);
        $savings = Account::factory()->create(['type' => AccountType::Savings, 'user_id' => $this->solo->id, 'currency_code' => 'EUR']);
        forecastMonthlyCharges($this->soloAccount, '2026-08-10', 3, -500000, 'TRASPASO');
        forecastMonthlyCharges($savings, '2026-08-10', 3, 500000, 'TRASPASO');

        expect(reviewData($this->solo)['uncategorized'])->toBeNull();
    });

    it('reads only the last three complete months', function () {
        ($this->spend)(80000, 10000);
        forecastCharge($this->soloAccount, '2026-11-03', -900000, 'BIG SHOP THIS MONTH');
        forecastCharge($this->soloAccount, '2026-07-03', -900000, 'BIG SHOP IN JULY');

        expect(reviewData($this->solo)['uncategorized'])->toBeNull();
    });

    it('reads only the account reviewed', function () {
        ($this->spend)(80000, 0);
        $other = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->solo->id, 'currency_code' => 'EUR']);
        forecastMonthlyCharges($other, '2026-08-06', 3, -50000, fn (int $index): string => "SHOP {$index}");

        expect(reviewData($this->solo, $this->soloAccount->id)['uncategorized'])->toBeNull()
            ->and(reviewData($this->solo, $other->id)['uncategorized']['count'])->toBe(3)
            ->and(reviewData($this->solo)['uncategorized']['count'])->toBe(3);
    });

    it('converts to the user\'s currency', function () {
        ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-11-26', 'rates' => ['eur' => 1.0, 'usd' => 2.0]]);
        ($this->spend)(80000, 0);
        $dollars = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->solo->id, 'currency_code' => 'USD']);
        forecastMonthlyCharges($dollars, '2026-08-06', 3, -60000, fn (int $index): string => "SHOP {$index}");

        expect(reviewData($this->solo)['uncategorized']['monthlyAmount'])->toBe(30000);
    });
});

describe('confirming', function () {
    it('marks the review answered in the space, even with no line, until the user starts over', function () {
        $page = fn () => $this->actingAs($this->user)->get(route('forecast.index'));

        $page()->assertInertia(fn (AssertableInertia $page) => $page->where('reviewPrompt', ['hasLines' => false, 'reviewed' => false]));

        postReview($this->user, [])->assertSessionHasNoErrors();

        $page()->assertInertia(fn (AssertableInertia $page) => $page->where('reviewPrompt', ['hasLines' => false, 'reviewed' => true]));
        expect(ForecastReviewCompletion::query()->sole())
            ->user_id->toBe($this->user->id)
            ->space_id->toBe($this->spaceId);

        $this->actingAs($this->user)->post(route('forecast.start-over'))->assertSessionHasNoErrors();

        expect(ForecastReviewCompletion::query()->count())->toBe(0);
        $page()->assertInertia(fn (AssertableInertia $page) => $page->where('reviewPrompt.reviewed', false));
    });

    it('marks it answered only in the space it was answered in', function () {
        postReview($this->user, [])->assertSessionHasNoErrors();
        $other = Space::factory()->create();

        expect(ForecastReviewCompletion::query()->where('user_id', $this->user->id)->forSpace($other->id)->exists())->toBeFalse()
            ->and(ForecastReviewCompletion::query()->where('user_id', $this->user->id)->forSpace($this->spaceId)->exists())->toBeTrue();
    });

    it('no longer creates a line for what repeats every month', function () {
        postReview($this->user, [])
            ->assertRedirect(route('forecast.index'))
            ->assertSessionHasNoErrors();

        expect(ForecastItem::query()->count())->toBe(0)
            ->and(ForecastItem::query()->where('source', ForecastItemSource::Detected)->exists())->toBeFalse();
    });

    it('refuses the old step 1 answers', function () {
        postReview($this->user, ['lines' => [['keys' => ["recurring|{$this->checking->id}|alquiler piso centro"]]]])
            ->assertSessionHasNoErrors();

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('adds a yearly suggestion that projects, and pairs its charges', function () {
        postReview($this->user, ['annual' => [reviewKey(reviewData($this->user), 'mapfre')]])
            ->assertSessionHasNoErrors();

        $insurance = ForecastItem::query()->sole();
        $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->spaceId);

        expect($insurance->frequency)->toBe(ForecastFrequency::Yearly)
            ->and($insurance->source)->toBe(ForecastItemSource::Manual)
            ->and($insurance->anchor_date->toDateString())->toBe('2025-06-09')
            ->and($insurance->matches()->count())->toBe(2)
            ->and(collect($plan->input->occurrences)->where('itemId', $insurance->id)->pluck('date')->all())->toBe(['2027-06-09']);
    });

    it('dismisses the yearly suggestions left out, which do not come back', function () {
        postReview($this->user, ['dismissed' => [reviewKey(reviewData($this->user), 'mapfre'), 'annual|x|unknown']])
            ->assertSessionHasNoErrors();

        expect(ForecastDismissal::query()->sole())
            ->fingerprint->toBe('mapfre seguro hogar poliza')
            ->kind->toBe(ForecastSuggestionKind::Annual)
            ->and(reviewData($this->user)['annual'])->toBe([]);
    });

    it('refuses a suggestion that is not on offer', function () {
        postReview($this->user, ['annual' => ["annual|{$this->checking->id}|made up"]])
            ->assertSessionHasErrors('annual');

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('stores the answers once when they are sent twice', function () {
        $answers = ['annual' => [reviewKey(reviewData($this->user), 'mapfre')]];

        postReview($this->user, $answers)->assertSessionHasNoErrors();
        postReview($this->user, $answers)->assertSessionHasErrors('annual');

        expect(ForecastItem::query()->count())->toBe(1);
    });

    it('adds the yearly and one-off payments typed in', function () {
        postReview($this->user, ['manual' => [
            ['name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $this->checking->id, 'frequency' => 'yearly'],
            ['name' => 'Extra pay', 'amount' => 150000, 'month' => 12, 'accountId' => $this->checking->id, 'frequency' => 'yearly'],
            ['name' => 'Community levy', 'amount' => -30000, 'month' => 2, 'accountId' => $this->savings->id, 'frequency' => 'once'],
            ['name' => 'Car tax', 'amount' => -12000, 'month' => 11, 'accountId' => $this->checking->id, 'frequency' => 'yearly'],
        ]])->assertSessionHasNoErrors();

        $lines = ForecastItem::query()->with('versions')->get()->keyBy('name');

        expect($lines['IBI']->anchor_date->toDateString())->toBe('2027-04-01')
            ->and($lines['IBI']->versions->sole()->amount)->toBe(-48000)
            ->and($lines['IBI']->source)->toBe(ForecastItemSource::Manual)
            ->and($lines['Extra pay']->anchor_date->toDateString())->toBe('2026-12-01')
            ->and($lines['Community levy']->kind)->toBe(ForecastItemKind::OneOff)
            ->and($lines['Community levy']->frequency)->toBe(ForecastFrequency::Once)
            ->and($lines['Community levy']->account_id)->toBe($this->savings->id)
            ->and($lines['Car tax']->anchor_date->toDateString())->toBe('2026-11-26');
    });

    it('validates the payments typed in', function (array $payment, string $error) {
        postReview($this->user, ['manual' => [[
            'name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $this->checking->id, 'frequency' => 'yearly',
            ...$payment,
        ]]])->assertSessionHasErrors($error);

        expect(ForecastItem::query()->count())->toBe(0);
    })->with([
        'no amount' => [['amount' => 0], 'manual.0.amount'],
        'month 13' => [['month' => 13], 'manual.0.month'],
        'monthly' => [['frequency' => 'monthly'], 'manual.0.frequency'],
        'no name' => [['name' => ''], 'manual.0.name'],
    ]);

    it('refuses another user\'s account', function () {
        $stranger = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => User::factory()->create()->id]);

        postReview($this->user, ['manual' => [['name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $stranger->id, 'frequency' => 'yearly']]])
            ->assertSessionHasErrors('manual.0.accountId');

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('lets a user without Pro answer it', function () {
        $free = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
        Feature::for($free)->activate(Forecast::class);
        $account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $free->id, 'currency_code' => 'EUR']);

        postReview($free, [
            'manual' => [['name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $account->id, 'frequency' => 'yearly']],
        ])->assertSessionHasNoErrors();

        expect(ForecastItem::query()->where('user_id', $free->id)->count())->toBe(1);
    });

    it('does not touch another user\'s suggestions', function () {
        $other = User::factory()->onboarded()->paywallSeen()->create();
        Feature::for($other)->activate(Forecast::class);

        postReview($other, ['annual' => [reviewKey(reviewData($this->user), 'mapfre')]])
            ->assertSessionHasErrors('annual');

        expect(ForecastItem::query()->count())->toBe(0);
    });
});

describe('the count of new suggestions', function () {
    it('counts the yearly ones only, and is forgotten once the review is answered', function () {
        expect(app(ForecastSuggestionCount::class)->for($this->user, $this->spaceId))->toBe(1);

        postReview($this->user, ['annual' => [reviewKey(reviewData($this->user), 'mapfre')]]);

        expect(app(ForecastSuggestionCount::class)->for($this->user, $this->spaceId))->toBe(0);
    });

    it('is worked out again in the weekly refresh', function () {
        expect(app(ForecastSuggestionCount::class)->for($this->user, $this->spaceId))->toBe(1);
        Transaction::query()->where('description', 'MAPFRE SEGURO HOGAR POLIZA')->delete();

        $this->user->update(['timezone' => 'UTC']);
        $this->travelTo('2026-11-30 06:10:00');
        $this->artisan('forecast:refresh')->assertSuccessful();

        expect(Cache::get("forecast:new-yearly-suggestions:{$this->user->id}:{$this->spaceId}"))->toBe(0);
    });
});
