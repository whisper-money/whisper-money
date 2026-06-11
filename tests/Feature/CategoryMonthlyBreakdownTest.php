<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::fake();
    Carbon::setTestNow('2026-06-15');

    $this->user = User::factory()->create(['currency_code' => 'USD']);
    $this->actingAs($this->user);

    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'USD',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeBreakdownTransaction(array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'currency_code' => 'USD',
        ...$attributes,
    ]);
}

function monthlyBreakdown(Category $category): TestResponse
{
    return test()->getJson("/api/categories/{$category->id}/monthly-breakdown");
}

test('it forbids analysing a category owned by another user', function () {
    $other = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $other->id, 'type' => CategoryType::Expense]);

    monthlyBreakdown($category)->assertForbidden();
});

test('the response is private and not cached between users', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    monthlyBreakdown($category)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('a leaf category returns a single series named after the category across twelve months', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food Delivery']);

    makeBreakdownTransaction(['amount' => -4000, 'category_id' => $category->id, 'transaction_date' => '2026-06-04']);
    makeBreakdownTransaction(['amount' => -1000, 'category_id' => $category->id, 'transaction_date' => '2026-05-09']);

    $response = monthlyBreakdown($category)->assertOk();

    expect($response->json('series'))->toBe([['key' => $category->id, 'label' => 'Food Delivery']]);
    expect($response->json('months'))->toHaveCount(12);
    expect($response->json('months.0.key'))->toBe('2025-07');
    expect($response->json('months.11.key'))->toBe('2026-06');
    expect($response->json('months.11.'.$category->id))->toBe(4000);
    expect($response->json('months.10.'.$category->id))->toBe(1000);
    expect($response->json('months.0.'.$category->id))->toBe(0);
});

test('spend nets within a month and a refund-dominant month dips below zero', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food Delivery']);

    // -40, -40, +20 -> nets to 60 of spend.
    makeBreakdownTransaction(['amount' => -4000, 'category_id' => $category->id, 'transaction_date' => '2026-06-04']);
    makeBreakdownTransaction(['amount' => -4000, 'category_id' => $category->id, 'transaction_date' => '2026-06-05']);
    makeBreakdownTransaction(['amount' => 2000, 'category_id' => $category->id, 'transaction_date' => '2026-06-06']);

    // Refunds exceed spend this month -> the bar dips below zero.
    makeBreakdownTransaction(['amount' => -1000, 'category_id' => $category->id, 'transaction_date' => '2026-05-09']);
    makeBreakdownTransaction(['amount' => 3000, 'category_id' => $category->id, 'transaction_date' => '2026-05-10']);

    $response = monthlyBreakdown($category)->assertOk();

    expect($response->json('months.11.'.$category->id))->toBe(6000);
    expect($response->json('months.10.'.$category->id))->toBe(-2000);
});

test('a parent rolls grandchildren into their immediate child and surfaces direct spend', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $delivery = Category::factory()->childOf($parent)->create(['name' => 'Delivery']);
    $grocery = Category::factory()->childOf($parent)->create(['name' => 'Grocery']);
    $uberEats = Category::factory()->childOf($delivery)->create(['name' => 'Uber Eats']);

    makeBreakdownTransaction(['amount' => -1000, 'category_id' => $delivery->id, 'transaction_date' => '2026-06-04']);
    makeBreakdownTransaction(['amount' => -500, 'category_id' => $uberEats->id, 'transaction_date' => '2026-06-05']);
    makeBreakdownTransaction(['amount' => -2000, 'category_id' => $grocery->id, 'transaction_date' => '2026-06-06']);
    makeBreakdownTransaction(['amount' => -300, 'category_id' => $parent->id, 'transaction_date' => '2026-06-07']);

    $response = monthlyBreakdown($parent)->assertOk();

    // Richest child first (Grocery 2000, then Delivery 1500), then Direct.
    expect($response->json('series'))->toBe([
        ['key' => $grocery->id, 'label' => 'Grocery'],
        ['key' => $delivery->id, 'label' => 'Delivery'],
        ['key' => 'direct', 'label' => 'Direct'],
    ]);
    expect($response->json('months.11.'.$grocery->id))->toBe(2000);
    expect($response->json('months.11.'.$delivery->id))->toBe(1500);
    expect($response->json('months.11.direct'))->toBe(300);
});

test('children beyond the top six fold into an Other segment', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Shopping']);

    foreach (range(1, 8) as $rank) {
        $child = Category::factory()->childOf($parent)->create(['name' => "Child {$rank}"]);
        makeBreakdownTransaction(['amount' => -1000 * $rank, 'category_id' => $child->id, 'transaction_date' => '2026-06-10']);
    }

    $response = monthlyBreakdown($parent)->assertOk();

    $series = $response->json('series');
    expect($series)->toHaveCount(7);
    expect(collect($series)->pluck('key')->last())->toBe('other');
    // The two smallest (1000 + 2000) are folded into Other.
    expect($response->json('months.11.other'))->toBe(3000);
});

test('only the trailing twelve months are included', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    makeBreakdownTransaction(['amount' => -5000, 'category_id' => $category->id, 'transaction_date' => '2026-06-01']);
    // Older than the window start (2025-07-01) -> excluded.
    makeBreakdownTransaction(['amount' => -9999, 'category_id' => $category->id, 'transaction_date' => '2025-06-30']);

    $response = monthlyBreakdown($category)->assertOk();

    $total = collect($response->json('months'))->sum($category->id);
    expect($total)->toBe(5000);
});

test('income categories keep inflows positive and net expenses against them', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income, 'name' => 'Salary']);

    makeBreakdownTransaction(['amount' => 500000, 'category_id' => $category->id, 'transaction_date' => '2026-06-01']);
    makeBreakdownTransaction(['amount' => -100000, 'category_id' => $category->id, 'transaction_date' => '2026-06-02']);

    $response = monthlyBreakdown($category)->assertOk();

    expect($response->json('months.11.'.$category->id))->toBe(400000);
});

test('summary reports the monthly average and the half-over-half trend', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    // Earlier half (2025-07..2025-12): one month of 6000 -> average 1000.
    makeBreakdownTransaction(['amount' => -6000, 'category_id' => $category->id, 'transaction_date' => '2025-09-15']);
    // Recent half (2026-01..2026-06): one month of 9000 -> average 1500.
    makeBreakdownTransaction(['amount' => -9000, 'category_id' => $category->id, 'transaction_date' => '2026-02-15']);

    $response = monthlyBreakdown($category)->assertOk();

    expect($response->json('summary.average_per_month'))->toBe(1250);
    expect($response->json('summary.trend_percentage'))->toEqual(50);
});

test('summary trend is null when the earlier half has no spending', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    makeBreakdownTransaction(['amount' => -6000, 'category_id' => $category->id, 'transaction_date' => '2026-06-10']);

    $response = monthlyBreakdown($category)->assertOk();

    expect($response->json('summary.average_per_month'))->toBe(500);
    expect($response->json('summary.trend_percentage'))->toBeNull();
});

test('foreign-currency transactions are converted to the user currency', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => '2026-06-01',
        'rates' => ['eur' => 0.5],
    ]);

    $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    makeBreakdownTransaction(['amount' => -1000, 'currency_code' => 'EUR', 'category_id' => $category->id, 'transaction_date' => '2026-06-01']);

    $response = monthlyBreakdown($category)->assertOk();

    // 1000 EUR cents / 0.5 = 2000 USD cents.
    expect($response->json('months.11.'.$category->id))->toBe(2000);
});
