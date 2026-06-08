<?php

use App\Enums\CategoryType;
use App\Features\TransactionAnalysis;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Http::fake();

    $this->user = User::factory()->create(['currency_code' => 'USD']);
    $this->actingAs($this->user);
    Feature::for($this->user)->activate(TransactionAnalysis::class);

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
        ...$attributes,
    ]);
}

test('analysis endpoint is gated behind the TransactionAnalysis feature flag', function () {
    Feature::for($this->user)->deactivate(TransactionAnalysis::class);

    $this->getJson('/api/transactions/analysis')->assertForbidden();
});

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
    $hotel = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Hotel']);
    $meals = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Meals']);

    makeTransaction(['amount' => -50000, 'category_id' => $hotel->id, 'transaction_date' => '2026-01-10']);
    makeTransaction(['amount' => -20000, 'category_id' => $meals->id, 'transaction_date' => '2026-01-11']);

    $response = $this->getJson('/api/transactions/analysis');

    $response->assertOk();
    expect($response->json('distinct_category_count'))->toBe(2);
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Hotel', 'amount' => 50000, 'children' => []]);
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
