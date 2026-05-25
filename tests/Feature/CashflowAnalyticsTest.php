<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('cashflow analytics responses are not cached between users', function () {
    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('cashflow summary returns income, expense, net, and savings rate', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Income: $1000
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 100000,
        'transaction_date' => now(),
    ]);

    // Expense: $400
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'amount' => -40000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => [
                'income' => 100000,
                'expense' => 40000,
                'net' => 60000,
                'savings_rate' => 60.0, // (1000 - 400) / 1000 * 100 = 60%
            ],
        ]);
});

test('cashflow analytics convert foreign currency transactions to user currency', function () {
    $date = now()->startOfMonth()->addDays(4);
    $from = $date->copy()->startOfMonth()->toDateString();
    $to = $date->copy()->endOfMonth()->toDateString();

    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    $usdAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'USD',
    ]);
    $eurAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $date->toDateString(),
        'rates' => ['eur' => 0.80],
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $usdAccount->id,
        'category_id' => $incomeCategory->id,
        'amount' => 10000,
        'currency_code' => 'USD',
        'transaction_date' => $date,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $eurAccount->id,
        'category_id' => $incomeCategory->id,
        'amount' => 8000,
        'currency_code' => 'EUR',
        'transaction_date' => $date,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $usdAccount->id,
        'category_id' => $expenseCategory->id,
        'amount' => -5000,
        'currency_code' => 'USD',
        'transaction_date' => $date,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $eurAccount->id,
        'category_id' => $expenseCategory->id,
        'amount' => -4000,
        'currency_code' => 'EUR',
        'transaction_date' => $date,
    ]);

    $summary = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => $from,
        'to' => $to,
    ]));
    $trend = $this->getJson('/api/cashflow/trend?'.http_build_query([
        'months' => 1,
        'to' => $to,
    ]));
    $sankey = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => $from,
        'to' => $to,
    ]));
    $incomeBreakdown = $this->getJson('/api/cashflow/breakdown?'.http_build_query([
        'from' => $from,
        'to' => $to,
        'type' => 'income',
    ]));
    $expenseBreakdown = $this->getJson('/api/cashflow/breakdown?'.http_build_query([
        'from' => $from,
        'to' => $to,
        'type' => 'expense',
    ]));

    $summary->assertOk()
        ->assertJsonPath('current.income', 20000)
        ->assertJsonPath('current.expense', 10000)
        ->assertJsonPath('current.net', 10000)
        ->assertJsonPath('current.savings_rate', 50);

    $trend->assertOk()
        ->assertJsonPath('data.0.income', 20000)
        ->assertJsonPath('data.0.expense', 10000)
        ->assertJsonPath('data.0.net', 10000);

    $sankey->assertOk()
        ->assertJsonPath('total_income', 20000)
        ->assertJsonPath('total_expense', 10000)
        ->assertJsonPath('income_categories.0.amount', 20000)
        ->assertJsonPath('expense_categories.0.amount', 10000);

    $incomeBreakdown->assertOk()
        ->assertJsonPath('total', 20000)
        ->assertJsonPath('data.0.amount', 20000)
        ->assertJsonPath('data.0.percentage', 100);

    $expenseBreakdown->assertOk()
        ->assertJsonPath('total', 10000)
        ->assertJsonPath('data.0.amount', 10000)
        ->assertJsonPath('data.0.percentage', 100);

    Http::assertNothingSent();
});

test('cashflow summary includes actual saved and invested amounts', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $savingsCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Savings,
    ]);
    $investmentCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Investment,
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 100000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'amount' => -40000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $savingsCategory->id,
        'amount' => -25000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $savingsCategory->id,
        'amount' => 5000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $investmentCategory->id,
        'amount' => -15000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJsonPath('current.income', 100000)
        ->assertJsonPath('current.expense', 40000)
        ->assertJsonPath('current.net', 60000)
        ->assertJsonPath('current.savings_rate', 60)
        ->assertJsonPath('current.savings', 25000)
        ->assertJsonPath('current.investments', 15000);
});

test('cashflow summary compares full quarter against previous full quarter', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 100000,
        'transaction_date' => '2025-04-15',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 30000,
        'transaction_date' => '2025-01-15',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 40000,
        'transaction_date' => '2025-02-15',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 50000,
        'transaction_date' => '2025-03-15',
    ]);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => '2025-04-01',
        'to' => '2025-06-30',
    ]));

    $response->assertOk()
        ->assertJsonPath('current.income', 100000)
        ->assertJsonPath('previous.income', 120000);
});

test('cashflow summary handles zero income for savings rate', function () {
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Only expense, no income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'amount' => -10000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => [
                'income' => 0,
                'expense' => 10000,
                'net' => -10000,
                'savings_rate' => 0, // Avoid division by zero
            ],
        ]);
});

test('cashflow summary returns previous period data', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Current period income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 50000,
        'transaction_date' => now(),
    ]);

    // Previous period income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 30000,
        'transaction_date' => now()->subMonthNoOverflow(),
    ]);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['current']['income'])->toBe(50000);
    expect($data['previous']['income'])->toBe(30000);
});

test('sankey returns income and expense categories with amounts', function () {
    $incomeCategory1 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);
    $incomeCategory2 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Freelance',
    ]);
    $expenseCategory1 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Housing',
    ]);
    $expenseCategory2 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Food',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Income transactions
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory1->id,
        'amount' => 500000, // $5000
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory2->id,
        'amount' => 100000, // $1000
        'transaction_date' => now(),
    ]);

    // Expense transactions
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory1->id,
        'amount' => -150000, // $1500
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory2->id,
        'amount' => -50000, // $500
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['income_categories', 'expense_categories', 'total_income', 'total_expense']);
    expect($data['total_income'])->toBe(600000);
    expect($data['total_expense'])->toBe(200000);
    expect($data['income_categories'])->toHaveCount(2);
    expect($data['expense_categories'])->toHaveCount(2);

    // Check that categories include required data
    $incomeIds = collect($data['income_categories'])->pluck('category.id')->toArray();
    expect($incomeIds)->toContain($incomeCategory1->id);
    expect($incomeIds)->toContain($incomeCategory2->id);
});

test('cashflow trend returns monthly data for specified months', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Create transactions for 3 months
    for ($i = 0; $i < 3; $i++) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $incomeCategory->id,
            'amount' => 100000 + ($i * 10000),
            'transaction_date' => now()->subMonthsNoOverflow($i),
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $expenseCategory->id,
            'amount' => -(50000 + ($i * 5000)),
            'transaction_date' => now()->subMonthsNoOverflow($i),
        ]);
    }

    $response = $this->getJson('/api/cashflow/trend?months=3');

    $response->assertOk();
    $data = $response->json();

    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['month', 'income', 'expense', 'net']);
});

test('cashflow trend does not include tracked transfers', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $investmentCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
        'name' => 'Investment',
    ]);
    $rebalanceCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Inflow,
        'name' => 'Investment Return Transfer',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 100000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'amount' => -40000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $investmentCategory->id,
        'amount' => -25000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $rebalanceCategory->id,
        'amount' => 15000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/trend?months=1');

    $response->assertOk();
    $point = $response->json('data.0');

    // Only income/expense categories affect the trend — transfers are excluded
    expect($point['income'])->toBe(100000);
    expect($point['expense'])->toBe(40000);
    expect($point['net'])->toBe(60000);
    expect($point)->not->toHaveKey('transfer_outflow');
    expect($point)->not->toHaveKey('transfer_inflow');
});

test('cashflow trend anchors the 12-month series to the requested period end month', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 32000,
        'transaction_date' => '2025-04-14',
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 48000,
        'transaction_date' => '2025-05-07',
    ]);

    $response = $this->getJson('/api/cashflow/trend?months=12&to=2025-05-31');

    $response->assertOk();

    $data = collect($response->json('data'))->keyBy('month');

    expect($data)->toHaveCount(12);
    expect($data->keys()->first())->toBe('2024-06');
    expect($data->keys()->last())->toBe('2025-05');
    expect($data['2025-04']['income'])->toBe(32000);
    expect($data['2025-05']['income'])->toBe(48000);
});

test('cashflow trend can use explicit period bounds', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 32000,
        'transaction_date' => '2025-04-14',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 48000,
        'transaction_date' => '2025-05-07',
    ]);

    $response = $this->getJson('/api/cashflow/trend?'.http_build_query([
        'from' => '2025-04-01',
        'to' => '2025-06-30',
    ]));

    $response->assertOk();

    $data = collect($response->json('data'))->keyBy('month');

    expect($data)->toHaveCount(3);
    expect($data->keys()->all())->toBe(['2025-04', '2025-05', '2025-06']);
    expect($data['2025-04']['income'])->toBe(32000);
    expect($data['2025-05']['income'])->toBe(48000);
});

test('cashflow trend defaults to 12 months', function () {
    $response = $this->getJson('/api/cashflow/trend');

    $response->assertOk();
    $data = $response->json();

    expect($data['data'])->toHaveCount(12);
});

test('breakdown returns category amounts with percentages for expenses', function () {
    $cat1 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Housing',
    ]);
    $cat2 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Food',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Housing: 60% of total
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $cat1->id,
        'amount' => -60000,
        'transaction_date' => now(),
    ]);

    // Food: 40% of total
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $cat2->id,
        'amount' => -40000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/breakdown?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
        'type' => 'expense',
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['data', 'total', 'previous_total']);
    expect($data['total'])->toBe(100000);
    expect($data['data'])->toHaveCount(2);

    // Should be sorted by amount desc (Housing first)
    expect($data['data'][0]['category']['name'])->toBe('Housing');
    expect($data['data'][0]['amount'])->toBe(60000);
    expect($data['data'][0]['percentage'])->toEqual(60.0);
    expect($data['data'][0])->toHaveKey('previous_amount');
});

test('breakdown returns category amounts for income', function () {
    $cat1 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);
    $cat2 = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Freelance',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $cat1->id,
        'amount' => 500000,
        'transaction_date' => now(),
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $cat2->id,
        'amount' => 100000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/breakdown?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
        'type' => 'income',
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total'])->toBe(600000);
    expect($data['data'])->toHaveCount(2);
    expect($data['data'][0]['category']['name'])->toBe('Salary');
});

test('breakdown requires type parameter', function () {
    $response = $this->getJson('/api/cashflow/breakdown?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertUnprocessable();
});

test('cashflow page is accessible', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get('/cashflow');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('cashflow/index'));
});

test('cashflow page returns categories with type', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);
    Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
        'name' => 'Food',
    ]);

    $response = $this->get('/cashflow');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('cashflow/index')
        ->has('categories', 2)
        ->has('categories.0.type')
        ->has('categories.0.cashflow_direction')
    );
});

test('cashflow summary includes uncategorized income', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Uncategorized positive amount = income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => 50000, // $500
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => [
                'income' => 50000,
                'expense' => 0,
                'net' => 50000,
            ],
        ]);
});

test('cashflow summary includes uncategorized expenses', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Uncategorized negative amount = expense
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -30000, // $300
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/summary?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk()
        ->assertJson([
            'current' => [
                'income' => 0,
                'expense' => 30000,
                'net' => -30000,
            ],
        ]);
});

test('sankey includes unknown income and expense categories', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Uncategorized income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => 100000,
        'transaction_date' => now(),
    ]);

    // Uncategorized expense
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -50000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['income_categories'])->toHaveCount(1);
    expect($data['expense_categories'])->toHaveCount(1);
    expect($data['income_categories'][0]['category']['name'])->toBe('Unknown Income');
    expect($data['income_categories'][0]['amount'])->toBe(100000);
    expect($data['expense_categories'][0]['category']['name'])->toBe('Unknown Expense');
    expect($data['expense_categories'][0]['amount'])->toBe(50000);
});

test('sankey income category with mixed positive and negative transactions shows only positive amounts', function () {
    // Reproduces a real-world scenario where an "Income from Rents" category contains
    // both income receipts and property-related expense payments. The Sankey should
    // show only the actual money received (positive flows), not abs(net).
    $incomeFromRents = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Income from Rents',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Mar 13: -€124.03 — BIZUM sent (Lavadora)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeFromRents->id,
        'amount' => -12403,
        'transaction_date' => '2026-03-13',
    ]);

    // Mar 10: +€38.04 — BIZUM received (luz febrero 2026)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeFromRents->id,
        'amount' => 3804,
        'transaction_date' => '2026-03-10',
    ]);

    // Mar 9: -€38.04 — Endesa energy payment
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeFromRents->id,
        'amount' => -3804,
        'transaction_date' => '2026-03-09',
    ]);

    // Mar 4: +€41.34 — BIZUM received (agua Febrero 2026)
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeFromRents->id,
        'amount' => 4134,
        'transaction_date' => '2026-03-04',
    ]);

    // Mar 2: -€105.92 — Comunidad de Propietarios
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeFromRents->id,
        'amount' => -10592,
        'transaction_date' => '2026-03-02',
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ]));

    $response->assertOk();
    $data = $response->json();

    // Positive flows only → income side: €38.04 + €41.34 = €79.38 (7938 cents)
    $rentIncome = collect($data['income_categories'])->firstWhere('category.name', 'Income from Rents');
    expect($rentIncome)->not->toBeNull();
    expect($rentIncome['amount'])->toBe(7938);
    expect($data['total_income'])->toBe(7938);

    // Negative flows → expense side: €124.03 + €38.04 + €105.92 = €267.99 (26799 cents)
    // The category appears on BOTH sides of the Sankey.
    $rentExpense = collect($data['expense_categories'])->firstWhere('category.name', 'Income from Rents');
    expect($rentExpense)->not->toBeNull();
    expect($rentExpense['amount'])->toBe(26799);
    expect($data['total_expense'])->toBe(26799);
});

test('sankey excludes hidden transfer categories from both sides', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Groceries',
    ]);
    $hiddenTransferCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
        'name' => 'Internal Transfer',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 200000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'amount' => -50000,
        'transaction_date' => now(),
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $hiddenTransferCategory->id,
        'amount' => 100000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $hiddenTransferCategory->id,
        'amount' => -75000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total_income'])->toBe(200000);
    expect($data['total_expense'])->toBe(50000);
    expect(collect($data['income_categories'])->pluck('category.name'))->not->toContain('Internal Transfer');
    expect(collect($data['expense_categories'])->pluck('category.name'))->not->toContain('Internal Transfer');
});

test('sankey includes outflow transfer categories on the expense side', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);
    $investmentCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
        'name' => 'Investments',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 300000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $investmentCategory->id,
        'amount' => -50000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total_income'])->toBe(300000);
    expect($data['total_expense'])->toBe(50000);

    $investmentExpense = collect($data['expense_categories'])->firstWhere('category.name', 'Investments');
    expect($investmentExpense)->not->toBeNull();
    expect($investmentExpense['amount'])->toBe(50000);

    // Outflow transfers should not appear on the income side
    expect(collect($data['income_categories'])->pluck('category.name'))->not->toContain('Investments');
});

test('sankey includes inflow transfer categories on the income side', function () {
    $expenseCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
        'name' => 'Groceries',
    ]);
    $inflowCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Inflow,
        'name' => 'From Relatives',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $inflowCategory->id,
        'amount' => 80000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $expenseCategory->id,
        'amount' => -20000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total_income'])->toBe(80000);
    expect($data['total_expense'])->toBe(20000);

    $inflowIncome = collect($data['income_categories'])->firstWhere('category.name', 'From Relatives');
    expect($inflowIncome)->not->toBeNull();
    expect($inflowIncome['amount'])->toBe(80000);

    // Inflow transfers should not appear on the expense side
    expect(collect($data['expense_categories'])->pluck('category.name'))->not->toContain('From Relatives');
});

test('sankey nets mixed-sign inflow transfer categories on the income side', function () {
    $inflowCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Inflow,
        'name' => 'From account of relatives',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $inflowCategory->id,
        'amount' => 300000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $inflowCategory->id,
        'amount' => -300000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total_income'])->toBe(0);
    expect($data['total_expense'])->toBe(0);
    expect(collect($data['income_categories'])->pluck('category.name'))->not->toContain('From account of relatives');
    expect(collect($data['expense_categories'])->pluck('category.name'))->not->toContain('From account of relatives');
});

test('sankey includes only the net positive amount for inflow transfer categories', function () {
    $inflowCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Inflow,
        'name' => 'From account of relatives',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $inflowCategory->id,
        'amount' => 500000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $inflowCategory->id,
        'amount' => -200000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total_income'])->toBe(300000);
    expect($data['total_expense'])->toBe(0);

    $inflowIncome = collect($data['income_categories'])->firstWhere('category.name', 'From account of relatives');
    expect($inflowIncome)->not->toBeNull();
    expect($inflowIncome['amount'])->toBe(300000);
    expect(collect($data['expense_categories'])->pluck('category.name'))->not->toContain('From account of relatives');
});

test('sankey includes only the net negative amount for outflow transfer categories', function () {
    $outflowCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
        'name' => 'Investments',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $outflowCategory->id,
        'amount' => -500000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $outflowCategory->id,
        'amount' => 200000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/sankey?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total_income'])->toBe(0);
    expect($data['total_expense'])->toBe(300000);

    $outflowExpense = collect($data['expense_categories'])->firstWhere('category.name', 'Investments');
    expect($outflowExpense)->not->toBeNull();
    expect($outflowExpense['amount'])->toBe(300000);
    expect(collect($data['income_categories'])->pluck('category.name'))->not->toContain('Investments');
});

test('breakdown includes unknown income category', function () {
    $incomeCategory = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Income,
        'name' => 'Salary',
    ]);

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    // Categorized income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $incomeCategory->id,
        'amount' => 200000,
        'transaction_date' => now(),
    ]);

    // Uncategorized income
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => 50000,
        'transaction_date' => now(),
    ]);

    $response = $this->getJson('/api/cashflow/breakdown?'.http_build_query([
        'from' => now()->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
        'type' => 'income',
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['total'])->toBe(250000);
    expect($data['data'])->toHaveCount(2);

    $unknownCategory = collect($data['data'])->firstWhere('category.name', 'Unknown Income');
    expect($unknownCategory)->not->toBeNull();
    expect($unknownCategory['amount'])->toBe(50000);
    expect($unknownCategory['percentage'])->toEqual(20.0); // 50k / 250k = 20%
});
