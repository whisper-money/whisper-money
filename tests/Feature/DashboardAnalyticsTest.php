<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\LoanDetail;
use App\Models\RealEstateDetail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Prevent real HTTP calls to currency API in all tests
    Http::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('net worth calculates assets minus liabilities', function () {
    // Assets
    $checking = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);
    AccountBalance::factory()->create([
        'account_id' => $checking->id,
        'balance_date' => now(),
        'balance' => 500000, // $5,000.00
    ]);

    // Liabilities
    $creditCard = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::CreditCard,
        'currency_code' => 'USD',
    ]);
    AccountBalance::factory()->create([
        'account_id' => $creditCard->id,
        'balance_date' => now(),
        'balance' => -100000, // -$1,000.00
    ]);

    // Previous period data (30 days ago)
    AccountBalance::factory()->create([
        'account_id' => $checking->id,
        'balance_date' => now()->subDays(30),
        'balance' => 400000, // $4,000.00
    ]);
    AccountBalance::factory()->create([
        'account_id' => $creditCard->id,
        'balance_date' => now()->subDays(30),
        'balance' => -50000, // -$500.00
    ]);

    $response = $this->getJson('/api/dashboard/net-worth?'.http_build_query([
        'from' => now()->subDays(29)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => 400000, // 5000 - 1000 = 4000
            'previous' => 350000, // 4000 - 500 = 3500
            'currency_code' => 'USD',
        ]);
});

test('net worth response includes currency_code', function () {
    $response = $this->getJson('/api/dashboard/net-worth?'.http_build_query([
        'from' => now()->subDays(29)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJsonStructure(['current', 'previous', 'currency_code'])
        ->assertJson(['currency_code' => 'USD']);
});

test('net worth treats positive loan balances as liabilities', function () {
    $checking = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    $loan = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $checking->id,
        'balance_date' => now(),
        'balance' => 500000,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $loan->id,
        'balance_date' => now(),
        'balance' => 100000,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $checking->id,
        'balance_date' => now()->subDays(30),
        'balance' => 450000,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $loan->id,
        'balance_date' => now()->subDays(30),
        'balance' => 150000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth?'.http_build_query([
        'from' => now()->subDays(29)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => 400000,
            'previous' => 300000,
            'currency_code' => 'USD',
        ]);
});

test('monthly spending calculates expenses correctly', function () {
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    // Current period expense
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => -5000, // -$50.00
        'transaction_date' => now(),
    ]);

    // Previous period expense (use subMonthNoOverflow to avoid Dec 31 -> Dec 1 issue)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => -3000, // -$30.00
        'transaction_date' => now()->subMonthNoOverflow(),
    ]);

    $response = $this->getJson('/api/dashboard/monthly-spending?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => 5000,
            'previous' => 3000,
        ]);
});

test('cash flow calculates income and expenses', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    // Income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $incomeCategory->id,
        'amount' => 10000, // $100.00
        'transaction_date' => now(),
    ]);

    // Expense
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $expenseCategory->id,
        'amount' => -4000, // -$40.00
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/dashboard/cash-flow?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => [
                'income' => 10000,
                'expense' => 4000,
            ],
        ]);
});

test('top categories returns highest spending categories', function () {
    $cat1 = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $cat2 = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Rent']);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $cat1->id,
        'amount' => -1000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $cat1->id,
        'amount' => -2000, // Total -3000
        'transaction_date' => now(),
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $cat2->id,
        'amount' => -5000, // Total -5000 (Higher)
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/dashboard/top-categories?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveCount(2);
    expect($data[0]['category']['id'])->toBe($cat2->id); // Highest spending first
    expect($data[0]['amount'])->toBe(5000);
    expect($data[0])->toHaveKeys(['previous_amount', 'total_amount']);
    expect($data[0]['total_amount'])->toBe(8000); // 5000 + 3000
    expect($data[1]['category']['id'])->toBe($cat1->id);
    expect($data[1]['amount'])->toBe(3000);
});

test('dashboard analytics net refunds in expense categories', function () {
    $foodDelivery = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Food Delivery',
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $foodDelivery->id,
        'amount' => -8000,
        'transaction_date' => '2026-05-05',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $foodDelivery->id,
        'amount' => 2000,
        'transaction_date' => '2026-05-06',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $foodDelivery->id,
        'amount' => 2000,
        'transaction_date' => '2026-05-07',
    ]);

    $period = [
        'from' => '2026-05-01',
        'to' => '2026-05-31',
    ];

    $monthlySpending = $this->getJson('/api/dashboard/monthly-spending?'.http_build_query($period));
    $cashFlow = $this->getJson('/api/dashboard/cash-flow?'.http_build_query($period));
    $topCategories = $this->getJson('/api/dashboard/top-categories?'.http_build_query($period));

    $monthlySpending->assertOk()
        ->assertJsonPath('current', 4000);

    $cashFlow->assertOk()
        ->assertJsonPath('current.income', 0)
        ->assertJsonPath('current.expense', 4000);

    $topCategories->assertOk()
        ->assertJsonPath('0.category.name', 'Food Delivery')
        ->assertJsonPath('0.amount', 4000)
        ->assertJsonPath('0.total_amount', 4000);
});

test('top categories excludes soft deleted categories', function () {
    $activeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Food',
    ]);
    $deletedCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Old category',
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $activeCategory->id,
        'amount' => -1000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $deletedCategory->id,
        'amount' => -5000,
        'transaction_date' => now(),
    ]);

    $deletedCategory->delete();

    $response = $this->getJson('/api/dashboard/top-categories?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.category.id'))->toBe($activeCategory->id);
});

test('top categories rolls child spending up into the top-level parent', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->childOf($food)->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $restaurants = Category::factory()->childOf($food)->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $food->id,
        'amount' => -1000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $groceries->id,
        'amount' => -2000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $restaurants->id,
        'amount' => -3000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/dashboard/top-categories?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveCount(1);
    expect($data[0]['category']['id'])->toBe($food->id);
    expect($data[0]['amount'])->toBe(6000);
    expect($data[0]['total_amount'])->toBe(6000);
});

test('net worth evolution returns monthly data points with per-account balances', function () {
    $account1 = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'Checking Account',
        'currency_code' => 'USD',
    ]);
    $account2 = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'name' => 'Savings Account',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account1->id,
        'balance_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'balance' => 200000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account1->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 150000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 250000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query([
        'from' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['data', 'accounts', 'currency_code']);
    expect($data['currency_code'])->toBe('USD');
    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['month', 'timestamp', $account1->id, $account2->id]);
    expect($data['accounts'])->toHaveKey($account1->id);
    expect($data['accounts'])->toHaveKey($account2->id);
    expect($data['accounts'][$account1->id]['name'])->toBe('Checking Account');
    expect($data['accounts'][$account1->id]['currency_code'])->toBe('USD');
    expect($data['accounts'][$account2->id]['name'])->toBe('Savings Account');
    expect($data['accounts'][$account2->id]['currency_code'])->toBe('USD');
});

test('net worth evolution converts foreign currency accounts using cached exchange rates', function () {
    $usdAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'USD Checking',
        'currency_code' => 'USD',
    ]);
    $eurAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'name' => 'EUR Savings',
        'currency_code' => 'EUR',
    ]);

    $lastMonth = now()->subMonthNoOverflow();
    $endOfMonth = $lastMonth->copy()->endOfMonth();

    AccountBalance::factory()->create([
        'account_id' => $usdAccount->id,
        'balance_date' => $endOfMonth,
        'balance' => 100000, // $1,000.00 USD
    ]);
    AccountBalance::factory()->create([
        'account_id' => $eurAccount->id,
        'balance_date' => $endOfMonth,
        'balance' => 200000, // €2,000.00 EUR
    ]);

    // Seed exchange rate: 1 USD = 0.90 EUR, so EUR -> USD = 200000 / 0.90 = 222222
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $endOfMonth->toDateString(),
        'rates' => ['eur' => 0.90],
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query([
        'from' => $lastMonth->copy()->startOfMonth()->toDateString(),
        'to' => $endOfMonth->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // USD account: no conversion, stays 100000
    expect($data['data'][0][$usdAccount->id])->toBe(100000);
    // EUR account: 200000 / 0.90 = 222222 (rounded)
    expect($data['data'][0][$eurAccount->id])->toBe((int) round(200000 / 0.90));

    // EUR account should have _original data
    $originalKey = $eurAccount->id.'_original';
    expect($data['data'][0])->toHaveKey($originalKey);
    expect($data['data'][0][$originalKey])->toMatchArray([
        'amount' => 200000,
        'currency_code' => 'EUR',
    ]);

    // USD account should NOT have _original data (same currency)
    $usdOriginalKey = $usdAccount->id.'_original';
    expect($data['data'][0])->not->toHaveKey($usdOriginalKey);

    Http::assertNothingSent();
});

test('net worth evolution preloads exchange rates for the requested date range', function () {
    $eurAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'currency_code' => 'EUR',
    ]);

    $end = now()->subMonthNoOverflow()->endOfMonth();
    $start = $end->copy()->subMonthsNoOverflow(11)->startOfMonth();
    $current = $start->copy();

    while ($current->lte($end)) {
        $date = $current->copy()->endOfMonth();

        AccountBalance::factory()->create([
            'account_id' => $eurAccount->id,
            'balance_date' => $date,
            'balance' => 100000,
        ]);

        ExchangeRate::factory()->create([
            'base_currency' => 'usd',
            'date' => $date->toDateString(),
            'rates' => ['eur' => 0.90],
        ]);

        $current->addMonth();
    }

    $exchangeRateSelects = [];
    DB::listen(function ($query) use (&$exchangeRateSelects): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'exchange_rates')) {
            $exchangeRateSelects[] = $query->sql;
        }
    });

    $response = $this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query([
        'from' => $start->toDateString(),
        'to' => $end->toDateString(),
    ]));

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(12);
    expect($exchangeRateSelects)->toHaveCount(1);
    Http::assertNothingSent();
});

test('net worth evolution uses last balance of each month per account', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    $lastMonth = now()->subMonthNoOverflow();

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $lastMonth->copy()->startOfMonth()->addDays(5),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $lastMonth->copy()->endOfMonth()->subDays(5),
        'balance' => 150000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $lastMonth->copy()->endOfMonth(),
        'balance' => 200000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query([
        'from' => $lastMonth->copy()->startOfMonth()->toDateString(),
        'to' => $lastMonth->copy()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0][$account->id])->toBe(200000);
});

test('net worth evolution returns account metadata including bank', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::CreditCard,
        'name' => 'My Credit Card',
        'name_iv' => 'test_iv_1234567',
        'currency_code' => 'USD',
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['accounts'][$account->id])->toMatchArray([
        'id' => $account->id,
        'name' => 'My Credit Card',
        'name_iv' => 'test_iv_1234567',
        'type' => 'credit_card',
    ]);
    expect($data['accounts'][$account->id])->toHaveKey('bank');
    expect($data['accounts'][$account->id]['bank'])->toHaveKeys(['id', 'name', 'logo']);
});

test('account daily balance evolution returns daily data points', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'Daily Test Account',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subDays(2),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subDays(1),
        'balance' => 110000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 120000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['data', 'account']);
    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['date', 'timestamp', 'value']);
    expect($data['data'][0]['date'])->toBe(now()->subDays(2)->format('Y-m-d'));
    expect($data['data'][0]['value'])->toBe(100000);
    expect($data['data'][1]['value'])->toBe(110000);
    expect($data['data'][2]['value'])->toBe(120000);
    expect($data['account']['id'])->toBe($account->id);
    expect($data['account']['currency_code'])->toBe('USD');
});

test('account daily balance evolution fills gaps with last known balance', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'currency_code' => 'USD',
    ]);

    // Balance before the range — carried forward into gap days
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subDays(10),
        'balance' => 50000,
    ]);

    // Balance on the last day of the range
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 80000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // 3 days in range: -2, -1, today
    expect($data['data'])->toHaveCount(3);
    // Days -2 and -1 have no direct entry, so they carry forward the 50000 balance
    expect($data['data'][0]['value'])->toBe(50000);
    expect($data['data'][1]['value'])->toBe(50000);
    // Today has the actual 80000 entry
    expect($data['data'][2]['value'])->toBe(80000);
});

test('account daily balance evolution forbids access to other users accounts', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
        'type' => AccountType::Checking,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertForbidden();
});

test('net worth daily evolution returns daily data points with per-account balances', function () {
    $account1 = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'Daily Checking',
        'currency_code' => 'USD',
    ]);
    $account2 = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'name' => 'Daily Savings',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account1->id,
        'balance_date' => now()->subDays(2),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => now()->subDays(2),
        'balance' => 200000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account1->id,
        'balance_date' => now(),
        'balance' => 150000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => now(),
        'balance' => 250000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-daily-evolution?'.http_build_query([
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['data', 'accounts', 'currency_code']);
    expect($data['currency_code'])->toBe('USD');
    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['date', 'timestamp', $account1->id, $account2->id]);
    expect($data['data'][0]['date'])->toBe(now()->subDays(2)->format('Y-m-d'));
    expect($data['data'][0][$account1->id])->toBe(100000);
    expect($data['data'][0][$account2->id])->toBe(200000);
    expect($data['data'][2][$account1->id])->toBe(150000);
    expect($data['data'][2][$account2->id])->toBe(250000);
    expect($data['accounts'])->toHaveKey($account1->id);
    expect($data['accounts'])->toHaveKey($account2->id);
    expect($data['accounts'][$account1->id]['currency_code'])->toBe('USD');
    expect($data['accounts'][$account2->id]['currency_code'])->toBe('USD');
});

test('net worth daily evolution converts foreign currency accounts', function () {
    $usdAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'USD Account',
        'currency_code' => 'USD',
    ]);
    $gbpAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'name' => 'GBP Account',
        'currency_code' => 'GBP',
    ]);

    $today = now();

    AccountBalance::factory()->create([
        'account_id' => $usdAccount->id,
        'balance_date' => $today,
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $gbpAccount->id,
        'balance_date' => $today,
        'balance' => 50000, // £500.00
    ]);

    // Seed exchange rate: 1 USD = 0.79 GBP
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $today->toDateString(),
        'rates' => ['gbp' => 0.79],
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-daily-evolution?'.http_build_query([
        'from' => $today->toDateString(),
        'to' => $today->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // USD stays the same
    expect($data['data'][0][$usdAccount->id])->toBe(100000);
    // GBP: 50000 / 0.79 = 63291 (rounded)
    expect($data['data'][0][$gbpAccount->id])->toBe((int) round(50000 / 0.79));

    // Original data for GBP account
    $originalKey = $gbpAccount->id.'_original';
    expect($data['data'][0])->toHaveKey($originalKey);
    expect($data['data'][0][$originalKey]['amount'])->toBe(50000);
    expect($data['data'][0][$originalKey]['currency_code'])->toBe('GBP');
});

test('net worth daily evolution fills gaps with last known balance', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    // Balance before the range — carried forward into gap days
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subDays(10),
        'balance' => 50000,
    ]);

    // Balance on the last day of the range
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 80000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-daily-evolution?'.http_build_query([
        'from' => now()->subDays(2)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // 3 days in range: -2, -1, today
    expect($data['data'])->toHaveCount(3);
    // Days -2 and -1 carry forward the 50000 balance
    expect($data['data'][0][$account->id])->toBe(50000);
    expect($data['data'][1][$account->id])->toBe(50000);
    // Today has the actual 80000 entry
    expect($data['data'][2][$account->id])->toBe(80000);
});

test('account daily balance evolution includes invested_amount for investment accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'name' => 'My Portfolio',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subDays(1),
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 550000,
        'invested_amount' => 420000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => now()->subDays(1)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->toHaveKey('invested_amount');
    expect($data['data'][0]['invested_amount'])->toBe(400000);
    expect($data['data'][1]['invested_amount'])->toBe(420000);
});

test('account daily balance evolution does not include invested_amount for non-investment accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'My Checking',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 500000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => now()->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->not->toHaveKey('invested_amount');
});

test('account daily balance evolution carries forward last known invested_amount across gaps', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    // invested_amount recorded 5 days ago
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subDays(5),
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);

    // Balance today with no invested_amount
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 550000,
        'invested_amount' => null,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => now()->subDays(1)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // Both days should carry forward the 400000 invested_amount from 5 days ago
    expect($data['data'][0]['invested_amount'])->toBe(400000);
    expect($data['data'][1]['invested_amount'])->toBe(400000);
});

test('account balance evolution includes invested_amount for retirement accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Retirement,
        'name' => 'Pension Fund',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 1000000,
        'invested_amount' => 800000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->toHaveKey('invested_amount');
    expect($data['data'][0]['invested_amount'])->toBe(800000);
});

test('account balance evolution does not include invested_amount for checking accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'My Checking',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 1000000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->not->toHaveKey('invested_amount');
});

test('net worth evolution includes invested_amount in accountsConfig for investment accounts', function () {
    $investment = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'name' => 'My Portfolio',
        'currency_code' => 'USD',
    ]);
    $checking = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'My Checking',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $investment->id,
        'balance_date' => now(),
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $checking->id,
        'balance_date' => now(),
        'balance' => 300000,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // Investment account should have invested_amount in its config
    expect($data['accounts'][$investment->id])->toHaveKey('invested_amount');
    expect($data['accounts'][$investment->id]['invested_amount'])->toBe(400000);

    // Checking account should NOT have invested_amount
    expect($data['accounts'][$checking->id])->not->toHaveKey('invested_amount');
});

test('net worth evolution returns null invested_amount when no invested data exists', function () {
    $investment = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'name' => 'Empty Portfolio',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $investment->id,
        'balance_date' => now(),
        'balance' => 500000,
        'invested_amount' => null,
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['accounts'][$investment->id])->toHaveKey('invested_amount');
    expect($data['accounts'][$investment->id]['invested_amount'])->toBeNull();
});

test('net worth daily evolution returns account metadata including bank', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::CreditCard,
        'name' => 'My Daily CC',
        'name_iv' => 'test_iv_daily',
        'currency_code' => 'USD',
    ]);

    $response = $this->getJson('/api/dashboard/net-worth-daily-evolution?'.http_build_query([
        'from' => now()->subDays(1)->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['accounts'][$account->id])->toMatchArray([
        'id' => $account->id,
        'name' => 'My Daily CC',
        'name_iv' => 'test_iv_daily',
        'type' => 'credit_card',
    ]);
    expect($data['accounts'][$account->id])->toHaveKey('bank');
    expect($data['accounts'][$account->id]['bank'])->toHaveKeys(['id', 'name', 'logo']);
});

test('account balance evolution includes display_* fields when account currency differs from user currency', function () {
    // User currency is USD (default from factory)
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'EUR Checking',
        'currency_code' => 'EUR',
    ]);

    $lastMonth = now()->subMonthNoOverflow();
    $endOfMonth = $lastMonth->copy()->endOfMonth();

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $endOfMonth,
        'balance' => 100000, // €1,000.00
    ]);

    // Seed exchange rate: 1 USD = 0.90 EUR, so EUR -> USD = 100000 / 0.90
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $endOfMonth->toDateString(),
        'rates' => ['eur' => 0.90],
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => $lastMonth->copy()->startOfMonth()->toDateString(),
        'to' => $endOfMonth->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // Original value stays in EUR
    expect($data['data'][0]['value'])->toBe(100000);
    // Converted display value in USD
    expect($data['data'][0])->toHaveKey('display_value');
    expect($data['data'][0]['display_value'])->toBe((int) round(100000 / 0.90));
    // Top-level display_currency_code is present
    expect($data)->toHaveKey('display_currency_code');
    expect($data['display_currency_code'])->toBe('USD');
    // Account metadata still shows original currency
    expect($data['account']['currency_code'])->toBe('EUR');
});

test('account balance evolution does not include display_* fields when currencies match', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'USD Checking',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 100000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->not->toHaveKey('display_value');
    expect($data)->not->toHaveKey('display_currency_code');
});

test('account daily balance evolution includes display_* fields when account currency differs from user currency', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'name' => 'GBP Savings',
        'currency_code' => 'GBP',
    ]);

    $today = now();
    $yesterday = now()->subDays(1);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $yesterday,
        'balance' => 50000, // £500.00
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $today,
        'balance' => 60000, // £600.00
    ]);

    // Seed exchange rates for both days
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $yesterday->toDateString(),
        'rates' => ['gbp' => 0.79],
    ]);
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $today->toDateString(),
        'rates' => ['gbp' => 0.80],
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => $yesterday->toDateString(),
        'to' => $today->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // Original values stay in GBP
    expect($data['data'][0]['value'])->toBe(50000);
    expect($data['data'][1]['value'])->toBe(60000);

    // Converted display values in USD
    expect($data['data'][0])->toHaveKey('display_value');
    expect($data['data'][0]['display_value'])->toBe((int) round(50000 / 0.79));
    expect($data['data'][1])->toHaveKey('display_value');
    expect($data['data'][1]['display_value'])->toBe((int) round(60000 / 0.80));

    // Top-level display_currency_code
    expect($data)->toHaveKey('display_currency_code');
    expect($data['display_currency_code'])->toBe('USD');
    expect($data['account']['currency_code'])->toBe('GBP');
});

test('account daily balance evolution does not include display_* fields when currencies match', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'USD Daily',
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now(),
        'balance' => 100000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/daily-balance-evolution?'.http_build_query([
        'from' => now()->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->not->toHaveKey('display_value');
    expect($data)->not->toHaveKey('display_currency_code');
});

test('account balance evolution includes display_invested_amount for foreign currency investment accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'name' => 'EUR Portfolio',
        'currency_code' => 'EUR',
    ]);

    $lastMonth = now()->subMonthNoOverflow();
    $endOfMonth = $lastMonth->copy()->endOfMonth();

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $endOfMonth,
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);

    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $endOfMonth->toDateString(),
        'rates' => ['eur' => 0.90],
    ]);

    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => $endOfMonth->toDateString(),
        'rates' => ['usd' => 1 / 0.90],
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => $lastMonth->copy()->startOfMonth()->toDateString(),
        'to' => $endOfMonth->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // invested_amount is now stored in user currency (USD)
    expect($data['data'][0]['invested_amount'])->toBe(400000);
    // Converted display values (invested_amount converted from user currency USD → account currency EUR)
    expect($data['data'][0])->toHaveKey('display_value');
    expect($data['data'][0])->toHaveKey('display_invested_amount');
    expect($data['data'][0]['display_invested_amount'])->toBe((int) round(400000 * 0.90));
    expect($data['display_currency_code'])->toBe('USD');
});

test('account balance evolution converts mortgage using loan currency when it differs from property currency', function () {
    // User currency is USD, property is USD, loan is EUR — only the mortgage needs conversion
    $property = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::RealEstate,
        'currency_code' => 'USD',
    ]);
    $loan = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
        'currency_code' => 'EUR',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $property->id,
        'linked_loan_account_id' => $loan->id,
    ]);

    $lastMonth = now()->subMonthNoOverflow();
    $endOfMonth = $lastMonth->copy()->endOfMonth();

    AccountBalance::factory()->create([
        'account_id' => $property->id,
        'balance_date' => $endOfMonth,
        'balance' => 50000000, // $500,000.00 USD
    ]);
    AccountBalance::factory()->create([
        'account_id' => $loan->id,
        'balance_date' => $endOfMonth,
        'balance' => 18000000, // €180,000.00 EUR
    ]);

    // 1 USD = 0.90 EUR, so EUR -> USD = 18000000 / 0.90
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $endOfMonth->toDateString(),
        'rates' => ['eur' => 0.90],
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$property->id.'/balance-evolution?'.http_build_query([
        'from' => $lastMonth->copy()->startOfMonth()->toDateString(),
        'to' => $endOfMonth->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    // Property value stays in USD and no alternate display currency is exposed.
    expect($data['data'][0]['value'])->toBe(50000000);
    expect($data['data'][0])->not->toHaveKey('display_value');

    // Mortgage balance is normalized into the property's account currency.
    expect($data['data'][0]['mortgage_balance'])->toBe((int) round(18000000 / 0.90));
    expect($data['data'][0])->not->toHaveKey('display_mortgage_balance');
    expect($data)->not->toHaveKey('display_currency_code');
});

test('account daily balance evolution converts mortgage using loan currency when it differs from property currency', function () {
    $property = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::RealEstate,
        'currency_code' => 'USD',
    ]);
    $loan = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
        'currency_code' => 'EUR',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $property->id,
        'linked_loan_account_id' => $loan->id,
    ]);

    $today = now();

    AccountBalance::factory()->create([
        'account_id' => $property->id,
        'balance_date' => $today,
        'balance' => 50000000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $loan->id,
        'balance_date' => $today,
        'balance' => 18000000,
    ]);

    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $today->toDateString(),
        'rates' => ['eur' => 0.90],
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$property->id.'/daily-balance-evolution?'.http_build_query([
        'from' => $today->toDateString(),
        'to' => $today->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0]['value'])->toBe(50000000);
    expect($data['data'][0])->not->toHaveKey('display_value');
    expect($data['data'][0]['mortgage_balance'])->toBe((int) round(18000000 / 0.90));
    expect($data['data'][0])->not->toHaveKey('display_mortgage_balance');
    expect($data)->not->toHaveKey('display_currency_code');
});

test('account balance evolution exposes alternate user-currency mortgage values when property and user currencies differ', function () {
    $this->user->update(['currency_code' => 'USD']);

    $property = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::RealEstate,
        'currency_code' => 'EUR',
    ]);
    $loan = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
        'currency_code' => 'GBP',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $property->id,
        'linked_loan_account_id' => $loan->id,
    ]);

    $endOfMonth = now()->subMonthNoOverflow()->endOfMonth();

    AccountBalance::factory()->create([
        'account_id' => $property->id,
        'balance_date' => $endOfMonth,
        'balance' => 50000000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $loan->id,
        'balance_date' => $endOfMonth,
        'balance' => 18000000,
    ]);

    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => $endOfMonth->toDateString(),
        'rates' => ['gbp' => 0.80],
    ]);
    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $endOfMonth->toDateString(),
        'rates' => ['eur' => 0.90, 'gbp' => 0.72],
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$property->id.'/balance-evolution?'.http_build_query([
        'from' => $endOfMonth->copy()->startOfMonth()->toDateString(),
        'to' => $endOfMonth->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0]['value'])->toBe(50000000);
    expect($data['data'][0]['display_value'])->toBe((int) round(50000000 / 0.90));
    expect($data['data'][0]['mortgage_balance'])->toBe((int) round(18000000 / 0.80));
    expect($data['data'][0]['display_mortgage_balance'])->toBe((int) round(18000000 / 0.72));
    expect($data['display_currency_code'])->toBe('USD');
});

test('real estate balance evolution appends projected market value when revaluation percentage is set', function () {
    $property = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::RealEstate,
        'currency_code' => 'USD',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $property->id,
        'revaluation_percentage' => '6.00',
    ]);

    $endOfLastMonth = now()->subMonthNoOverflow()->endOfMonth();

    AccountBalance::factory()->create([
        'account_id' => $property->id,
        'balance_date' => $endOfLastMonth,
        'balance' => 50000000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$property->id.'/balance-evolution?'.http_build_query([
        'from' => $endOfLastMonth->copy()->startOfMonth()->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    $projected = collect($data['data'])->where('projected', true)->values();
    expect($projected)->toHaveCount(12);

    // Each future point should be greater than the prior (6% APR compounded monthly)
    $values = $projected->pluck('value')->all();
    for ($i = 1; $i < count($values); $i++) {
        expect($values[$i])->toBeGreaterThan($values[$i - 1]);
    }
});

test('real estate balance evolution appends projected mortgage balance from linked loan', function () {
    $property = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::RealEstate,
        'currency_code' => 'USD',
    ]);
    $loan = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
        'currency_code' => 'USD',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $property->id,
        'linked_loan_account_id' => $loan->id,
        'revaluation_percentage' => null,
    ]);

    LoanDetail::factory()->create([
        'account_id' => $loan->id,
        'original_amount' => 20000000,
        'annual_interest_rate' => '3.50',
        'loan_term_months' => 240,
        'start_date' => now()->subYear()->startOfMonth(),
    ]);

    $endOfLastMonth = now()->subMonthNoOverflow()->endOfMonth();

    AccountBalance::factory()->create([
        'account_id' => $property->id,
        'balance_date' => $endOfLastMonth,
        'balance' => 50000000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $loan->id,
        'balance_date' => $endOfLastMonth,
        'balance' => 18000000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$property->id.'/balance-evolution?'.http_build_query([
        'from' => $endOfLastMonth->copy()->startOfMonth()->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    $projected = collect($data['data'])->where('projected', true)->values();
    expect($projected)->toHaveCount(12);

    // No revaluation -> projected market value should equal last historical value
    expect($projected->first()['value'])->toBe(50000000);

    // Mortgage balance should decrease over time
    $mortgages = $projected->pluck('mortgage_balance')->filter()->values()->all();
    expect($mortgages)->not->toBeEmpty();
    for ($i = 1; $i < count($mortgages); $i++) {
        expect($mortgages[$i])->toBeLessThan($mortgages[$i - 1]);
    }
});
