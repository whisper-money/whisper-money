<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

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

test('net worth evolution returns account metadata including bank', function () {
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
