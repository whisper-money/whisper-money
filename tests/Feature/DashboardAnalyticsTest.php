<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('net worth calculates assets minus liabilities', function () {
    // Assets
    $checking = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
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

    // Previous period expense
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'amount' => -3000, // -$30.00
        'transaction_date' => now()->subMonth(),
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
    expect($data[1]['category']['id'])->toBe($cat1->id);
    expect($data[1]['amount'])->toBe(3000);
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
        'currency_code' => 'EUR',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account1->id,
        'balance_date' => now()->subMonth()->endOfMonth(),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => now()->subMonth()->endOfMonth(),
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
        'from' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['data', 'accounts']);
    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['month', 'timestamp', $account1->id, $account2->id]);
    expect($data['accounts'])->toHaveKey($account1->id);
    expect($data['accounts'])->toHaveKey($account2->id);
    expect($data['accounts'][$account1->id]['name'])->toBe('Checking Account');
    expect($data['accounts'][$account1->id]['currency_code'])->toBe('USD');
    expect($data['accounts'][$account2->id]['name'])->toBe('Savings Account');
    expect($data['accounts'][$account2->id]['currency_code'])->toBe('EUR');
});

test('net worth evolution uses last balance of each month per account', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    $lastMonth = now()->subMonth();

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

test('net worth evolution returns account metadata', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::CreditCard,
        'name' => 'My Credit Card',
        'name_iv' => 'test_iv_1234567',
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
});
