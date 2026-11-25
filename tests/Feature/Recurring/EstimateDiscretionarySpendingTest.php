<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\EstimateDiscretionarySpending;
use App\Services\TransactionSplitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->estimate = app(EstimateDiscretionarySpending::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Checking,
    ]);
    $this->groceries = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
});

/** The first day of a complete month this many months back. */
function completeMonth(int $back): CarbonImmutable
{
    return CarbonImmutable::today()->startOfMonth()->subMonthsNoOverflow($back);
}

/** One outflow inside a complete month. */
function spend(User $user, Account $account, int $monthsBack, int $amount, ?Category $category = null): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'transaction_date' => completeMonth($monthsBack)->addDays(9)->toDateString(),
        'amount' => $amount,
        'currency_code' => 'EUR',
    ]);
}

function estimateFor(User $user, Account $account): ?array
{
    return app(EstimateDiscretionarySpending::class)
        ->forAccounts(collect([$account->id]), 'EUR', CarbonImmutable::today());
}

it('takes the median month rather than the average', function () {
    // One blowout month must not set the pace for the other eleven.
    spend($this->user, $this->account, 1, -50000, $this->groceries);
    spend($this->user, $this->account, 2, -60000, $this->groceries);
    spend($this->user, $this->account, 3, -900000, $this->groceries);

    expect(estimateFor($this->user, $this->account))
        ->toBe(['monthly' => -60000, 'months_observed' => 3]);
});

it('says nothing when the ledger is too short to have an opinion', function () {
    spend($this->user, $this->account, 1, -50000, $this->groceries);
    spend($this->user, $this->account, 2, -60000, $this->groceries);

    expect(estimateFor($this->user, $this->account))->toBeNull();
});

it('leaves out the month in progress', function () {
    foreach ([1, 2, 3] as $back) {
        spend($this->user, $this->account, $back, -50000, $this->groceries);
    }

    // A big spend today would drag a mean down and must not appear at all.
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'transaction_date' => CarbonImmutable::today()->toDateString(),
        'amount' => -400000,
        'currency_code' => 'EUR',
    ]);

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-50000);
});

it('excludes what the projection already walks', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    foreach ([1, 2, 3] as $back) {
        spend($this->user, $this->account, $back, -20000, $this->groceries);
        $subscription = spend($this->user, $this->account, $back, -1299, $this->groceries);
        $series->transactions()->attach($subscription->id);
    }

    // Only the groceries, never the subscription counted twice.
    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-20000);
});

it('leaves out the parts of a charge the projection walks, and counts the parts of any other split', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);
    $splitter = app(TransactionSplitter::class);

    foreach ([1, 2, 3] as $back) {
        $groceries = spend($this->user, $this->account, $back, -20000, $this->groceries);
        $subscription = spend($this->user, $this->account, $back, -1299, $this->groceries);
        $series->transactions()->attach($subscription->id);

        // Split after detection linked it: the series holds the original.
        $splitter->split($subscription, [['amount' => -1000, 'category_id' => $this->groceries->id], ['amount' => -299]]);
        $splitter->split($groceries, [['amount' => -15000, 'category_id' => $this->groceries->id], ['amount' => -5000]]);
    }

    // The groceries in full, the subscription never counted a second time.
    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-20000);
});

/**
 * Three months of charges claimed by one series, for the caller to then stop
 * the forecast from walking.
 */
function seriesClaimingThreeMonths(User $user, Account $account, Category $category): RecurringSeries
{
    $series = RecurringSeries::factory()->create([
        'user_id' => $user->id,
        'currency_code' => 'EUR',
    ]);

    foreach ([1, 2, 3] as $back) {
        $series->transactions()->attach(spend($user, $account, $back, -80000, $category)->id);
    }

    return $series;
}

it('counts spending the user dismissed from the recurring list', function () {
    // Absent from the recurring charges because the series is dismissed, absent
    // from the estimate because the series once claimed it: the money vanished
    // from both sides at once, and the projection spent none of it.
    seriesClaimingThreeMonths($this->user, $this->account, $this->groceries)
        ->update(['user_state' => RecurringSeriesUserState::Ignored]);

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-80000);
});

it('counts spending whose series the user deleted', function () {
    // A deleted series keeps its pivot rows: the detection run rewrites the
    // pivot for every series except the deleted ones, deliberately.
    seriesClaimingThreeMonths($this->user, $this->account, $this->groceries)->delete();

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-80000);
});

it('counts spending whose series has lapsed', function () {
    seriesClaimingThreeMonths($this->user, $this->account, $this->groceries)
        ->update(['status' => RecurringSeriesStatus::Lapsed]);

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-80000);
});

it('counts a charge billed in a currency the forecast skips', function () {
    // The forecast only walks series in the user's own currency, so this one
    // is nobody else's to count.
    seriesClaimingThreeMonths($this->user, $this->account, $this->groceries)
        ->update(['currency_code' => 'USD']);

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-80000);
});

it('ignores money moved rather than spent', function () {
    $savings = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Savings,
    ]);
    $transfer = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
    ]);

    foreach ([1, 2, 3] as $back) {
        spend($this->user, $this->account, $back, -30000, $this->groceries);
        spend($this->user, $this->account, $back, -500000, $savings);
        spend($this->user, $this->account, $back, -200000, $transfer);
    }

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-30000);
});

it('counts an uncategorised outflow', function () {
    foreach ([1, 2, 3] as $back) {
        spend($this->user, $this->account, $back, -7500);
    }

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-7500);
});

it('counts a split by its lines, not its parent', function () {
    $savings = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Savings,
    ]);

    foreach ([1, 2, 3] as $back) {
        $shop = spend($this->user, $this->account, $back, -50000);
        $shop->splits()->createMany([
            ['category_id' => $this->groceries->id, 'amount' => -12000, 'position' => 0],
            ['category_id' => $savings->id, 'amount' => -38000, 'position' => 1],
        ]);
    }

    // The transfer half of the shop is not spending.
    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-12000);
});

it('leaves income alone', function () {
    $salary = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);

    foreach ([1, 2, 3] as $back) {
        spend($this->user, $this->account, $back, -40000, $this->groceries);
        spend($this->user, $this->account, $back, 265000, $salary);
    }

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-40000);
});

it('only looks at the accounts it was given', function () {
    $other = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Checking,
    ]);

    foreach ([1, 2, 3] as $back) {
        spend($this->user, $this->account, $back, -10000, $this->groceries);
        spend($this->user, $other, $back, -90000, $this->groceries);
    }

    expect(estimateFor($this->user, $this->account)['monthly'])->toBe(-10000);
});

it('has nothing to say without accounts', function () {
    expect($this->estimate->forAccounts(collect(), 'EUR', CarbonImmutable::today()))->toBeNull();
});
