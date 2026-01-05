<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
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
            'transaction_date' => now()->subMonths($i),
        ]);
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $expenseCategory->id,
            'amount' => -(50000 + ($i * 5000)),
            'transaction_date' => now()->subMonths($i),
        ]);
    }

    $response = $this->getJson('/api/cashflow/trend?months=3');

    $response->assertOk();
    $data = $response->json();

    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['month', 'income', 'expense', 'net']);
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
