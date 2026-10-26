<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;

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
