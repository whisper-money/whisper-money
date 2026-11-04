<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Budget snapshots hold what a transaction costs the budget's owner in their
 * own currency, converted the way the cashflow converts it. 1 EUR buys 2 USD
 * on the rated day, so a 100 USD expense weighs 50 EUR.
 */

beforeEach(function () {
    // No rate is ever fetched: the provider answers empty, as on a missing day.
    Http::fake();
    Mail::fake();

    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $this->service = app(BudgetTransactionService::class);

    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-10-10', 'rates' => ['usd' => 2.0]]);
});

function currencyOctoberPeriod(Budget $budget): BudgetPeriod
{
    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'allocated_amount' => 100000,
    ]);
}

function currencyTrackedPeriod(object $test): BudgetPeriod
{
    return currencyOctoberPeriod(Budget::factory()->monthly()->forCategories($test->category)->create(['user_id' => $test->user->id]));
}

function currencyCatchAllPeriod(object $test): BudgetPeriod
{
    return currencyOctoberPeriod(Budget::factory()->monthly()->catchAll()->create(['user_id' => $test->user->id]));
}

function foreignCurrencyExpense(object $test, int $amount, string $currency, string $date = '2026-10-10', ?Category $category = null): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'category_id' => ($category ?? $test->category)->id,
        'currency_code' => $currency,
        'amount' => $amount,
        'transaction_date' => $date,
    ]);
}

function budgetSnapshotOf(Transaction $transaction, BudgetPeriod $period): ?int
{
    return BudgetTransaction::query()
        ->where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->value('amount');
}

/**
 * The row the bug wrote: the transaction amount as booked, whatever its currency.
 */
function storeUnconvertedSnapshot(Transaction $transaction, BudgetPeriod $period): void
{
    BudgetTransaction::query()->updateOrCreate(
        ['budget_period_id' => $period->id, 'transaction_id' => $transaction->id],
        ['amount' => -$transaction->amount],
    );
}

test('a live assignment stores the owner share converted to the user currency', function (string $budgetKind) {
    $this->account->update(['ownership_percentage' => 50]);
    $period = $budgetKind === 'tracked' ? currencyTrackedPeriod($this) : currencyCatchAllPeriod($this);
    $transaction = foreignCurrencyExpense($this, -10000, 'USD');

    $this->service->assignTransaction($transaction);

    expect(budgetSnapshotOf($transaction, $period))->toBe(2500);
})->with(['tracked', 'catch-all']);

test('the historical backfill stores the converted amount', function () {
    $period = currencyTrackedPeriod($this);
    $usd = foreignCurrencyExpense($this, -10000, 'USD');
    $eur = foreignCurrencyExpense($this, -3000, 'EUR');

    $this->service->assignHistoricalTransactionsToPeriod($period);

    expect(budgetSnapshotOf($usd, $period))->toBe(5000)
        ->and(budgetSnapshotOf($eur, $period))->toBe(3000);
});

test('rebuilding a catch-all period stores the converted amount, fixing one stored unconverted', function () {
    $period = currencyCatchAllPeriod($this);
    $absorbed = foreignCurrencyExpense($this, -10000, 'USD');
    $stale = foreignCurrencyExpense($this, -6000, 'USD');
    storeUnconvertedSnapshot($stale, $period);

    $this->service->rebuildCatchAllPeriod($period->load('budget'));

    expect(budgetSnapshotOf($absorbed, $period))->toBe(5000)
        ->and(budgetSnapshotOf($stale, $period))->toBe(3000);
});

test('a day with no rate keeps the amount unconverted, as the cashflow does', function () {
    $period = currencyTrackedPeriod($this);
    $transaction = foreignCurrencyExpense($this, -10000, 'USD', date: '2026-10-12');

    $this->service->assignTransaction($transaction);

    expect(budgetSnapshotOf($transaction, $period))->toBe(10000);
});

test('changing the account share re-weighs foreign and same-currency rows alike', function () {
    $period = currencyTrackedPeriod($this);
    $usd = foreignCurrencyExpense($this, -10000, 'USD');
    $eur = foreignCurrencyExpense($this, -3333, 'EUR');
    $this->service->assignHistoricalTransactionsToPeriod($period);

    $this->account->update(['ownership_percentage' => 33]);

    expect(budgetSnapshotOf($usd, $period))->toBe(1650)
        ->and(budgetSnapshotOf($eur, $period))->toBe(1100);

    $this->account->update(['ownership_percentage' => 100]);

    expect(budgetSnapshotOf($usd, $period))->toBe(5000)
        ->and(budgetSnapshotOf($eur, $period))->toBe(3333);
});

test('resnapshot rewrites only the rows whose amount is off and reports how many changed', function () {
    $period = currencyTrackedPeriod($this);
    $usd = foreignCurrencyExpense($this, -10000, 'USD');
    $eur = foreignCurrencyExpense($this, -3000, 'EUR');
    storeUnconvertedSnapshot($usd, $period);
    storeUnconvertedSnapshot($eur, $period);

    $changed = $this->service->resnapshot(BudgetTransaction::query()->where('budget_period_id', $period->id));

    expect($changed)->toBe(1)
        ->and(budgetSnapshotOf($usd, $period))->toBe(5000)
        ->and(budgetSnapshotOf($eur, $period))->toBe(3000);
});

test('the catch-all spends what the cashflow general block counts with a foreign-currency expense', function () {
    $tracked = currencyTrackedPeriod($this);
    $catchAll = currencyCatchAllPeriod($this);
    $other = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    $live = [
        foreignCurrencyExpense($this, -2000, 'EUR'),
        foreignCurrencyExpense($this, -2900, 'USD', category: $other),
        foreignCurrencyExpense($this, -6100, 'USD', category: $other),
        foreignCurrencyExpense($this, -1500, 'EUR', category: $other),
    ];
    $this->service->assignTransactions($live, notify: false);
    $backfilled = foreignCurrencyExpense($this, -5905, 'USD', category: $other);
    $this->service->assignHistoricalTransactionsToPeriod($catchAll);

    $summary = $this->actingAs($this->user)
        ->getJson('/api/cashflow/summary?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->json('current');

    expect($catchAll->spentAmount())->toBe(1450 + 3050 + 1500 + 2953)
        ->and($catchAll->spentAmount())->toBe($summary['general_expense'])
        ->and($tracked->spentAmount())->toBe($summary['variable_expense'])
        ->and(budgetSnapshotOf($backfilled, $catchAll))->toBe(2953);
});
