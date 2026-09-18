<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The cashflow screen with two months of money behind it.
 *
 * Every figure the screen prints is worked out in the browser from four
 * endpoints, so the only place the whole chain is proven is here: the cards, the
 * period the reader is looking at, the breakdown a refund has to net into
 * (#987), and the sankey that draws it all.
 */
beforeEach(function (): void {
    config(['subscriptions.enabled' => false]);

    fakeCurrencyApi();
});

/**
 * One reader, two consecutive months, everything in their own currency.
 *
 * This month: salary $4,000 in, rent $1,500 and groceries $600 out, $180 of it
 * refunded, and $700 moved to savings. So income $4,000, expenses $1,920, net
 * $2,080, groceries $420 once the refund nets against it.
 *
 * Last month: salary $3,500 in and rent $1,200 out, so net $2,300.
 */
function seedCashflowReader(User $user): void
{
    $bank = Bank::factory()->create(['name' => 'Cashflow Bank']);

    $checking = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
        'name' => 'Main Checking',
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    $categories = [];

    foreach ([
        'Salary' => CategoryType::Income,
        'Rent' => CategoryType::Expense,
        'Groceries' => CategoryType::Expense,
        'To Savings' => CategoryType::Savings,
    ] as $name => $type) {
        $categories[$name] = Category::factory()->create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
        ]);
    }

    $book = function (string $category, int $amount, string $date) use ($user, $checking, $categories): void {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $checking->id,
            'category_id' => $categories[$category]->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'transaction_date' => $date,
        ]);
    };

    // Today's date, so the rows land in this month whichever day the suite runs.
    $thisMonth = now()->toDateString();
    // The first of last month, which is in the previous month on every day of
    // every month.
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->toDateString();

    $book('Salary', 400000, $thisMonth);
    $book('Rent', -150000, $thisMonth);
    $book('Groceries', -60000, $thisMonth);
    // The refund, booked to the category it came back from: it nets against
    // groceries rather than disappearing or crossing over to income (#987).
    $book('Groceries', 18000, $thisMonth);
    $book('To Savings', -70000, $thisMonth);

    $book('Salary', 350000, $lastMonth);
    $book('Rent', -120000, $lastMonth);
}

/**
 * The period is passed in the URL rather than left to the browser's clock, so a
 * run that straddles midnight on the 1st still looks at the month it seeded.
 */
function currentCashflowPeriod(): string
{
    return now()->format('Y-m');
}

it('shows this month\'s income, expenses and net, and what was set aside', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    seedCashflowReader($user);

    actingAs($user);

    $page = visit('/cashflow?period='.currentCashflowPeriod());

    $page->assertSee('Track your income, expenses, and savings')
        ->assertSee('Net Cashflow')
        ->assertSee('$2,080')
        ->assertSee('$4,000')
        ->assertSee('$1,920')
        // The transfer to savings, on its own card rather than counted as spending.
        ->assertSee('Saved & Invested')
        ->assertSee('$700')
        ->assertNoJavascriptErrors();
});

it('lists the categories behind the month and nets a refund into its own', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    seedCashflowReader($user);

    actingAs($user);

    $page = visit('/cashflow?period='.currentCashflowPeriod());

    $page->assertSee('Expense Categories')
        ->assertSee('Rent')
        ->assertSee('$1,500')
        // $600 spent and $180 back is a $420 row, not a $600 one and not a
        // category that vanished from the list.
        ->assertSee('Groceries')
        ->assertSee('$420')
        ->assertSee('Income Sources')
        ->assertSee('Salary')
        ->assertNoJavascriptErrors();
});

it('draws the money flow for the month', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    seedCashflowReader($user);

    actingAs($user);

    $page = visit('/cashflow?period='.currentCashflowPeriod());

    $page->assertSee('Money Flow')
        ->assertPresent('[data-testid="cashflow-sankey"] .recharts-surface')
        ->assertDontSee('No cashflow data for this period')
        ->assertNoJavascriptErrors();
});

it('walks back to the previous month and returns', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'USD']);
    seedCashflowReader($user);

    actingAs($user);

    $page = visit('/cashflow?period='.currentCashflowPeriod());

    $page->assertSee('$2,080')
        ->click('[aria-label="Previous period"]')
        // Last month: $3,500 in, $1,200 out.
        ->assertSee('$2,300')
        ->assertSee('$3,500')
        ->assertSee('$1,200')
        ->assertDontSee('$2,080')
        ->click('[aria-label="Next period"]')
        ->assertSee('$2,080')
        ->assertSee('$4,000')
        ->assertNoJavascriptErrors();
});
