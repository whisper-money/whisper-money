<?php

use App\Enums\CategoryType;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Enums\ForecastMatchedBy;
use App\Enums\ForecastSuggestionKind;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\ForecastDismissal;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\ForecastMatcher;
use App\Services\Forecast\ForecastPlanBuilder;
use App\Services\Forecast\ForecastReviewPresenter;
use App\Services\Forecast\ForecastSuggestionCount;
use App\Services\Forecast\ForecastSuggestions;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/**
 * A checking account paid a salary on the 28th, charged rent on the 1st and
 * two streaming services on the 15th and 16th for the last 12 months, with a
 * home insurance charged once a year in June: three monthly suggestions, two
 * of them of the same kind, and one yearly.
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);
    $this->spaceId = $this->user->activeSpace()->id;

    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Checking', 'currency_code' => 'EUR']);
    $this->savings = Account::factory()->create(['user_id' => $this->user->id, 'name' => 'Savings', 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $this->checking->id, 'balance_date' => '2026-11-20', 'balance' => 300000]);

    $this->housing = forecastExpenseCategory($this->user, name: 'Housing');
    $this->leisure = forecastExpenseCategory($this->user, name: 'Leisure');

    $this->salary = forecastMonthlyCharges($this->checking, '2025-11-28', 12, 250000, 'NOMINA ACME SL');
    $this->rent = forecastMonthlyCharges($this->checking, '2025-12-01', 12, -85000, 'ALQUILER PISO CENTRO', $this->housing);
    $this->netflix = forecastMonthlyCharges($this->checking, '2025-12-15', 12, -1299, 'NETFLIX COM', $this->leisure);
    $this->hbo = forecastMonthlyCharges($this->checking, '2025-12-16', 12, -999, 'HBO MAX', $this->leisure);
    forecastCharge($this->checking, '2025-06-10', -32000, 'MAPFRE SEGURO HOGAR POLIZA');
    forecastCharge($this->checking, '2026-06-08', -34500, 'MAPFRE SEGURO HOGAR POLIZA');
});

/**
 * @return array<string, mixed>
 */
function reviewData(User $user): array
{
    return app(ForecastReviewPresenter::class)->present($user, $user->activeSpace()->id);
}

/**
 * The key of the suggestion named like `$name`.
 */
function reviewKey(array $review, string $name, string $step = 'recurring'): string
{
    return collect($review[$step])->firstOrFail(fn (array $suggestion): bool => str_contains(strtolower($suggestion['name']), strtolower($name)))['key'];
}

/**
 * @param  array<string, mixed>  $answers
 */
function postReview(User $user, array $answers)
{
    return test()->actingAs($user)->from(route('forecast.index'))->post(route('forecast.review.store'), [
        'lines' => [],
        'dismissed' => [],
        'manual' => [],
        ...$answers,
    ]);
}

describe('with the Forecast flag off', function () {
    beforeEach(function () {
        Feature::for($this->user)->deactivate(Forecast::class);
    });

    it('does not take answers', function () {
        postReview($this->user, ['manual' => [['name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $this->checking->id, 'frequency' => 'yearly']]])
            ->assertNotFound();

        expect(ForecastItem::query()->count())->toBe(0)
            ->and(ForecastDismissal::query()->count())->toBe(0);
    });

    it('does not count suggestions in the weekly refresh', function () {
        $this->travelTo('2026-11-30 06:10:00');
        $this->user->update(['timezone' => 'UTC']);

        $this->artisan('forecast:refresh')->assertSuccessful();

        expect(Cache::has("forecast:new-suggestions:{$this->user->id}:{$this->spaceId}"))->toBeFalse();
    });
});

describe('the review data', function () {
    it('is only sent when the page asks for it', function () {
        $this->actingAs($this->user)->get(route('forecast.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('review')
                ->missing('newSuggestionCount')
                ->where('reviewPrompt.hasLines', false)
                ->loadDeferredProps(fn (AssertableInertia $reload) => $reload->where('newSuggestionCount', 5))
                ->reloadOnly('review', fn (AssertableInertia $reload) => $reload
                    ->has('review.recurring', 4)
                    ->has('review.annual', 1)
                    ->has('review.accounts', 2)));
    });

    it('describes each suggestion for the review rows', function () {
        $review = reviewData($this->user);
        $salary = collect($review['recurring'])->firstWhere('key', reviewKey($review, 'nomina'));

        expect($salary)->toMatchArray([
            'kind' => 'recurring',
            'accountName' => 'Checking',
            'frequency' => 'monthly',
            'anchorDay' => 28,
            'amount' => 250000,
            'confidence' => 'high',
            'chargeCount' => 12,
            'expectedCount' => 12,
        ]);
    });

    it('shows the charges a yearly suggestion stands on', function () {
        $insurance = reviewData($this->user)['annual'][0];

        expect($insurance['kind'])->toBe('annual')
            ->and($insurance['evidence'])->toBe([
                ['date' => '2025-06-10', 'amount' => -32000],
                ['date' => '2026-06-08', 'amount' => -34500],
            ]);
    });

    it('says how the day-to-day spend is estimated', function () {
        $groceries = forecastExpenseCategory($this->user, name: 'Groceries');
        forecastMonthlyCharges($this->checking, '2026-08-05', 3, -120000, fn (int $index): string => "SUPERMARKET {$index}", $groceries);

        $daily = reviewData($this->user)['dailySpend'];

        expect($daily['currencyCode'])->toBe('EUR')
            ->and($daily['median'])->toBe(['name' => 'Groceries', 'amount' => 120000])
            ->and($daily['unusableBudgets'])->toBe([]);
    });

    it('lists only the accounts the forecast includes', function () {
        Account::factory()->create(['user_id' => User::factory()->create()->id]);

        expect(collect(reviewData($this->user)['accounts'])->pluck('id')->all())
            ->toEqualCanonicalizing([$this->checking->id, $this->savings->id]);
    });
});

describe('confirming', function () {
    it('stores a kept suggestion as a line that is not suggested again', function () {
        $review = reviewData($this->user);

        postReview($this->user, ['lines' => [['keys' => [reviewKey($review, 'alquiler')], 'name' => 'Rent']]])
            ->assertRedirect(route('forecast.index'))
            ->assertSessionHasNoErrors();

        $rent = ForecastItem::query()->sole();

        expect($rent->name)->toBe('Rent')
            ->and($rent->account_id)->toBe($this->checking->id)
            ->and($rent->category_id)->toBe($this->housing->id)
            ->and($rent->kind)->toBe(ForecastItemKind::Recurring)
            ->and($rent->frequency)->toBe(ForecastFrequency::Monthly)
            ->and($rent->source)->toBe(ForecastItemSource::Detected)
            ->and($rent->fingerprint)->toBe('alquiler piso centro')
            ->and($rent->anchor_date->toDateString())->toBe('2025-12-01')
            ->and($rent->versions()->sole()->amount)->toBe(-85000)
            ->and($rent->match_rules_json)->toBe(['and' => [
                ['in' => ['alquiler', ['var' => 'description']]],
                ['in' => ['piso', ['var' => 'description']]],
                ['in' => ['centro', ['var' => 'description']]],
            ]]);

        $remaining = collect(app(ForecastSuggestions::class)->for($this->user, $this->spaceId)['recurring'])->pluck('name');
        expect($remaining)->not->toContain('ALQUILER PISO CENTRO');
    });

    it('pairs the charges the line was built from with its occurrences', function () {
        postReview($this->user, ['lines' => [['keys' => [reviewKey(reviewData($this->user), 'alquiler')]]]]);

        $matches = ForecastMatch::query()->orderBy('occurrence_date')->get();

        expect($matches)->toHaveCount(12)
            ->and($matches->pluck('transaction_id')->all())->toEqualCanonicalizing(collect($this->rent)->pluck('id')->all())
            ->and($matches->first()->occurrence_date->toDateString())->toBe('2025-12-01')
            ->and($matches->last()->occurrence_date->toDateString())->toBe('2026-11-01')
            ->and($matches->every(fn (ForecastMatch $match): bool => $match->matched_by === ForecastMatchedBy::User && $match->deviation === 0))->toBeTrue();
    });

    it('does not count the confirmed charges again in the estimated rest', function () {
        $housingEstimate = fn (): int => collect(app(ForecastPlanBuilder::class)->build($this->user, $this->spaceId, null, 2)->categoryEstimates)
            ->where('categoryId', $this->housing->id)
            ->where('month', '2026-12')
            ->sum('amount');

        expect($housingEstimate())->toBe(85000);

        postReview($this->user, ['lines' => [['keys' => [reviewKey(reviewData($this->user), 'alquiler')]]]]);

        $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->spaceId, null, 2);
        $rentInDecember = collect($plan->input->occurrences)->filter(fn ($occurrence): bool => str_starts_with($occurrence->date, '2026-12'))->sum('amount');

        expect($housingEstimate())->toBe(0)
            ->and($rentInDecember)->toBe(-85000);
    });

    it('recognises the next charge by its description', function () {
        postReview($this->user, ['lines' => [['keys' => [reviewKey(reviewData($this->user), 'alquiler')]]]]);

        $this->travelTo('2026-12-01 10:00:00');
        $december = forecastCharge($this->checking, '2026-12-01', -85000, 'Alquiler piso centro DIC', $this->housing);
        $other = forecastCharge($this->checking, '2026-12-01', -85000, 'TRANSFER TO JOHN');

        expect(app(ForecastMatcher::class)->match($december)?->occurrence_date->toDateString())->toBe('2026-12-01')
            ->and(app(ForecastMatcher::class)->match($other))->toBeNull();
    });

    it('merges several suggestions into one line', function () {
        $review = reviewData($this->user);
        $netflix = reviewKey($review, 'netflix');
        $hbo = reviewKey($review, 'hbo');

        postReview($this->user, ['lines' => [['keys' => [$hbo, $netflix], 'name' => 'Streaming']]])->assertSessionHasNoErrors();

        $line = ForecastItem::query()->sole();

        expect($line->name)->toBe('Streaming')
            ->and($line->fingerprint)->toBe('netflix com')
            ->and($line->versions()->sole()->amount)->toBe(-2298)
            ->and($line->match_rules_json)->toHaveKey('or')
            ->and(ForecastMatch::query()->where('forecast_item_id', $line->id)->count())->toBe(24)
            ->and(ForecastDismissal::query()->sole()->fingerprint)->toBe('hbo max');

        $remaining = collect(app(ForecastSuggestions::class)->for($this->user, $this->spaceId)['recurring'])->pluck('name')->all();
        expect($remaining)->toBe(['NOMINA ACME SL', 'ALQUILER PISO CENTRO']);
    });

    it('matches each payment of a merged line to the same occurrence', function () {
        $review = reviewData($this->user);
        postReview($this->user, ['lines' => [['keys' => [reviewKey($review, 'netflix'), reviewKey($review, 'hbo')], 'name' => 'Streaming']]]);
        $line = ForecastItem::query()->sole();

        $this->travelTo('2026-12-16 10:00:00');
        $netflix = forecastCharge($this->checking, '2026-12-15', -1399, 'NETFLIX COM', $this->leisure);
        $hbo = forecastCharge($this->checking, '2026-12-16', -999, 'HBO MAX', $this->leisure);
        $matcher = app(ForecastMatcher::class);

        expect($matcher->match($netflix)?->forecast_item_id)->toBe($line->id)
            ->and(app(ForecastMatcher::class)->match($hbo)?->forecast_item_id)->toBe($line->id)
            ->and(ForecastMatch::query()->whereIn('transaction_id', [$netflix->id, $hbo->id])->pluck('occurrence_date')->map->toDateString()->unique()->all())->toBe(['2026-12-15']);
    });

    it('refuses to merge suggestions on different days', function () {
        $review = reviewData($this->user);

        postReview($this->user, ['lines' => [['keys' => [reviewKey($review, 'alquiler'), reviewKey($review, 'netflix')]]]])
            ->assertSessionHasErrors('lines');

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('stores the answers once when they are sent twice', function () {
        $answers = ['lines' => [['keys' => [reviewKey(reviewData($this->user), 'alquiler')]]]];

        postReview($this->user, $answers)->assertSessionHasNoErrors();
        postReview($this->user, $answers)->assertSessionHasErrors('lines');

        expect(ForecastItem::query()->count())->toBe(1)
            ->and(ForecastMatch::query()->count())->toBe(12);
    });

    it('refuses to merge suggestions of different directions', function () {
        $review = reviewData($this->user);

        postReview($this->user, ['lines' => [['keys' => [reviewKey($review, 'nomina'), reviewKey($review, 'alquiler')]]]])
            ->assertSessionHasErrors('lines');

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('refuses a suggestion that is not on offer', function () {
        postReview($this->user, ['lines' => [['keys' => ["recurring|{$this->checking->id}|made up"]]]])
            ->assertSessionHasErrors('lines');

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('refuses a suggestion in two lines', function () {
        $key = reviewKey(reviewData($this->user), 'alquiler');

        postReview($this->user, ['lines' => [['keys' => [$key]], ['keys' => [$key]]]])
            ->assertSessionHasErrors('lines.0.keys.0');
    });

    it('dismisses the suggestions left out, which do not come back', function () {
        $review = reviewData($this->user);

        postReview($this->user, [
            'lines' => [['keys' => [reviewKey($review, 'nomina')]]],
            'dismissed' => [reviewKey($review, 'netflix'), reviewKey($review, 'hbo'), reviewKey($review, 'mapfre', 'annual'), 'recurring|x|unknown'],
        ])->assertSessionHasNoErrors();

        expect(ForecastDismissal::query()->orderBy('fingerprint')->get(['fingerprint', 'kind'])->map(fn ($dismissal): array => [$dismissal->fingerprint, $dismissal->kind])->all())->toBe([
            ['hbo max', ForecastSuggestionKind::Recurring],
            ['mapfre seguro hogar poliza', ForecastSuggestionKind::Annual],
            ['netflix com', ForecastSuggestionKind::Recurring],
        ]);

        $remaining = reviewData($this->user);
        expect(collect($remaining['recurring'])->pluck('name')->all())->toBe(['ALQUILER PISO CENTRO'])
            ->and($remaining['annual'])->toBe([]);
    });

    it('adds a yearly suggestion and pairs its charges', function () {
        postReview($this->user, ['lines' => [['keys' => [reviewKey(reviewData($this->user), 'mapfre', 'annual')], 'name' => 'Home insurance']]])
            ->assertSessionHasNoErrors();

        $insurance = ForecastItem::query()->sole();

        expect($insurance->frequency)->toBe(ForecastFrequency::Yearly)
            ->and($insurance->anchor_date->toDateString())->toBe('2025-06-09')
            ->and($insurance->matches()->count())->toBe(2);
    });

    it('adds the yearly and one-off payments typed in', function () {
        $this->travelTo('2026-11-26 12:00:00');

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
        $stranger = Account::factory()->create(['user_id' => User::factory()->create()->id]);

        postReview($this->user, ['manual' => [['name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $stranger->id, 'frequency' => 'yearly']]])
            ->assertSessionHasErrors('manual.0.accountId');

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('lets a user without Pro answer it', function () {
        $free = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
        Feature::for($free)->activate(Forecast::class);
        $account = Account::factory()->create(['user_id' => $free->id, 'currency_code' => 'EUR']);
        forecastMonthlyCharges($account, '2025-12-01', 12, -85000, 'ALQUILER PISO CENTRO');

        postReview($free, [
            'lines' => [['keys' => [reviewKey(reviewData($free), 'alquiler')]]],
            'manual' => [['name' => 'IBI', 'amount' => -48000, 'month' => 4, 'accountId' => $account->id, 'frequency' => 'yearly']],
        ])->assertSessionHasNoErrors();

        expect(ForecastItem::query()->where('user_id', $free->id)->count())->toBe(2);
    });

    it('does not touch another user\'s suggestions', function () {
        $other = User::factory()->onboarded()->paywallSeen()->create();
        Feature::for($other)->activate(Forecast::class);

        postReview($other, ['lines' => [['keys' => [reviewKey(reviewData($this->user), 'alquiler')]]]])
            ->assertSessionHasErrors('lines');

        expect(ForecastItem::query()->count())->toBe(0);
    });

    it('stores a one-sided transfer going where the user says', function () {
        $transfers = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);
        forecastMonthlyCharges($this->checking, '2025-12-03', 12, -20000, 'TRASPASO A BROKER', $transfers);
        $review = reviewData($this->user);
        $transfer = collect($review['recurring'])->firstWhere('kind', 'transfer');

        expect($transfer['destinationUnknown'])->toBeTrue();

        postReview($this->user, ['lines' => [
            ['keys' => [$transfer['key']], 'destinationAccountId' => $this->savings->id],
        ]])->assertSessionHasNoErrors();

        expect(ForecastItem::query()->sole()->destination_account_id)->toBe($this->savings->id);
    });

    it('stores a one-sided transfer as leaving the app by default', function () {
        $transfers = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);
        forecastMonthlyCharges($this->checking, '2025-12-03', 12, -20000, 'TRASPASO A BROKER', $transfers);
        $transfer = collect(reviewData($this->user)['recurring'])->firstWhere('kind', 'transfer');

        postReview($this->user, ['lines' => [['keys' => [$transfer['key']]]]])->assertSessionHasNoErrors();

        $line = ForecastItem::query()->sole();
        expect($line->destination_account_id)->toBeNull()
            ->and($line->destination_external)->toBeTrue();
    });
});

describe('the count of new suggestions', function () {
    it('is forgotten once the review is answered', function () {
        expect(app(ForecastSuggestionCount::class)->for($this->user, $this->spaceId))->toBe(5);

        postReview($this->user, ['lines' => [['keys' => [reviewKey(reviewData($this->user), 'alquiler')]]]]);

        expect(app(ForecastSuggestionCount::class)->for($this->user, $this->spaceId))->toBe(4);
    });

    it('is worked out again in the weekly refresh', function () {
        expect(app(ForecastSuggestionCount::class)->for($this->user, $this->spaceId))->toBe(5);
        Transaction::query()->where('description', 'HBO MAX')->delete();

        $this->user->update(['timezone' => 'UTC']);
        $this->travelTo('2026-11-30 06:10:00');
        $this->artisan('forecast:refresh')->assertSuccessful();

        expect(Cache::get("forecast:new-suggestions:{$this->user->id}:{$this->spaceId}"))->toBe(4);
    });
});
