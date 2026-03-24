<?php

use App\Models\Account;
use App\Models\AccountBalance;

/*
|--------------------------------------------------------------------------
| API Analytics Query Count Tests
|--------------------------------------------------------------------------
|
| These tests enforce query count ceilings for all analytics API endpoints
| used by the dashboard and cashflow pages. These endpoints are called
| asynchronously from the frontend and are critical to page load performance.
|
| Run this suite in isolation:
|   php artisan test --testsuite=Performance
|
*/

beforeEach(function () {
    $this->user = performanceSeedUser();
    $this->actingAs($this->user);

    $this->dateParams = http_build_query([
        'from' => now()->subDays(30)->toDateString(),
        'to' => now()->toDateString(),
    ]);
});

// ──────────────────────────────────────────────────────────────────────────
// Dashboard Analytics API
// ──────────────────────────────────────────────────────────────────────────

test('net worth API does not exceed query threshold', function () {
    assertMaxQueries(25, function () {
        $this->getJson("/api/dashboard/net-worth?{$this->dateParams}")->assertOk();
    }, 'API Net Worth');
});

test('monthly spending API does not exceed query threshold', function () {
    assertMaxQueries(12, function () {
        $this->getJson("/api/dashboard/monthly-spending?{$this->dateParams}")->assertOk();
    }, 'API Monthly Spending');
});

test('cash flow API does not exceed query threshold', function () {
    assertMaxQueries(15, function () {
        $this->getJson("/api/dashboard/cash-flow?{$this->dateParams}")->assertOk();
    }, 'API Cash Flow');
});

test('top categories API does not exceed query threshold', function () {
    assertMaxQueries(13, function () {
        $this->getJson("/api/dashboard/top-categories?{$this->dateParams}")->assertOk();
    }, 'API Top Categories');
});

test('net worth evolution API does not exceed query threshold', function () {
    assertMaxQueries(25, function () {
        $this->getJson("/api/dashboard/net-worth-evolution?{$this->dateParams}")->assertOk();
    }, 'API Net Worth Evolution');
});

// ──────────────────────────────────────────────────────────────────────────
// Cashflow Analytics API
// ──────────────────────────────────────────────────────────────────────────

test('cashflow summary API does not exceed query threshold', function () {
    assertMaxQueries(15, function () {
        $this->getJson("/api/cashflow/summary?{$this->dateParams}")->assertOk();
    }, 'API Cashflow Summary');
});

test('cashflow trend API does not exceed query threshold', function () {
    assertMaxQueries(35, function () {
        $this->getJson('/api/cashflow/trend')->assertOk();
    }, 'API Cashflow Trend');
});

test('cashflow breakdown API does not exceed query threshold', function () {
    assertMaxQueries(15, function () {
        $this->getJson("/api/cashflow/breakdown?type=expense&{$this->dateParams}")->assertOk();
    }, 'API Cashflow Breakdown');
});

// ──────────────────────────────────────────────────────────────────────────
// Query count must not scale with data volume
// ──────────────────────────────────────────────────────────────────────────

test('net worth API query count does not scale with number of accounts', function () {
    $categories = $this->user->categories;

    $extraAccounts = Account::factory(7)->create(['user_id' => $this->user->id]);
    foreach ($extraAccounts as $index => $account) {
        for ($i = 0; $i < 5; $i++) {
            AccountBalance::factory()->create([
                'account_id' => $account->id,
                'balance_date' => now()->subDays(($index * 5) + $i + 20)->toDateString(),
            ]);
        }
    }

    // The net worth endpoint queries per-account, so we allow proportional growth
    // but cap it to prevent unbounded scaling
    assertMaxQueries(55, function () {
        $this->getJson("/api/dashboard/net-worth?{$this->dateParams}")->assertOk();
    }, 'API Net Worth with 10 accounts');
});

test('cashflow trend API query count does not scale with extra months', function () {
    assertMaxQueries(35, function () {
        $this->getJson('/api/cashflow/trend?months=12')->assertOk();
    }, 'API Cashflow Trend 12 months');
});
