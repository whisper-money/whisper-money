<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

function dayTransaction(string $date, int $amount, string $currency = 'EUR', array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'transaction_date' => $date,
        'amount' => $amount,
        'currency_code' => $currency,
        ...$attributes,
    ]);
}

/**
 * @return array<string, array{count: int, total: ?int}>
 */
function dayTotalsFor(array $query = []): array
{
    $response = actingAs(test()->user)
        ->get(route('transactions.index', $query))
        ->assertSuccessful();

    $dayTotals = $response->viewData('page')['props']['dayTotals'];
    ksort($dayTotals);

    return $dayTotals;
}

test('it counts and adds up every transaction of each day the page shows', function () {
    dayTransaction('2025-11-12', -1500);
    dayTransaction('2025-11-12', 4000);
    dayTransaction('2025-11-11', -250);

    expect(dayTotalsFor())->toBe([
        '2025-11-11' => ['count' => 1, 'total' => -250],
        '2025-11-12' => ['count' => 2, 'total' => 2500],
    ]);
});

test('it only counts the transactions the filters match', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    dayTransaction('2025-11-12', -1500, attributes: ['category_id' => $groceries->id]);
    dayTransaction('2025-11-12', -9900);
    dayTransaction('2025-11-11', -250);

    expect(dayTotalsFor(['category_ids' => [$groceries->id]]))->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -1500],
    ]);
});

test('it leaves out other users transactions on the same day', function () {
    dayTransaction('2025-11-12', -1500);
    $stranger = User::factory()->create();
    Transaction::factory()->create([
        'user_id' => $stranger->id,
        'account_id' => Account::factory()->create(['user_id' => $stranger->id])->id,
        'transaction_date' => '2025-11-12',
        'amount' => -99999,
    ]);

    expect(dayTotalsFor())->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -1500],
    ]);
});

test('it converts each currency into the user currency at the rate of its own day', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-11',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 4.0],
    ]);
    dayTransaction('2025-11-12', -1000);
    dayTransaction('2025-11-12', -8000, 'USD');
    dayTransaction('2025-11-12', -4000, 'USD');
    dayTransaction('2025-11-11', -8000, 'USD');

    expect(dayTotalsFor())->toBe([
        '2025-11-11' => ['count' => 1, 'total' => -4000],
        '2025-11-12' => ['count' => 3, 'total' => -4000],
    ]);
});

test('a day holding an amount with no rate has no total', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    dayTransaction('2025-11-12', -1000);
    dayTransaction('2025-11-12', -1000, 'USD');
    dayTransaction('2025-11-12', -1000, 'GBP');
    dayTransaction('2025-11-11', -250);

    expect(dayTotalsFor())->toBe([
        '2025-11-11' => ['count' => 1, 'total' => -250],
        '2025-11-12' => ['count' => 3, 'total' => null],
    ]);
});

test('a split counts its parts, not the original they replaced', function () {
    $original = dayTransaction('2025-11-12', -5340, attributes: ['category_id' => null]);

    actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
        'splits' => [['amount' => -3490], ['amount' => -1850]],
    ])->assertCreated();

    expect(dayTotalsFor())->toBe([
        '2025-11-12' => ['count' => 2, 'total' => -5340],
    ]);
});

/**
 * A transaction split into the given lines, each a category and an amount in
 * cents, without a category of its own, the way splitting one leaves it.
 *
 * @param  list<array{0: Category, 1: int}>  $lines
 */
function daySplitTransaction(string $date, array $lines, string $currency = 'EUR', ?int $amount = null): Transaction
{
    $transaction = dayTransaction(
        $date,
        $amount ?? array_sum(array_column($lines, 1)),
        $currency,
        ['category_id' => null],
    );

    foreach ($lines as $position => [$category, $lineAmount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $lineAmount,
            'position' => $position,
        ]);
    }

    return $transaction;
}

test('a provider outage costs the page one attempt and keeps the days that have a rate', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-09',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    dayTransaction('2025-11-12', -1000, 'USD');
    dayTransaction('2025-11-11', -1000, 'USD');
    dayTransaction('2025-11-10', -1000, 'USD');
    dayTransaction('2025-11-10', -700);
    dayTransaction('2025-11-09', -1000, 'USD');
    dayTransaction('2025-11-08', -250);

    expect(dayTotalsFor())->toBe([
        '2025-11-08' => ['count' => 1, 'total' => -250],
        '2025-11-09' => ['count' => 1, 'total' => -500],
        '2025-11-10' => ['count' => 2, 'total' => null],
        '2025-11-11' => ['count' => 1, 'total' => null],
        '2025-11-12' => ['count' => 1, 'total' => null],
    ])->and($calls)->toBe(2);
});

test('without a filter a split transaction stands at its own amount and counts once', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    daySplitTransaction('2025-11-12', [[$groceries, -3490], [$home, -1850]]);

    expect(dayTotalsFor())->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -5340],
    ]);
});

test('under a category filter a split transaction adds only its matching lines, in each currency', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);

    daySplitTransaction('2025-11-12', [[$groceries, -3000], [$home, -7000]]);
    daySplitTransaction('2025-11-12', [[$groceries, -4000], [$home, -6000]], 'USD');
    dayTransaction('2025-11-12', -500, attributes: ['category_id' => $groceries->id]);
    dayTransaction('2025-11-12', -9900, attributes: ['category_id' => $home->id]);

    // 3000 + 500 in euros, and 4000 dollars at 2 to the euro: the second
    // category's lines and its unsplit row stay out. Two split transactions
    // and one row are listed, so three, however the lines fall.
    expect(dayTotalsFor(['category_ids' => [$groceries->id]]))->toBe([
        '2025-11-12' => ['count' => 3, 'total' => -5500],
    ]);
});

test('under a category type filter a split transaction adds only the lines of that type', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $piggyBank = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Savings]);
    daySplitTransaction('2025-11-12', [[$groceries, -7000], [$piggyBank, -3000]]);
    dayTransaction('2025-11-12', -500, attributes: ['category_id' => $piggyBank->id]);
    dayTransaction('2025-11-12', -9900, attributes: ['category_id' => $groceries->id]);

    expect(dayTotalsFor(['category_types' => 'savings']))->toBe([
        '2025-11-12' => ['count' => 2, 'total' => -3500],
    ]);
});

test('a category type narrows the lines a category or a label keeps', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $piggyBank = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Savings]);
    $trip = Label::factory()->create(['user_id' => $this->user->id]);
    daySplitTransaction('2025-11-12', [[$groceries, -7000], [$piggyBank, -3000]])->labels()->attach($trip);

    expect(dayTotalsFor(['label_ids' => [$trip->id], 'category_types' => 'savings']))->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -3000],
    ]);
});

test('a filter on a parent category keeps the lines of its subcategories', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id]);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'parent_id' => $food->id]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    daySplitTransaction('2025-11-12', [[$groceries, -3000], [$home, -7000]]);

    expect(dayTotalsFor(['category_ids' => [$food->id]]))->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -3000],
    ]);
});

test('a label on a split transaction keeps all its lines, labels living on the transaction', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    $trip = Label::factory()->create(['user_id' => $this->user->id]);
    daySplitTransaction('2025-11-12', [[$groceries, -3000], [$home, -7000]])
        ->labels()->attach($trip);
    daySplitTransaction('2025-11-12', [[$groceries, -100], [$home, -200]]);

    expect(dayTotalsFor(['label_ids' => [$trip->id]]))->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -10000],
    ]);
});

test('a category and a label filter add the matching lines of one and all the lines of the other', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    $trip = Label::factory()->create(['user_id' => $this->user->id]);
    daySplitTransaction('2025-11-12', [[$groceries, -3000], [$home, -7000]]);
    daySplitTransaction('2025-11-12', [[$home, -100], [$home, -200]])->labels()->attach($trip);

    expect(dayTotalsFor(['category_ids' => [$groceries->id], 'label_ids' => [$trip->id]]))->toBe([
        '2025-11-12' => ['count' => 2, 'total' => -3300],
    ]);
});

test('a split transaction listed for its own category counts but adds no line of another', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    $transaction = daySplitTransaction('2025-11-12', [[$home, -7000]]);
    $transaction->update(['category_id' => $groceries->id]);

    expect(dayTotalsFor(['category_ids' => [$groceries->id]]))->toBe([
        '2025-11-12' => ['count' => 1, 'total' => 0],
    ]);
});

test('the day total under a category filter adds up what the CSV export writes', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    daySplitTransaction('2025-11-12', [[$groceries, -3000], [$home, -7000]]);
    dayTransaction('2025-11-12', -500, attributes: ['category_id' => $groceries->id]);

    $filters = ['category_ids' => [$groceries->id]];
    $total = dayTotalsFor($filters)['2025-11-12']['total'];

    $exported = actingAs($this->user)
        ->post(route('transactions.export'), $filters)
        ->assertOk()
        ->streamedContent();
    $rows = array_slice(explode("\n", trim(ltrim($exported, "\u{FEFF}"))), 1);
    $amounts = array_map(fn (string $row): float => (float) str_getcsv($row, escape: '')[2], $rows);

    expect((int) round(array_sum($amounts) * 100))->toBe($total);
});

test('under a cashflow block filter a split transaction adds only the lines in the block', function () {
    $this->account->update(['type' => AccountType::Checking]);
    $savings = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Savings]);
    $expense = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    daySplitTransaction('2025-11-12', [[$savings, -3000], [$expense, -7000]]);

    expect(dayTotalsFor([
        'date_from' => '2025-11-01',
        'date_to' => '2025-11-30',
        'cashflow_block' => 'saved',
    ]))->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -3000],
    ]);
});

test('a day cut by the pagination shows its whole figure on both pages', function () {
    dayTransaction('2025-11-12', -100);
    foreach (range(1, 14) as $ignored) {
        dayTransaction('2025-11-11', -1000);
    }
    dayTransaction('2025-11-10', -5);

    $firstPage = actingAs($this->user)
        ->get(route('transactions.index', ['per_page' => 10]))
        ->viewData('page')['props'];

    expect($firstPage['transactions']['data'])->toHaveCount(10)
        ->and($firstPage['dayTotals'])->toBe([
            '2025-11-12' => ['count' => 1, 'total' => -100],
            '2025-11-11' => ['count' => 14, 'total' => -14000],
        ]);

    $nextCursor = $firstPage['transactions']['next_cursor'];

    expect(dayTotalsFor(['per_page' => 10, 'cursor' => $nextCursor]))->toBe([
        '2025-11-10' => ['count' => 1, 'total' => -5],
        '2025-11-11' => ['count' => 14, 'total' => -14000],
    ]);
});

test('a bookmarked sort is ignored: the list stays by date and carries its day totals', function () {
    dayTransaction('2025-11-12', -1500);

    expect(dayTotalsFor(['sort' => '-amount']))->toBe([
        '2025-11-12' => ['count' => 1, 'total' => -1500],
    ]);
});

test('a partial load of the transactions brings the day totals asked beside them', function () {
    dayTransaction('2025-11-12', -1500);

    $version = actingAs($this->user)->get(route('transactions.index'))->viewData('page')['version'];

    actingAs($this->user)
        ->get(route('transactions.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Data' => 'transactions,dayTotals',
            'X-Inertia-Partial-Component' => 'transactions/index',
        ])
        ->assertOk()
        ->assertJsonPath('props.dayTotals', ['2025-11-12' => ['count' => 1, 'total' => -1500]])
        ->assertJsonMissingPath('props.categories');
});
