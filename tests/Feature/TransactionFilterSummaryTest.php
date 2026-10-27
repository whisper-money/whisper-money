<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->groceries = Category::factory()->create(['user_id' => $this->user->id]);
});

function summaryTransaction(string $date, int $amount, string $currency = 'EUR', array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'category_id' => test()->groceries->id,
        'transaction_date' => $date,
        'amount' => $amount,
        'currency_code' => $currency,
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function filterSummaryFor(array $query): array
{
    return actingAs(test()->user)
        ->getJson(route('api.transactions.summary', $query))
        ->assertSuccessful()
        ->json();
}

test('it counts, adds up and averages every transaction the filters match', function () {
    summaryTransaction('2025-11-12', -1500);
    summaryTransaction('2025-11-11', -1000);
    summaryTransaction('2025-10-02', -500);
    summaryTransaction('2025-11-12', -9900, attributes: ['category_id' => null]);

    $summary = filterSummaryFor(['category_ids' => [$this->groceries->id]]);

    expect($summary)
        ->count->toBe(3)
        ->total->toBe(-3000)
        ->average->toBe(-1000)
        ->kind->toBe('expense')
        ->currency->toBe('EUR');
});

test('it covers every matching row, not only the first page', function () {
    foreach (range(1, 12) as $day) {
        summaryTransaction(sprintf('2025-11-%02d', $day), -100);
    }

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id], 'per_page' => 10]))
        ->count->toBe(12)
        ->total->toBe(-1200);
});

test('the kind says whether the matching rows are all expenses, all income or both', function (array $amounts, string $kind) {
    foreach ($amounts as $amount) {
        summaryTransaction('2025-11-12', $amount);
    }

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]])['kind'])->toBe($kind);
})->with([
    'expenses' => [[-100, -200], 'expense'],
    'income' => [[100, 200], 'income'],
    'both' => [[-100, 200], 'mixed'],
    'nothing' => [[], 'mixed'],
]);

test('a mixed set totals the net figure', function () {
    summaryTransaction('2025-11-12', -1000);
    summaryTransaction('2025-11-13', 4000);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->total->toBe(3000)
        ->average->toBe(1500);
});

test('it converts each currency once per day, as the day totals do', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 3.0],
    ]);
    // Converted one by one, each row rounds to -33 and the total to -99.
    summaryTransaction('2025-11-12', -100, 'USD');
    summaryTransaction('2025-11-12', -100, 'USD');
    summaryTransaction('2025-11-12', -100, 'USD');
    summaryTransaction('2025-11-12', -500);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(4)
        ->total->toBe(-600)
        ->average->toBe(-150);
});

test('an amount with no rate leaves the total and the average out', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0],
    ]);
    summaryTransaction('2025-11-12', -500);
    summaryTransaction('2025-11-12', -100, 'GBP');

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(2)
        ->total->toBeNull()
        ->average->toBeNull();
});

test('the trend totals the same filters month by month over the six months the filter ends in', function () {
    summaryTransaction('2025-06-30', -999);
    summaryTransaction('2025-07-01', -100);
    summaryTransaction('2025-09-15', -200);
    summaryTransaction('2025-09-20', -300);
    summaryTransaction('2025-12-31', -400);
    summaryTransaction('2026-01-01', -999);
    summaryTransaction('2025-09-15', -999, attributes: ['category_id' => null]);

    $summary = filterSummaryFor([
        'category_ids' => [$this->groceries->id],
        'date_from' => '2025-12-01',
        'date_to' => '2025-12-31',
    ]);

    expect($summary['count'])->toBe(1)
        ->and($summary['trend'])->toBe([
            ['month' => '2025-07', 'total' => -100],
            ['month' => '2025-08', 'total' => 0],
            ['month' => '2025-09', 'total' => -500],
            ['month' => '2025-10', 'total' => 0],
            ['month' => '2025-11', 'total' => 0],
            ['month' => '2025-12', 'total' => -400],
        ]);
});

test('without an end date the trend ends this month', function () {
    Carbon::setTestNow('2026-03-10');
    summaryTransaction('2026-03-02', -700);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]])['trend'])
        ->toHaveCount(6)
        ->sequence(
            fn ($month) => $month->month->toBe('2025-10'),
            fn ($month) => $month->month->toBe('2025-11'),
            fn ($month) => $month->month->toBe('2025-12'),
            fn ($month) => $month->month->toBe('2026-01'),
            fn ($month) => $month->month->toBe('2026-02'),
            fn ($month) => $month->toBe(['month' => '2026-03', 'total' => -700]),
        );
});

test('it leaves out other users transactions', function () {
    summaryTransaction('2025-11-12', -100);
    $stranger = User::factory()->create();
    Transaction::factory()->create([
        'user_id' => $stranger->id,
        'account_id' => Account::factory()->create(['user_id' => $stranger->id])->id,
        'transaction_date' => '2025-11-12',
        'amount' => -99999,
    ]);

    expect(filterSummaryFor(['date_from' => '2025-11-01', 'date_to' => '2025-11-30']))
        ->count->toBe(1)
        ->total->toBe(-100);
});

test('the summary route validates its filters like the list does', function () {
    actingAs($this->user)
        ->getJson(route('api.transactions.summary', ['date_from' => 'yesterday']))
        ->assertUnprocessable();
});

test('the transactions page defers the summary of a filtered list', function () {
    summaryTransaction('2025-11-12', -1500);

    actingAs($this->user)
        ->get(route('transactions.index', ['category_ids' => [$this->groceries->id]]))
        ->assertInertia(fn ($page) => $page
            ->missing('filterSummary')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('filterSummary.count', 1)
                ->where('filterSummary.total', -1500)
                ->where('filterSummary.kind', 'expense')
            )
        );
});

test('the transactions page has no summary without a filter', function () {
    summaryTransaction('2025-11-12', -1500);

    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page->where('filterSummary', null));
});
