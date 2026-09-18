<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Category;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The dashboard with a reader's data on it.
 *
 * The page went down twice in production through a render loop in the net worth
 * chart (PHP-LARAVEL-3B, and PHP-LARAVEL-47 on a phone), and twice more it
 * printed the wrong figure (WM-5D8C, WM-60CF). None of that shows up in a test
 * that only asserts the page title, so these drive the real chart with real
 * balances and move the pointer across it.
 */
beforeEach(function (): void {
    // The paywall is not what these assertions are about.
    config(['subscriptions.enabled' => false]);

    // Everything is seeded in the reader's own currency, so no rate is ever
    // fetched. The stub is the guard for that, not a fixture.
    fakeCurrencyApi();
});

/**
 * One reader with three accounts of different types, a fourth that was archived,
 * a quarter of balances behind them and three months of spending.
 *
 * Every figure is pinned. The dashboard prints, for this seed:
 * - net worth $12,500.00 — checking $2,500.00 plus savings $10,000.00. The loan
 *   is out because loans default off (#1012), and the archived account is worth
 *   nothing from the day it was archived (WM-60CF).
 * - this month: income $3,000, expenses $1,890, net $1,110 — the archived
 *   account's $500 of fees counts towards none of it.
 * - top category over the last 30 days: Rent, $1,200.
 *
 * @return array{checking: Account, savings: Account, loan: Account, archived: Account}
 */
function seedDashboardReader(User $user): array
{
    $bank = Bank::factory()->create(['name' => 'Dashboard Bank']);

    $accounts = [
        'checking' => ['Everyday Checking', AccountType::Checking, null, [150000, 200000, 220000, 240000, 250000]],
        'savings' => ['Rainy Day Savings', AccountType::Savings, null, [800000, 850000, 900000, 950000, 1000000]],
        'loan' => ['Car Loan', AccountType::Loan, null, [650000, 600000, 570000, 540000, 500000]],
        'archived' => ['Closed Account', AccountType::Checking, now()->subMonthsNoOverflow(2), [300000, 300000, 300000, 300000, 300000]],
    ];

    $created = [];

    foreach ($accounts as $key => [$name, $type, $archivedAt, $balances]) {
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'bank_id' => $bank->id,
            'name' => $name,
            'type' => $type,
            'currency_code' => 'USD',
            'archived_at' => $archivedAt,
        ]);

        // The oldest one sits before the chart's 12-month window, so every month
        // it draws carries a figure and the tooltip has something to say
        // wherever the pointer lands.
        foreach ([400, 90, 60, 30, 0] as $index => $daysAgo) {
            AccountBalance::factory()->create([
                'account_id' => $account->id,
                'balance_date' => now()->subDays($daysAgo)->toDateString(),
                'balance' => $balances[$index],
            ]);
        }

        $created[$key] = $account;
    }

    $categories = [];

    foreach ([
        'Rent' => CategoryType::Expense,
        'Groceries' => CategoryType::Expense,
        'Dining Out' => CategoryType::Expense,
        'Transport' => CategoryType::Expense,
        'Salary' => CategoryType::Income,
        'Savings Pot' => CategoryType::Savings,
        'Closed Account Fees' => CategoryType::Expense,
    ] as $name => $type) {
        $categories[$name] = Category::factory()->create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
        ]);
    }

    // Today, so every row lands in this month and inside the 30-day window the
    // top-categories card reads, whichever day of the month the suite runs on.
    foreach ([
        'Rent' => -120000,
        'Groceries' => -42000,
        'Dining Out' => -18000,
        'Transport' => -9000,
        'Salary' => 300000,
        'Savings Pot' => -50000,
    ] as $category => $amount) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $created['checking']->id,
            'category_id' => $categories[$category]->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'transaction_date' => now()->toDateString(),
        ]);
    }

    // Spending on the archived account, dated after the day it was archived. It
    // counts towards nothing — not this month's expenses, not the top
    // categories — which is the half of WM-60CF that balances do not cover.
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $created['archived']->id,
        'category_id' => $categories['Closed Account Fees']->id,
        'amount' => -50000,
        'currency_code' => 'USD',
        'transaction_date' => now()->toDateString(),
    ]);

    // The two months before, small enough that they cannot outgrow Rent even on
    // the one day of the month where the 30-day window reaches back into them.
    foreach ([1, 2] as $monthsAgo) {
        foreach (['Groceries' => -30000, 'Dining Out' => -12000, 'Salary' => 300000] as $category => $amount) {
            Transaction::factory()->plaintext()->create([
                'user_id' => $user->id,
                'account_id' => $created['checking']->id,
                'category_id' => $categories[$category]->id,
                'amount' => $amount,
                'currency_code' => 'USD',
                'transaction_date' => now()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->toDateString(),
            ]);
        }
    }

    return $created;
}

it('shows the seeded balances, spending and cashflow', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    seedDashboardReader($user);

    actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Overview of your financial health')
        // Net worth: the two assets, with the loan off by default and the
        // archived account worth nothing since the day it was archived.
        ->assertSee('Net Worth Evolution')
        ->assertSee('$12,500.00')
        // One card per live account, the loan included and counted as a debt.
        ->assertSee('Everyday Checking')
        ->assertSee('$2,500.00')
        ->assertSee('Rainy Day Savings')
        ->assertSee('$10,000.00')
        ->assertSee('Car Loan')
        ->assertSee('-$5,000.00')
        // The archived account keeps its history in the chart but is off the page.
        ->assertDontSee('Closed Account')
        // Top categories over the last 30 days, with nothing the archived
        // account spent among them.
        ->assertSee('Top spending categories')
        ->assertSee('Rent')
        ->assertSee('$1,200')
        ->assertDontSee('Closed Account Fees')
        // This month's cashflow.
        ->assertSee('This month\'s income and expenses')
        ->assertSee('$3,000')
        ->assertSee('$1,890')
        ->assertSee('$1,110')
        ->assertNoJavascriptErrors();
});

it('draws the net worth chart and stays quiet while the pointer crosses it', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    seedDashboardReader($user);

    actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Net Worth Evolution')
        ->assertPresent('[data-testid="net-worth-chart"] .recharts-surface')
        // The regression behind both outages: a pointer over the chart put
        // recharts and the tooltip into a render loop and took the page down. So
        // the pointer goes onto the chart, off it, and back on.
        ->hover('[data-testid="net-worth-chart"] .recharts-surface')
        // The tooltip's own total line, which nothing else on the page prints.
        ->assertSee('Total')
        ->assertSee('$12,500.00')
        ->hover('h2:has-text("Accounts")')
        ->hover('[data-testid="net-worth-chart"] .recharts-surface')
        ->assertSee('Total')
        ->assertNoJavascriptErrors();
});

it('draws the net worth chart on a phone-sized screen', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    seedDashboardReader($user);

    actingAs($user);

    // The second outage was only ever reproduced on a phone.
    $page = visit('/dashboard')->resize(390, 844);

    $page->assertSee('Net Worth Evolution')
        ->assertSee('$12,500.00')
        ->assertPresent('[data-testid="net-worth-chart"] .recharts-surface')
        ->hover('[data-testid="net-worth-chart"] .recharts-surface')
        ->assertSee('Total')
        ->assertSee('Everyday Checking')
        ->assertNoJavascriptErrors();
});

it('hides an account from the dashboard and brings it back', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    $accounts = seedDashboardReader($user);
    $savings = $accounts['savings'];

    actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Rainy Day Savings')
        ->click('[aria-label="Edit accounts"]')
        ->assertSee('Toggle visibility and drag to reorder.')
        ->click("[data-testid=\"account-visibility-toggle\"][data-account-id=\"{$savings->id}\"]")
        ->assertPresent("[data-testid=\"account-visibility-toggle\"][data-account-id=\"{$savings->id}\"][aria-pressed=\"false\"]")
        ->keys('[data-slot="dialog-content"]', 'Escape')
        ->assertDontSee('Rainy Day Savings')
        ->assertSee('Everyday Checking')
        ->assertNoJavascriptErrors();

    // The choice is the reader's, so it is kept rather than redrawn each visit.
    retry(20, fn () => expect($savings->fresh()->hidden_on_dashboard)->toBeTrue(), 100);

    $page->click('[aria-label="Edit accounts"]')
        ->assertSee('Toggle visibility and drag to reorder.')
        ->click("[data-testid=\"account-visibility-toggle\"][data-account-id=\"{$savings->id}\"]")
        ->keys('[data-slot="dialog-content"]', 'Escape')
        ->assertSee('Rainy Day Savings')
        ->assertNoJavascriptErrors();

    retry(20, fn () => expect($savings->fresh()->hidden_on_dashboard)->toBeFalse(), 100);
});

it('offers the monthly summary and puts it away once dismissed', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD', 'locale' => 'en']);
    seedDashboardReader($user);

    $period = now()->subMonthNoOverflow();

    $summary = MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'period' => $period->format('Y-m'),
    ]);

    actingAs($user);

    $page = visit('/dashboard');

    $notice = 'Your '.$period->locale('en')->isoFormat('MMMM').' summary is ready';

    $page->assertSee($notice)
        ->assertSee('Open it')
        ->click('[aria-label="Dismiss"]')
        ->assertDontSee($notice)
        ->assertNoJavascriptErrors();

    // Dismissal is stored on the summary, so it stays away on the next device
    // too (#920).
    retry(20, fn () => expect($summary->fresh()->dismissed_at)->not->toBeNull(), 100);
});
