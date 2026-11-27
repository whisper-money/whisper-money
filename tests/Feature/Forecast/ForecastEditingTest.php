<?php

use App\Enums\AccountType;
use App\Enums\ForecastEstimateKind;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\ForecastItemOverride;
use App\Models\ForecastMatch;
use App\Models\Space;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Editing the forecast: a checking account with 1,000 on 26 Nov 2026, paid
 * 3,000 on the 1st and charged a rent of 1,900 on the 5th of each month,
 * and a savings account with 9,000.
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);

    $this->checking = editingAccount($this->user, 'Checking', AccountType::Checking, 100000);
    $this->savings = editingAccount($this->user, 'Savings', AccountType::Savings, 900000);
    $this->rent = editingLine($this->user, $this->checking, 'Rent', -190000, '2026-01-05');
    $this->from = route('forecast.index', ['account' => $this->checking->id]);
});

function editingAccount(User $user, string $name, AccountType $type, int $balance): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-20', 'balance' => $balance]);

    return $account;
}

function editingLine(User $user, Account $account, string $name, int $amount, string $anchor, ForecastFrequency $frequency = ForecastFrequency::Monthly): ForecastItem
{
    return ForecastItem::factory()->amount($amount)->frequency($frequency)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'name' => $name,
        'anchor_date' => $anchor,
        'amount_tolerance' => 1000,
    ]);
}

/**
 * @return array<string, int> The line's amounts by the day they take effect.
 */
function editingVersions(ForecastItem $item): array
{
    return $item->versions()->get()->mapWithKeys(fn ($version): array => [$version->effective_from->toDateString() => $version->amount])->all();
}

function editingFreeUser(): User
{
    $free = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
    Feature::for($free)->activate(Forecast::class);

    return $free;
}

/**
 * @return array<string, mixed>
 */
function newExpense(Account $account, array $overrides = []): array
{
    return [
        'type' => 'expense',
        'name' => 'Christmas gifts',
        'account_id' => $account->id,
        'amount' => 35000,
        'date' => '2026-12-15',
        'frequency' => 'once',
        ...$overrides,
    ];
}

describe('with the Forecast flag off', function () {
    beforeEach(function () {
        Feature::for($this->user)->deactivate(Forecast::class);
    });

    it('has none of the editing endpoints and changes nothing', function (string $method, Closure $url) {
        $this->actingAs($this->user)->json($method, $url($this))->assertNotFound();

        expect(ForecastItem::query()->count())->toBe(1)
            ->and($this->rent->versions()->count())->toBe(1)
            ->and(ForecastItemOverride::query()->count())->toBe(0)
            ->and(ForecastEstimateAdjustment::query()->count())->toBe(0)
            ->and(ForecastAccountSetting::query()->count())->toBe(0)
            ->and($this->rent->fresh()->archived_at)->toBeNull();
    })->with([
        'add a line' => ['POST', fn ($test) => route('forecast.items.store')],
        'change a line' => ['PATCH', fn ($test) => route('forecast.items.update', $test->rent)],
        'stop forecasting a line' => ['DELETE', fn ($test) => route('forecast.items.destroy', $test->rent)],
        'accept a drift' => ['POST', fn ($test) => route('forecast.items.drift', $test->rent)],
        'adjust an estimate' => ['POST', fn ($test) => route('forecast.estimates.store')],
        'answer a spike' => ['POST', fn ($test) => route('forecast.spikes.store', forecastCharge($test->checking, '2026-11-10', -90000, 'TALLER'))],
        'change an account' => ['PATCH', fn ($test) => route('forecast.accounts.update', $test->checking)],
    ]);
});

describe('adding a line', function () {
    it('stores a one-off expense, recognised by its name, and goes back to the page', function () {
        $this->actingAs($this->user)->from($this->from)
            ->post(route('forecast.items.store'), newExpense($this->checking))
            ->assertRedirect($this->from)
            ->assertSessionHasNoErrors();

        $item = ForecastItem::query()->where('name', 'Christmas gifts')->sole();

        expect($item->user_id)->toBe($this->user->id)
            ->and($item->space_id)->toBe($this->user->activeSpace()->id)
            ->and($item->kind)->toBe(ForecastItemKind::OneOff)
            ->and($item->frequency)->toBe(ForecastFrequency::Once)
            ->and($item->source)->toBe(ForecastItemSource::Manual)
            ->and($item->anchor_date->toDateString())->toBe('2026-12-15')
            ->and($item->match_rules_json)->toBe(['in' => ['christmas gifts', ['var' => 'description']]])
            ->and($item->amount_tolerance)->toBe(1750)
            ->and(editingVersions($item))->toBe(['2026-12-15' => -35000]);
    });

    it('stores an income with a positive amount and an end', function () {
        $this->actingAs($this->user)->post(route('forecast.items.store'), newExpense($this->checking, [
            'type' => 'income', 'name' => 'Rental income', 'frequency' => 'yearly', 'ends_on' => '2027-06-30',
        ]))->assertSessionHasNoErrors();

        $item = ForecastItem::query()->where('name', 'Rental income')->sole();

        expect($item->versions->sole()->amount)->toBe(35000)
            ->and($item->kind)->toBe(ForecastItemKind::Recurring)
            ->and($item->ends_on->toDateString())->toBe('2027-06-30');
    });

    it('stores a transfer to another account, recognised by amount and date alone', function () {
        $this->actingAs($this->user)->post(route('forecast.items.store'), [
            'type' => 'transfer',
            'account_id' => $this->savings->id,
            'destination_account_id' => $this->checking->id,
            'amount' => 50000,
            'date' => '2027-04-01',
            'frequency' => 'once',
        ])->assertSessionHasNoErrors();

        $item = ForecastItem::query()->whereNotNull('destination_account_id')->sole();

        expect($item->account_id)->toBe($this->savings->id)
            ->and($item->destination_account_id)->toBe($this->checking->id)
            ->and($item->destination_external)->toBeFalse()
            ->and($item->match_rules_json)->toBeNull()
            ->and($item->versions->sole()->amount)->toBe(-50000);
    });

    it('stores a transfer out of the app, which counts as spend', function () {
        $this->actingAs($this->user)->post(route('forecast.items.store'), [
            'type' => 'transfer',
            'name' => 'To my partner',
            'account_id' => $this->checking->id,
            'destination_external' => true,
            'amount' => 20000,
            'date' => '2026-12-01',
            'frequency' => 'monthly',
        ])->assertSessionHasNoErrors();

        expect(ForecastItem::query()->where('name', 'To my partner')->sole())
            ->destination_account_id->toBeNull()
            ->destination_external->toBeTrue();
    });

    it('stores a weekly transfer out of the app, planned every 7 days from its date', function () {
        $this->actingAs($this->user)->post(route('forecast.items.store'), [
            'type' => 'transfer',
            'name' => 'Cleaner',
            'account_id' => $this->checking->id,
            'destination_external' => true,
            'amount' => 5000,
            'date' => '2026-12-03',
            'frequency' => 'weekly',
        ])->assertSessionHasNoErrors();

        $item = ForecastItem::query()->where('name', 'Cleaner')->sole();
        $forecast = $this->actingAs($this->user)->get($this->from)->viewData('page')['props']['forecast'];
        $line = collect($forecast['plan']['expenses'])->flatMap(fn (array $group): array => $group['items'])->firstWhere('id', $item->id);

        expect($item->frequency)->toBe(ForecastFrequency::Weekly)
            ->and($item->kind)->toBe(ForecastItemKind::Recurring)
            ->and($line['kind'])->toBe('weekly')
            ->and($line['definition'])->toMatchArray(['frequency' => 'weekly', 'anchorDate' => '2026-12-03'])
            ->and($line['amounts']['2026-12'])->toBe(-25000)
            ->and($line['amounts']['2027-01'])->toBe(-20000);
    });

    it('rejects what it cannot plan', function (Closure $data, string $field) {
        $this->actingAs($this->user)->post(route('forecast.items.store'), $data($this))->assertSessionHasErrors($field);

        expect(ForecastItem::query()->count())->toBe(1);
    })->with([
        'no name' => [fn ($test) => newExpense($test->checking, ['name' => '']), 'name'],
        'no amount' => [fn ($test) => newExpense($test->checking, ['amount' => 0]), 'amount'],
        'a day already gone' => [fn ($test) => newExpense($test->checking, ['date' => '2026-11-25']), 'date'],
        'an unknown repetition' => [fn ($test) => newExpense($test->checking, ['frequency' => 'fortnightly']), 'frequency'],
        'a weekly charge, which its category already estimates' => [fn ($test) => newExpense($test->checking, ['frequency' => 'weekly']), 'frequency'],
        'an end before the start' => [fn ($test) => newExpense($test->checking, ['frequency' => 'yearly', 'ends_on' => '2026-12-01']), 'ends_on'],
        'a monthly charge, which its category already estimates' => [fn ($test) => newExpense($test->checking, ['frequency' => 'monthly']), 'frequency'],
        'a quarterly income' => [fn ($test) => newExpense($test->checking, ['type' => 'income', 'frequency' => 'quarterly']), 'frequency'],
        'another user\'s account' => [fn ($test) => newExpense(editingAccount(User::factory()->create(), 'Theirs', AccountType::Checking, 0)), 'account_id'],
        'an account left out of the forecast' => [function ($test) {
            ForecastAccountSetting::factory()->create(['account_id' => $test->savings->id, 'included' => false]);

            return newExpense($test->savings);
        }, 'account_id'],
        'another user\'s category' => [fn ($test) => newExpense($test->checking, ['category_id' => forecastExpenseCategory(User::factory()->create())->id]), 'category_id'],
        'a transfer to nowhere' => [fn ($test) => ['type' => 'transfer', 'account_id' => $test->checking->id, 'amount' => 100, 'date' => '2026-12-01', 'frequency' => 'once'], 'destination_account_id'],
        'a transfer between two currencies' => [function ($test) {
            $dollars = editingAccount($test->user, 'Dollars', AccountType::Savings, 0);
            $dollars->forceFill(['currency_code' => 'USD'])->save();

            return ['type' => 'transfer', 'account_id' => $test->checking->id, 'destination_account_id' => $dollars->id, 'amount' => 100, 'date' => '2026-12-01', 'frequency' => 'once'];
        }, 'destination_account_id'],
        'a transfer to the same account' => [fn ($test) => ['type' => 'transfer', 'account_id' => $test->checking->id, 'destination_account_id' => $test->checking->id, 'amount' => 100, 'date' => '2026-12-01', 'frequency' => 'once'], 'destination_account_id'],
    ]);

    it('lets a user without Pro plan only the months they see', function () {
        $free = editingFreeUser();
        $account = editingAccount($free, 'Mine', AccountType::Checking, 1000);

        $this->actingAs($free)->post(route('forecast.items.store'), newExpense($account, ['date' => '2027-01-02']))->assertSessionHasErrors('date');
        $this->actingAs($free)->post(route('forecast.items.store'), newExpense($account, ['date' => '2026-12-31']))->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('forecast.items.store'), newExpense($this->checking, ['date' => '2027-09-01']))->assertSessionHasNoErrors();

        expect(ForecastItem::query()->where('user_id', $free->id)->count())->toBe(1);
    });
});

describe('changing a line', function () {
    it('changes only one month with an override', function () {
        $this->actingAs($this->user)->from($this->from)->patch(route('forecast.items.update', $this->rent), [
            'scope' => 'occurrence', 'occurrence_date' => '2027-03-05', 'amount' => 210000,
        ])->assertRedirect($this->from)->assertSessionHasNoErrors();

        expect($this->rent->overrides()->sole())
            ->occurrence_date->toDateString()->toBe('2027-03-05')
            ->amount->toBe(-210000)
            ->moved_to->toBeNull()
            ->and($this->rent->versions()->count())->toBe(1);
    });

    it('moves one occurrence to another day of its month', function () {
        $this->actingAs($this->user)->patch(route('forecast.items.update', $this->rent), [
            'scope' => 'occurrence', 'occurrence_date' => '2027-02-05', 'day' => 31,
        ])->assertSessionHasNoErrors();

        expect($this->rent->overrides()->sole())
            ->moved_to->toDateString()->toBe('2027-02-28')
            ->amount->toBeNull();
    });

    it('changes the amount from a month on with a new version', function () {
        $this->actingAs($this->user)->patch(route('forecast.items.update', $this->rent), [
            'scope' => 'from_here', 'occurrence_date' => '2027-03-05', 'amount' => 200000,
        ])->assertSessionHasNoErrors();

        expect(editingVersions($this->rent))->toBe(['2026-01-05' => -190000, '2027-03-05' => -200000])
            ->and(ForecastItem::query()->count())->toBe(1);
    });

    it('replaces the later versions from a month on', function () {
        $this->rent->versions()->create(['effective_from' => '2027-06-05', 'amount' => -230000]);

        $this->actingAs($this->user)->patch(route('forecast.items.update', $this->rent), [
            'scope' => 'from_here', 'occurrence_date' => '2027-03-05', 'amount' => 200000,
        ])->assertSessionHasNoErrors();

        expect(editingVersions($this->rent))->toBe(['2026-01-05' => -190000, '2027-03-05' => -200000]);
    });

    it('keeps the sign of the version in force on the occurrence', function () {
        $refund = editingLine($this->user, $this->checking, 'Refund', 5000, '2026-12-10');
        $refund->versions()->create(['effective_from' => '2027-06-10', 'amount' => -1000]);

        $this->actingAs($this->user)->patch(route('forecast.items.update', $refund), [
            'scope' => 'occurrence', 'occurrence_date' => '2027-01-10', 'amount' => 6000,
        ])->assertSessionHasNoErrors();

        expect($refund->overrides()->sole()->amount)->toBe(6000);
    });

    it('keeps the schedule a payment was matched to and carries on as a new line', function () {
        $insurance = editingLine($this->user, $this->checking, 'Insurance', -60000, '2026-04-10', ForecastFrequency::Yearly);
        forecastCharge($this->checking, '2026-04-10', -60000, 'INSURANCE');

        $this->actingAs($this->user)->patch(route('forecast.items.update', $insurance), [
            'scope' => 'from_here', 'occurrence_date' => '2027-04-10', 'day' => 20,
        ])->assertSessionHasNoErrors();

        expect($insurance->fresh())
            ->anchor_date->toDateString()->toBe('2026-04-10')
            ->ends_on->toDateString()->toBe('2027-04-09')
            ->and(ForecastItem::query()->where('name', 'Insurance')->whereKeyNot($insurance->id)->sole()->anchor_date->toDateString())->toBe('2027-04-20');
    });

    it('reschedules in place when nothing of the line was left before', function () {
        $insurance = editingLine($this->user, $this->checking, 'Insurance', -60000, '2027-04-10', ForecastFrequency::Yearly);

        $this->actingAs($this->user)->patch(route('forecast.items.update', $insurance), [
            'scope' => 'from_here', 'occurrence_date' => '2027-04-10', 'frequency' => 'quarterly', 'day' => 20,
        ])->assertSessionHasNoErrors();

        expect($insurance->fresh())
            ->anchor_date->toDateString()->toBe('2027-04-20')
            ->frequency->toBe(ForecastFrequency::Quarterly)
            ->and(ForecastItem::query()->count())->toBe(2);
    });

    it('ends the line and carries on as a new one when months before keep the old schedule', function () {
        $this->actingAs($this->user)->patch(route('forecast.items.update', $this->rent), [
            'scope' => 'from_here', 'occurrence_date' => '2027-03-05', 'day' => 1, 'amount' => 200000,
        ])->assertSessionHasNoErrors();

        $successor = ForecastItem::query()->whereKeyNot($this->rent->id)->sole();

        expect($this->rent->fresh()->ends_on->toDateString())->toBe('2027-03-04')
            ->and($successor->anchor_date->toDateString())->toBe('2027-03-01')
            ->and($successor->name)->toBe('Rent')
            ->and(editingVersions($successor))->toBe(['2027-03-01' => -200000]);
    });

    it('refuses months that can no longer change', function (Closure $setup, array $data, string $field) {
        $setup($this);

        $this->actingAs($this->user)->patch(route('forecast.items.update', $this->rent), ['scope' => 'occurrence', 'amount' => 1, ...$data])
            ->assertSessionHasErrors($field);

        expect(ForecastItemOverride::query()->count())->toBe(0);
    })->with([
        'a closed month' => [fn () => null, ['occurrence_date' => '2026-10-05'], 'occurrence_date'],
        'a date the schedule does not hold' => [fn () => null, ['occurrence_date' => '2027-03-06'], 'occurrence_date'],
        'this month, already paid' => [function ($test) {
            forecastCharge($test->checking, '2026-11-05', -190000, 'RENT');

            expect(ForecastMatch::query()->sole()->occurrence_date->toDateString())->toBe('2026-11-05');
        }, ['occurrence_date' => '2026-11-05'], 'occurrence_date'],
        'a day already gone' => [fn () => null, ['occurrence_date' => '2026-11-05', 'day' => 1], 'day'],
    ]);

    it('lets the month in progress change while it is still to come', function () {
        $this->actingAs($this->user)->patch(route('forecast.items.update', $this->rent), [
            'scope' => 'occurrence', 'occurrence_date' => '2026-11-05', 'amount' => 195000,
        ])->assertSessionHasNoErrors();

        expect($this->rent->overrides()->sole()->amount)->toBe(-195000);
    });

    it('lets a user without Pro change only the months they see', function () {
        $free = editingFreeUser();
        $line = editingLine($free, editingAccount($free, 'Mine', AccountType::Checking, 1000), 'Gym', -4000, '2026-01-10');

        $this->actingAs($free)->patch(route('forecast.items.update', $line), ['scope' => 'from_here', 'occurrence_date' => '2027-01-10', 'amount' => 5000])
            ->assertSessionHasErrors('occurrence_date');
        $this->actingAs($free)->patch(route('forecast.items.update', $line), ['scope' => 'from_here', 'occurrence_date' => '2026-12-10', 'amount' => 5000])
            ->assertSessionHasNoErrors();

        expect(editingVersions($line))->toBe(['2026-01-10' => -4000, '2026-12-10' => -5000]);
    });

    it('never reaches another user\'s line, or one in another space', function () {
        $theirs = editingLine($other = User::factory()->create(), editingAccount($other, 'Theirs', AccountType::Checking, 0), 'Theirs', -100, '2026-01-05');
        $elsewhere = editingLine($this->user, $this->checking, 'Elsewhere', -100, '2026-01-05');
        $elsewhere->forceFill(['space_id' => Space::factory()->create()->id])->save();

        foreach ([$theirs, $elsewhere] as $line) {
            $this->actingAs($this->user)->patch(route('forecast.items.update', $line), ['scope' => 'occurrence', 'occurrence_date' => '2027-01-05', 'amount' => 1])->assertNotFound();
            $this->actingAs($this->user)->delete(route('forecast.items.destroy', $line))->assertNotFound();
        }

        expect(ForecastItemOverride::query()->count())->toBe(0)
            ->and(ForecastItem::query()->whereNotNull('archived_at')->count())->toBe(0);
    });
});

it('stops forecasting a line by archiving it', function () {
    $this->actingAs($this->user)->from($this->from)->delete(route('forecast.items.destroy', $this->rent))->assertRedirect($this->from);

    expect($this->rent->fresh()->archived_at)->not->toBeNull();

    $this->actingAs($this->user)->delete(route('forecast.items.destroy', $this->rent))->assertNotFound();
});

describe('accepting a drift', function () {
    it('updates the line from the first occurrence after its last payment', function () {
        foreach (['2026-10-05' => -200000, '2026-11-05' => -201000] as $date => $paid) {
            ForecastMatch::factory()->create([
                'forecast_item_id' => $this->rent->id,
                'occurrence_date' => $date,
                'transaction_id' => forecastCharge($this->checking, $date, $paid, 'RENT')->id,
                'deviation' => $paid + 190000,
            ]);
        }

        $this->actingAs($this->user)->post(route('forecast.items.drift', $this->rent))->assertSessionHasNoErrors();

        expect(editingVersions($this->rent))->toBe(['2026-01-05' => -190000, '2026-12-05' => -201000]);
    });

    it('says there is nothing to update without a drift', function () {
        $this->actingAs($this->user)->post(route('forecast.items.drift', $this->rent))->assertSessionHasErrors('drift');

        expect($this->rent->versions()->count())->toBe(1);
    });
});

describe('adjusting an estimate', function () {
    it('sets the estimated rest for one month only, without touching budgets', function () {
        $food = forecastExpenseCategory($this->user, name: 'Food');
        $budgets = Budget::query()->count();

        $this->actingAs($this->user)->from($this->from)->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => $food->id, 'kind' => 'expense', 'month' => '2027-02', 'amount' => 45000, 'scope' => 'month',
        ])->assertRedirect($this->from)->assertSessionHasNoErrors();

        expect(ForecastEstimateAdjustment::query()->sole())
            ->starts_on->toDateString()->toBe('2027-02-01')
            ->ends_on->toDateString()->toBe('2027-02-28')
            ->amount->toBe(45000)
            ->user_id->toBe($this->user->id)
            ->and(Budget::query()->count())->toBe($budgets);
    });

    it('replaces the adjustments that start later when set from a month on', function () {
        $data = ['account_id' => $this->checking->id, 'category_id' => null, 'kind' => 'expense', 'amount' => 30000];

        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [...$data, 'month' => '2027-03', 'scope' => 'month']);
        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [...$data, 'month' => '2026-12', 'scope' => 'from_here', 'amount' => 20000]);

        expect(ForecastEstimateAdjustment::query()->sole())
            ->starts_on->toDateString()->toBe('2026-12-01')
            ->amount->toBe(20000);
    });

    it('sets it from a month on, replacing an earlier figure for that month', function () {
        $data = ['account_id' => $this->checking->id, 'category_id' => null, 'kind' => 'expense', 'month' => '2026-12', 'amount' => 30000, 'scope' => 'from_here'];

        $this->actingAs($this->user)->post(route('forecast.estimates.store'), $data)->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [...$data, 'amount' => 25000])->assertSessionHasNoErrors();

        expect(ForecastEstimateAdjustment::query()->sole())
            ->ends_on->toBeNull()
            ->amount->toBe(25000);
    });

    it('reads in the forecast as set by the user', function () {
        $food = forecastExpenseCategory($this->user, name: 'Food');
        forecastCharge($this->checking, '2026-10-12', -40000, 'SUPER', $food);

        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => $food->id, 'kind' => 'expense', 'month' => '2026-11', 'amount' => 45000, 'scope' => 'from_here',
        ]);

        $group = collect($this->actingAs($this->user)->get($this->from)->viewData('page')['props']['forecast']['plan']['expenses'])->firstWhere('categoryId', $food->id);

        expect($group['estimate']['basis'])->toBe('adjustment')
            ->and($group['estimate']['amounts']['2027-01'])->toBe(-45000);
    });

    it('says the category was set by the user once a later month is, though the month in progress is not', function () {
        $food = forecastExpenseCategory($this->user, name: 'Food');
        forecastCharge($this->checking, '2026-10-12', -40000, 'SUPER', $food);

        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => $food->id, 'month' => '2027-01', 'amount' => 45000, 'scope' => 'from_here',
        ])->assertSessionHasNoErrors();

        $group = collect($this->actingAs($this->user)->get($this->from)->viewData('page')['props']['forecast']['plan']['expenses'])->firstWhere('categoryId', $food->id);

        expect($group['estimate']['basis'])->toBe('adjustment')
            ->and($group['estimate']['amounts']['2026-12'])->toBe(-40000)
            ->and($group['estimate']['amounts']['2027-01'])->toBe(-45000);
    });

    it('rejects what it cannot adjust', function (Closure $data, string $field) {
        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'kind' => 'expense', 'month' => '2026-12', 'amount' => 100, 'scope' => 'month', ...$data($this),
        ])->assertSessionHasErrors($field);

        expect(ForecastEstimateAdjustment::query()->count())->toBe(0);
    })->with([
        'a closed month' => [fn () => ['month' => '2026-10'], 'month'],
        'a subcategory' => [fn ($test) => ['category_id' => forecastExpenseCategory($test->user, forecastExpenseCategory($test->user))->id], 'category_id'],
        'another user\'s account' => [fn () => ['account_id' => editingAccount(User::factory()->create(), 'Theirs', AccountType::Checking, 0)->id], 'account_id'],
        'an unknown scope' => [fn () => ['scope' => 'forever'], 'scope'],
        'no side for "Other"' => [fn () => ['kind' => null], 'kind'],
        'an unknown side' => [fn () => ['kind' => 'transfer'], 'kind'],
        'an income category on the spend side' => [fn ($test) => ['category_id' => forecastIncomeCategory($test->user)->id], 'kind'],
        'a spend category on the income side' => [fn ($test) => ['category_id' => forecastExpenseCategory($test->user)->id, 'kind' => 'income'], 'kind'],
    ]);

    it('takes the side of the category when none is sent, as a page loaded before the change does', function () {
        $salary = forecastIncomeCategory($this->user);

        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => $salary->id, 'month' => '2026-12', 'amount' => 250000, 'scope' => 'from_here',
        ])->assertSessionHasNoErrors();

        expect(ForecastEstimateAdjustment::query()->sole()->kind)->toBe(ForecastEstimateKind::Income);
    });

    it('keeps "Other income" and "Other spending" apart, though neither has a category', function () {
        $data = ['account_id' => $this->checking->id, 'category_id' => null, 'month' => '2026-12', 'scope' => 'from_here'];

        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [...$data, 'kind' => 'expense', 'amount' => 30000])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [...$data, 'kind' => 'income', 'amount' => 50000])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [...$data, 'kind' => 'income', 'amount' => 60000])->assertSessionHasNoErrors();

        expect(ForecastEstimateAdjustment::query()->orderBy('amount')->get()->map(fn (ForecastEstimateAdjustment $adjustment): array => [$adjustment->kind->value, $adjustment->amount])->all())
            ->toBe([['expense', 30000], ['income', 60000]]);
    });

    it('reads an "Other income" adjustment in the forecast as money in', function () {
        $this->actingAs($this->user)->post(route('forecast.estimates.store'), [
            'account_id' => $this->checking->id, 'category_id' => null, 'kind' => 'income', 'month' => '2026-12', 'amount' => 50000, 'scope' => 'from_here',
        ])->assertSessionHasNoErrors();

        $plan = $this->actingAs($this->user)->get($this->from)->viewData('page')['props']['forecast']['plan'];
        $otherIncome = collect($plan['income'])->firstWhere('key', 'other');

        expect($otherIncome['estimate'])->toMatchArray(['kind' => 'income', 'basis' => 'adjustment'])
            ->and($otherIncome['estimate']['amounts']['2027-01'])->toBe(50000)
            ->and(data_get(collect($plan['expenses'])->firstWhere('key', 'other'), 'estimate.basis'))->not->toBe('adjustment');
    });

    it('lets a user without Pro adjust only the months they see', function () {
        $free = editingFreeUser();
        $account = editingAccount($free, 'Mine', AccountType::Checking, 1000);
        $data = ['account_id' => $account->id, 'kind' => 'expense', 'amount' => 100, 'scope' => 'from_here'];

        $this->actingAs($free)->post(route('forecast.estimates.store'), [...$data, 'month' => '2027-01'])->assertSessionHasErrors('month');
        $this->actingAs($free)->post(route('forecast.estimates.store'), [...$data, 'month' => '2026-12'])->assertSessionHasNoErrors();
    });
});

describe('answering a spike', function () {
    beforeEach(function () {
        $this->spike = forecastCharge($this->checking, '2026-11-10', -90000, 'TALLER MECANICO');
    });

    it('leaves a one-off out of the estimate', function () {
        $this->actingAs($this->user)->post(route('forecast.spikes.store', $this->spike), ['answer' => 'one_off'])->assertSessionHasNoErrors();

        expect(ForecastExcludedTransaction::query()->sole()->transaction_id)->toBe($this->spike->id)
            ->and(ForecastItem::query()->count())->toBe(1);
    });

    it('turns one that repeats into a yearly line', function () {
        $this->actingAs($this->user)->post(route('forecast.spikes.store', $this->spike), ['answer' => 'repeats'])->assertSessionHasNoErrors();

        $line = ForecastItem::query()->whereKeyNot($this->rent->id)->sole();

        expect($line->frequency)->toBe(ForecastFrequency::Yearly)
            ->and($line->account_id)->toBe($this->checking->id)
            ->and($line->source)->toBe(ForecastItemSource::Detected)
            ->and($line->anchor_date->toDateString())->toBe('2027-11-10')
            ->and($line->versions->sole()->amount)->toBe(-90000);
    });

    it('does not add the same yearly line twice', function () {
        $this->actingAs($this->user)->post(route('forecast.spikes.store', $this->spike), ['answer' => 'repeats']);
        $this->actingAs($this->user)->post(route('forecast.spikes.store', $this->spike), ['answer' => 'one_off']);
        $this->actingAs($this->user)->post(route('forecast.spikes.store', $this->spike), ['answer' => 'repeats'])->assertSessionHasNoErrors();

        expect(ForecastItem::query()->whereKeyNot($this->rent->id)->count())->toBe(1);
    });

    it('leaves a user without Pro to plan only the months they see', function () {
        $free = editingFreeUser();
        $spike = forecastCharge(editingAccount($free, 'Mine', AccountType::Checking, 1000), '2026-11-10', -90000, 'TALLER');

        $this->actingAs($free)->post(route('forecast.spikes.store', $spike), ['answer' => 'repeats'])->assertSessionHasErrors('answer');

        expect(ForecastItem::query()->where('user_id', $free->id)->count())->toBe(0);
    });

    it('rejects an unknown answer and another user\'s transaction', function () {
        $this->actingAs($this->user)->post(route('forecast.spikes.store', $this->spike), ['answer' => 'maybe'])->assertSessionHasErrors('answer');

        $theirs = forecastCharge(editingAccount(User::factory()->create(), 'Theirs', AccountType::Checking, 0), '2026-11-10', -90000, 'TALLER');
        $this->actingAs($this->user)->post(route('forecast.spikes.store', $theirs), ['answer' => 'one_off'])->assertNotFound();

        expect(ForecastExcludedTransaction::query()->count())->toBe(0);
    });
});

describe('the account settings', function () {
    it('stores the wanted minimum and the yearly rate', function () {
        $this->actingAs($this->user)->from($this->from)->patch(route('forecast.accounts.update', $this->checking), [
            'minimum_balance' => 30000, 'annual_rate' => 4.5,
        ])->assertRedirect($this->from)->assertSessionHasNoErrors();

        expect(ForecastAccountSetting::query()->sole())
            ->account_id->toBe($this->checking->id)
            ->minimum_balance->toBe(30000)
            ->annual_rate_bps->toBe(450)
            ->included->toBeTrue();

        $this->actingAs($this->user)->patch(route('forecast.accounts.update', $this->checking), ['minimum_balance' => null]);

        expect(ForecastAccountSetting::query()->sole())->minimum_balance->toBeNull()->annual_rate_bps->toBe(450);
    });

    it('leaves an account out and lands on the forecast\'s default account', function () {
        $this->actingAs($this->user)->from($this->from)->patch(route('forecast.accounts.update', $this->checking), ['included' => false])
            ->assertRedirect(route('forecast.index'));

        expect(ForecastAccountSetting::query()->sole()->included)->toBeFalse();
    });

    it('never reaches another user\'s account', function () {
        $theirs = editingAccount(User::factory()->create(), 'Theirs', AccountType::Checking, 0);

        $this->actingAs($this->user)->patch(route('forecast.accounts.update', $theirs), ['minimum_balance' => 1])->assertNotFound();

        expect(ForecastAccountSetting::query()->count())->toBe(0);
    });

    it('rejects a rate out of range', function () {
        $this->actingAs($this->user)->patch(route('forecast.accounts.update', $this->checking), ['annual_rate' => 250])->assertSessionHasErrors('annual_rate');
    });
});

describe('what the page gives the edit sheets', function () {
    it('describes each line for the client engine and its sheet', function () {
        $this->rent->forceFill(['match_rules_json' => ['in' => ['rent', ['var' => 'description']]]])->save();
        forecastCharge($this->checking, '2026-11-05', -191000, 'RENT NOVEMBER');

        $forecast = $this->actingAs($this->user)->get($this->from)->viewData('page')['props']['forecast'];
        $line = collect($forecast['plan']['expenses'])->flatMap(fn (array $group): array => $group['items'])->firstWhere('id', $this->rent->id);

        expect($line['definition'])->toMatchArray(['id' => $this->rent->id, 'accountId' => $this->checking->id, 'frequency' => 'monthly', 'anchorDate' => '2026-01-05'])
            ->and($line['definition']['versions'])->toBe([['effectiveFrom' => '2026-01-05', 'amount' => -190000]])
            ->and($line['occurrenceDates']['2026-12'])->toBe('2026-12-05')
            ->and($line['matchText'])->toBe('rent')
            ->and($line['amountTolerance'])->toBe(1000)
            ->and($line['payments'])->toBe([['occurrenceDate' => '2026-11-05', 'planned' => -190000, 'real' => -191000]])
            ->and($line)->not->toHaveKey('drift')
            ->and($forecast['account']['annualRateBps'])->toBeNull();
    });

    it('sends a user without Pro nothing of the line past the months they see', function () {
        $free = editingFreeUser();
        $account = editingAccount($free, 'Mine', AccountType::Checking, 1000);
        $line = editingLine($free, $account, 'Gym', -4000, '2026-01-10');
        $line->versions()->create(['effective_from' => '2027-05-10', 'amount' => -6000]);
        $line->overrides()->create(['occurrence_date' => '2027-03-10', 'amount' => -1]);

        $forecast = $this->actingAs($free)->get(route('forecast.index'))->viewData('page')['props']['forecast'];
        $sent = collect($forecast['plan']['expenses'])->flatMap(fn (array $group): array => $group['items'])->sole();

        expect($sent['definition']['versions'])->toBe([['effectiveFrom' => '2026-01-10', 'amount' => -4000]])
            ->and($sent['definition']['overrides'])->toBe([])
            ->and(array_keys($sent['occurrenceDates']))->toBe(['2026-11', '2026-12']);
    });

    it('offers the transfer that lifts the lowest point, from the account with room to spare', function () {
        editingLine($this->user, $this->checking, 'Salary', 300000, '2026-01-01');
        editingLine($this->user, $this->checking, 'Car insurance', -705000, '2027-04-03', ForecastFrequency::Yearly);

        $forecast = $this->actingAs($this->user)->get($this->from)->viewData('page')['props']['forecast'];

        expect($forecast['alert']['level'])->toBe('strong')
            ->and($forecast['alert']['month'])->toBe('2027-04')
            ->and($forecast['alert']['min'])->toBe(-55000)
            ->and($forecast['alert']['transfer'])->toBe([
                'fromAccountId' => $this->savings->id,
                'amount' => 60000,
                'date' => '2027-04-01',
                'month' => '2027-04',
            ]);
    });

    it('offers no transfer when no other account can lend it', function () {
        editingLine($this->user, $this->checking, 'Salary', 300000, '2026-01-01');
        editingLine($this->user, $this->checking, 'Car insurance', -2000000, '2027-04-03', ForecastFrequency::Yearly);

        $forecast = $this->actingAs($this->user)->get($this->from)->viewData('page')['props']['forecast'];

        expect($forecast['alert']['level'])->toBe('strong')
            ->and($forecast['alert']['transfer'])->toBeNull();
    });

    it('projects every included account for the transfer sheet, the selected one first', function () {
        $input = $this->actingAs($this->user)->get(route('forecast.index', ['account' => $this->savings->id]))->viewData('page')['props']['projectionInput'];

        expect(array_column($input['accounts'], 'id'))->toBe([$this->savings->id, $this->checking->id]);
    });
});
