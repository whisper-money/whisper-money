<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;

/*
|--------------------------------------------------------------------------
| Page Query Count Tests
|--------------------------------------------------------------------------
|
| These tests enforce query count ceilings for all dashboard and settings
| pages. If a page exceeds its threshold, it means new queries were added
| (likely N+1 regressions or unnecessary eager loads).
|
| Each threshold includes a small buffer above the current count so that
| minor legitimate changes don't cause false failures, while N+1 issues
| (which typically add dozens of queries) are reliably caught.
|
| Run this suite in isolation:
|   php artisan test --testsuite=Performance
|
*/

beforeEach(function () {
    $this->user = performanceSeedUser();
    $this->actingAs($this->user);
});

// ──────────────────────────────────────────────────────────────────────────
// Main Sidebar Pages
// ──────────────────────────────────────────────────────────────────────────

test('dashboard page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('dashboard'))->assertOk();
    }, 'Dashboard');
});

test('accounts index page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('accounts.list'))->assertOk();
    }, 'Accounts Index');
});

test('account show page does not exceed query threshold', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => $this->user->currency_code,
        'type' => AccountType::Checking,
    ]);

    assertMaxQueries(20, function () use ($account) {
        $this->get(route('accounts.show', $account))->assertOk();
    }, 'Account Show');
});

test('transactions index page does not exceed query threshold', function () {
    assertMaxQueries(23, function () {
        $this->get(route('transactions.index'))->assertOk();
    }, 'Transactions Index');
});

test('budgets index page does not exceed query threshold', function () {
    assertMaxQueries(23, function () {
        $this->get(route('budgets.index'))->assertOk();
    }, 'Budgets Index');
});

test('budget show page does not exceed query threshold', function () {
    $budget = $this->user->budgets()->first();

    assertMaxQueries(24, function () use ($budget) {
        $this->get(route('budgets.show', $budget))->assertOk();
    }, 'Budget Show');
});

test('cashflow page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('cashflow'))->assertOk();
    }, 'Cashflow');
});

// ──────────────────────────────────────────────────────────────────────────
// Settings Pages
// ──────────────────────────────────────────────────────────────────────────

test('settings accounts page does not exceed query threshold', function () {
    assertMaxQueries(19, function () {
        $this->get(route('accounts.index'))->assertOk();
    }, 'Settings Accounts');
});

test('settings categories page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('categories.index'))->assertOk();
    }, 'Settings Categories');
});

test('settings labels page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('labels.index'))->assertOk();
    }, 'Settings Labels');
});

test('settings automation rules page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('automation-rules.index'))->assertOk();
    }, 'Settings Automation Rules');
});

test('settings account/profile page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('account.edit'))->assertOk();
    }, 'Settings Account/Profile');
});

test('settings appearance page does not exceed query threshold', function () {
    assertMaxQueries(17, function () {
        $this->get(route('appearance.edit'))->assertOk();
    }, 'Settings Appearance');
});

// ──────────────────────────────────────────────────────────────────────────
// Query count must not scale with data volume
// ──────────────────────────────────────────────────────────────────────────

test('dashboard query count does not scale with number of accounts', function () {
    // Add 7 more accounts (10 total) with transactions and balances
    $categories = $this->user->categories;

    $extraAccounts = Account::factory(7)->create(['user_id' => $this->user->id]);
    foreach ($extraAccounts as $index => $account) {
        Transaction::factory(10)->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $categories->random()->id,
        ]);

        for ($i = 0; $i < 5; $i++) {
            AccountBalance::factory()->create([
                'account_id' => $account->id,
                'balance_date' => now()->subDays(($index * 5) + $i + 20)->toDateString(),
            ]);
        }
    }

    // Same threshold as 3 accounts — query count must not grow with data
    assertMaxQueries(17, function () {
        $this->get(route('dashboard'))->assertOk();
    }, 'Dashboard with 10 accounts');
});

test('transactions page query count does not scale with number of transactions', function () {
    $account = $this->user->accounts()->first();
    $category = $this->user->categories()->first();

    // Add 90 more transactions (120 total)
    Transaction::factory(90)->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    // Same threshold — paginated queries should not scale
    assertMaxQueries(23, function () {
        $this->get(route('transactions.index'))->assertOk();
    }, 'Transactions with 120 records');
});
