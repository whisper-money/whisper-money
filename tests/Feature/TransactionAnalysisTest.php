<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();

    $this->user = User::factory()->create(['currency_code' => 'USD']);
    $this->actingAs($this->user);

    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'USD',
    ]);
});

function makeTransaction(array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'currency_code' => 'USD',
        'category_id' => null,
        ...$attributes,
    ]);
}

test('analysis response is not cached between users', function () {
    $this->getJson('/api/transactions/analysis')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('summary totals income, expense, net and count from the filtered set', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $income = makeTransaction(['amount' => 100000, 'transaction_date' => '2026-01-10']);
    $income->labels()->attach($label);

    $expense = makeTransaction(['amount' => -40000, 'transaction_date' => '2026-01-12']);
    $expense->labels()->attach($label);

    // Outside the label filter, must be excluded.
    makeTransaction(['amount' => -99999, 'transaction_date' => '2026-01-12']);

    $response = $this->getJson('/api/transactions/analysis?'.http_build_query([
        'label_ids' => $label->id,
    ]));

    $response->assertOk()
        ->assertJson([
            'currency' => 'USD',
            'summary' => [
                'income' => 100000,
                'expense' => 40000,
                'net' => 60000,
                'count' => 2,
            ],
        ]);
});

test('category breakdown groups expenses by top-level category', function () {
    $hotel = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Hotel', 'color' => 'blue', 'icon' => 'Building']);
    $meals = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Meals']);

    makeTransaction(['amount' => -50000, 'category_id' => $hotel->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -20000, 'category_id' => $meals->id, 'transaction_date' => '2026-01-11']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('distinct_category_count'))->toBe(2);
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Hotel', 'amount' => 50000, 'color' => 'blue', 'icon' => 'Building', 'children' => []]);
    expect($response->json('by_category.1'))->toMatchArray(['name' => 'Meals', 'amount' => 20000, 'children' => []]);
});

test('category breakdown nests sub-categories under their parent total', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries', 'parent_id' => $food->id]);
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Restaurants', 'parent_id' => $food->id]);

    makeTransaction(['amount' => -30000, 'category_id' => $groceries->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -10000, 'category_id' => $restaurants->id, 'transaction_date' => '2026-01-11']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('distinct_category_count'))->toBe(1);
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Food', 'amount' => 40000]);
    expect($response->json('by_category.0.children.0'))->toMatchArray(['name' => 'Groceries', 'amount' => 30000]);
    expect($response->json('by_category.0.children.1'))->toMatchArray(['name' => 'Restaurants', 'amount' => 10000]);
});

test('spend booked directly on a split parent surfaces as a Direct child', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries', 'parent_id' => $food->id]);

    makeTransaction(['amount' => -30000, 'category_id' => $groceries->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -5000, 'category_id' => $food->id, 'transaction_date' => '2026-01-11']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Food', 'amount' => 35000]);

    $children = collect($response->json('by_category.0.children'));
    expect($children)->toHaveCount(2);
    expect($children->firstWhere('name', 'Groceries')['amount'])->toBe(30000);
    expect($children->firstWhere('name', 'Direct')['amount'])->toBe(5000);
});

test('grand-children fold into their level-two sub-category', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries', 'parent_id' => $food->id]);
    $organic = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Organic', 'parent_id' => $groceries->id]);

    makeTransaction(['amount' => -12000, 'category_id' => $organic->id, 'transaction_date' => '2026-01-10']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Food', 'amount' => 12000]);
    expect($response->json('by_category.0.children.0'))->toMatchArray(['name' => 'Groceries', 'amount' => 12000]);
});

test('tag breakdown sums expenses per label', function () {
    $trip = Label::factory()->create(['user_id' => $this->user->id, 'name' => 'Miami']);
    $food = Label::factory()->create(['user_id' => $this->user->id, 'name' => 'Food']);

    $meal = makeTransaction(['amount' => -3000, 'transaction_date' => '2026-01-10']);
    $meal->labels()->attach([$trip->id, $food->id]);

    $hotel = makeTransaction(['amount' => -7000, 'transaction_date' => '2026-01-11']);
    $hotel->labels()->attach($trip->id);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('distinct_label_count'))->toBe(2);
    expect($response->json('by_tag.0'))->toMatchArray(['name' => 'Miami', 'amount' => 10000]);
    expect($response->json('by_tag.1'))->toMatchArray(['name' => 'Food', 'amount' => 3000]);
});

test('over time uses daily buckets for short spans and carries a cumulative expense', function () {
    makeTransaction(['amount' => -1000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -2000, 'transaction_date' => '2026-01-12']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('over_time.bucket'))->toBe('day');

    $points = $response->json('over_time.points');
    expect($points)->toHaveCount(3); // Jan 10, 11 (gap filled), 12
    expect($points[0])->toMatchArray(['date' => '2026-01-10', 'expense' => 1000, 'cumulative_expense' => 1000]);
    expect($points[1])->toMatchArray(['date' => '2026-01-11', 'expense' => 0, 'cumulative_expense' => 1000]);
    expect($points[2])->toMatchArray(['date' => '2026-01-12', 'expense' => 2000, 'cumulative_expense' => 3000]);
});

test('over time switches to monthly buckets for long spans', function () {
    makeTransaction(['amount' => -1000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -2000, 'transaction_date' => '2026-06-10']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('over_time.bucket'))->toBe('month');
    expect($response->json('over_time.points'))->toHaveCount(6); // Jan..Jun
});

test('over time carries a cumulative net alongside the cumulative expense', function () {
    makeTransaction(['amount' => 5000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -2000, 'transaction_date' => '2026-01-11']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    $points = $response->json('over_time.points');
    expect($points[0])->toMatchArray(['cumulative_expense' => 0, 'cumulative_net' => 5000]);
    expect($points[1])->toMatchArray(['cumulative_expense' => 2000, 'cumulative_net' => 3000]);
});

test('largest expenses lists the biggest spends richest-first, capped at ten', function () {
    foreach (range(1, 12) as $index) {
        makeTransaction(['amount' => -$index * 1000, 'description' => "Expense {$index}", 'transaction_date' => '2026-01-10']);
    }
    // Income must never appear among the largest expenses.
    makeTransaction(['amount' => 999999, 'transaction_date' => '2026-01-10']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    $largest = $response->json('largest_expenses');
    expect($largest)->toHaveCount(10);
    expect($largest[0])->toMatchArray(['description' => 'Expense 12', 'amount' => 12000]);
    expect($largest[9])->toMatchArray(['description' => 'Expense 3', 'amount' => 3000]);
});

test('largest expenses carry the category, account and labels for display', function () {
    $bank = Bank::factory()->create(['name' => 'Acme Bank']);
    $account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'bank_id' => $bank->id]);
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Hotel']);
    $label = Label::factory()->create(['user_id' => $this->user->id, 'name' => 'Trip']);

    $transaction = makeTransaction([
        'amount' => -50000,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Grand Hotel',
        'transaction_date' => '2026-01-10',
    ]);
    $transaction->labels()->attach($label);

    $row = $this->getJson('/api/transactions/analysis')->assertOk()->json('largest_expenses.0');

    expect($row)->toMatchArray([
        'description' => 'Grand Hotel',
        'amount' => 50000,
        'category' => ['name' => 'Hotel', 'color' => $category->color, 'icon' => $category->icon],
        'account' => ['name' => $account->name, 'bank' => ['name' => 'Acme Bank', 'logo' => $bank->logo]],
    ]);
    expect($row['labels'])->toHaveCount(1);
    expect($row['labels'][0])->toMatchArray(['name' => 'Trip']);
});

test('payee breakdown sums named creditors and ignores blank ones', function () {
    makeTransaction(['amount' => -3000, 'creditor_name' => 'Hotel Paradiso', 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -2000, 'creditor_name' => 'Hotel Paradiso', 'transaction_date' => '2026-01-11']);
    makeTransaction(['amount' => -1000, 'creditor_name' => 'Cafe Roma', 'transaction_date' => '2026-01-12']);
    makeTransaction(['amount' => -9000, 'creditor_name' => null, 'transaction_date' => '2026-01-13']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('distinct_payee_count'))->toBe(2);
    expect($response->json('by_payee.0'))->toMatchArray(['name' => 'Hotel Paradiso', 'amount' => 5000]);
    expect($response->json('by_payee.1'))->toMatchArray(['name' => 'Cafe Roma', 'amount' => 1000]);
});

test('account breakdown sums expenses per funding account', function () {
    $other = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'USD', 'name' => 'Travel card']);

    makeTransaction(['amount' => -4000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -6000, 'account_id' => $other->id, 'transaction_date' => '2026-01-11']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('distinct_account_count'))->toBe(2);
    expect($response->json('by_account.0'))->toMatchArray(['name' => 'Travel card', 'amount' => 6000]);
});

test('summary excludes transfer category transactions from income and expense', function () {
    $transfer = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);

    makeTransaction(['amount' => 100000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -40000, 'transaction_date' => '2026-01-11']);

    // Internal movements between own accounts: must not move income or expense.
    makeTransaction(['amount' => 70000, 'category_id' => $transfer->id, 'transaction_date' => '2026-01-12']);
    makeTransaction(['amount' => -70000, 'category_id' => $transfer->id, 'transaction_date' => '2026-01-13']);

    $this->getJson('/api/transactions/analysis')
        ->assertOk()
        ->assertJson([
            'summary' => [
                'income' => 100000,
                'expense' => 40000,
                'net' => 60000,
            ],
        ]);
});

test('summary excludes savings and investment outflows from expense, matching cashflow', function () {
    $savings = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Savings]);
    $investment = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Investment]);

    makeTransaction(['amount' => -40000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -25000, 'category_id' => $savings->id, 'transaction_date' => '2026-01-11']);
    makeTransaction(['amount' => -15000, 'category_id' => $investment->id, 'transaction_date' => '2026-01-12']);

    $this->getJson('/api/transactions/analysis')
        ->assertOk()
        ->assertJson(['summary' => ['expense' => 40000]]);
});

test('category breakdown ignores transfer, savings and investment categories', function () {
    $expense = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries']);
    $transfer = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer, 'name' => 'Move money']);
    $savings = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Savings, 'name' => 'Rainy day']);

    makeTransaction(['amount' => -30000, 'category_id' => $expense->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -50000, 'category_id' => $transfer->id, 'transaction_date' => '2026-01-11']);
    makeTransaction(['amount' => -20000, 'category_id' => $savings->id, 'transaction_date' => '2026-01-12']);

    $response = $this->getJson('/api/transactions/analysis')->assertOk();

    expect($response->json('distinct_category_count'))->toBe(1);
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Groceries', 'amount' => 30000]);
});

test('tag, payee and account breakdowns ignore transfer categories', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);
    $transfer = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);

    makeTransaction(['amount' => -10000, 'creditor_name' => 'Shop', 'transaction_date' => '2026-01-10'])
        ->labels()->attach($label);

    $tagged = makeTransaction(['amount' => -90000, 'category_id' => $transfer->id, 'creditor_name' => 'My other account', 'transaction_date' => '2026-01-11']);
    $tagged->labels()->attach($label);

    $response = $this->getJson('/api/transactions/analysis')->assertOk();

    expect($response->json('distinct_label_count'))->toBe(1);
    expect($response->json('by_tag.0.amount'))->toBe(10000);
    expect($response->json('distinct_payee_count'))->toBe(1);
    expect($response->json('by_payee.0'))->toMatchArray(['name' => 'Shop', 'amount' => 10000]);
    expect($response->json('distinct_account_count'))->toBe(1);
    expect($response->json('by_account.0.amount'))->toBe(10000);
});

test('largest expenses ignores transfer category outflows', function () {
    $transfer = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);

    makeTransaction(['amount' => -5000, 'description' => 'Dinner', 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -99000, 'category_id' => $transfer->id, 'description' => 'Transfer out', 'transaction_date' => '2026-01-11']);

    $largest = $this->getJson('/api/transactions/analysis')->assertOk()->json('largest_expenses');

    expect($largest)->toHaveCount(1);
    expect($largest[0])->toMatchArray(['description' => 'Dinner', 'amount' => 5000]);
});

test('over time buckets ignore transfer categories', function () {
    $transfer = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);

    makeTransaction(['amount' => 30000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -10000, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => 50000, 'category_id' => $transfer->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -50000, 'category_id' => $transfer->id, 'transaction_date' => '2026-01-10']);

    $points = collect($this->getJson('/api/transactions/analysis')->assertOk()->json('over_time.points'));
    $day = $points->firstWhere('date', '2026-01-10');

    expect($day)->toMatchArray(['income' => 30000, 'expense' => 10000]);
});

test('refunds net against spending within an expense category, matching cashflow', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Shopping']);

    makeTransaction(['amount' => -10000, 'category_id' => $shopping->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => 3000, 'category_id' => $shopping->id, 'transaction_date' => '2026-01-15']);

    $response = $this->getJson('/api/transactions/analysis')->assertOk();

    // The +3000 refund is not income: it nets the expense down to 7000.
    $response->assertJson(['summary' => ['income' => 0, 'expense' => 7000, 'net' => -7000]]);
    expect($response->json('distinct_category_count'))->toBe(1);
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Shopping', 'amount' => 7000]);
});

test('reversals net against income within an income category', function () {
    $salary = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income, 'name' => 'Salary']);

    makeTransaction(['amount' => 10000, 'category_id' => $salary->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -2000, 'category_id' => $salary->id, 'transaction_date' => '2026-01-15']);

    $this->getJson('/api/transactions/analysis')
        ->assertOk()
        ->assertJson(['summary' => ['income' => 8000, 'expense' => 0, 'net' => 8000]]);
});

test('an uncategorized inflow is income, never an expense category row', function () {
    makeTransaction(['amount' => 5000, 'transaction_date' => '2026-01-10']);

    $response = $this->getJson('/api/transactions/analysis')->assertOk();

    $response->assertJson(['summary' => ['income' => 5000, 'expense' => 0, 'net' => 5000]]);
    expect($response->json('distinct_category_count'))->toBe(0);
    expect($response->json('by_category'))->toBe([]);
});

test('foreign currency expenses are converted to the user currency', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $eurAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);

    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => '2026-01-10',
        'rates' => ['eur' => 0.80],
    ]);

    makeTransaction(['amount' => -5000, 'category_id' => $shopping->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -4000, 'category_id' => $shopping->id, 'account_id' => $eurAccount->id, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-10']);

    // 4000 EUR / 0.80 = 5000 USD, on top of the 5000 USD spend.
    $this->getJson('/api/transactions/analysis')
        ->assertOk()
        ->assertJson(['summary' => ['expense' => 10000]]);
});
