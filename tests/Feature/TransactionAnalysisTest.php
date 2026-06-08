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
    expect($response->json('by_category.0'))->toMatchArray(['name' => 'Hotel', 'amount' => 50000]);
    expect($response->json('by_category.1'))->toMatchArray(['name' => 'Meals', 'amount' => 20000]);
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
