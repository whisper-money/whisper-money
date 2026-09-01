<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\Readiness;
use App\Services\MonthlySummary\SummaryBuilder;

/*
 * The figures a monthly summary freezes.
 *
 * The point of most of these is that the email cannot quote a number the user
 * cannot find in the app: the net worth row honours the same two chart toggles
 * the dashboard does, and the cashflow figures come from the same service the
 * cashflow screen reads.
 */

beforeEach(function (): void {
    // The whole feature is month-relative, so a run at 23:59 on the 31st must
    // not report a different month than the assertions assume.
    $this->freezeTime();
    $this->month = now()->subMonth()->startOfMonth();
});

function summaryUser(): User
{
    return User::factory()->onboarded()->create(['currency_code' => 'EUR', 'timezone' => 'Europe/Madrid']);
}

/**
 * A categorised transaction inside the given month.
 */
function monthlyTransaction(User $user, Account $account, int $amount, CategoryType $type, ?string $categoryName = null, ?Carbon\Carbon $month = null): Transaction
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'name' => $categoryName ?? fake()->unique()->word(),
    ]);

    return Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'currency_code' => 'EUR',
        'amount' => $amount,
        'transaction_date' => ($month ?? test()->month)->copy()->addDays(4),
    ]);
}

it('reports income, spending and the savings rate for the closed month', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);

    monthlyTransaction($user, $account, 385000, CategoryType::Income);
    monthlyTransaction($user, $account, -248195, CategoryType::Expense, 'Housing');

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['cashflow']['income'])->toBe(385000)
        ->and($payload['cashflow']['expense'])->toBe(248195)
        ->and($payload['cashflow']['net'])->toBe(136805)
        ->and($payload['cashflow']['savings_rate'])->toBe(35.5)
        ->and($payload['period'])->toBe($this->month->format('Y-m'))
        ->and($payload['currency'])->toBe('EUR');
});

it('leaves loans out of net worth when the user has turned them off', function (): void {
    $user = summaryUser();
    $end = $this->month->copy()->endOfMonth();

    $checking = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
    $loan = Account::factory()->loan()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

    AccountBalance::factory()->create(['account_id' => $checking->id, 'balance' => 500000, 'balance_date' => $end->toDateString()]);
    AccountBalance::factory()->create(['account_id' => $loan->id, 'balance' => 200000, 'balance_date' => $end->toDateString()]);

    $builder = app(SummaryBuilder::class);

    expect($builder->build($user, $this->month, complete: true)['net_worth']['current'])->toBe(300000);

    $user->setting()->updateOrCreate(['user_id' => $user->id], ['include_loans_in_net_worth_chart' => false]);

    expect($builder->build($user->fresh(), $this->month, complete: true)['net_worth']['current'])->toBe(500000);
});

it('counts the streak of months that closed in the black', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);

    // Three months in the black, and a fourth in the red before them: the streak
    // is what it stops at, not how far the history goes.
    foreach ([0, 1, 2] as $ago) {
        $month = $this->month->copy()->subMonths($ago);
        monthlyTransaction($user, $account, 300000, CategoryType::Income, null, $month);
        monthlyTransaction($user, $account, -100000, CategoryType::Expense, null, $month);
    }

    $red = $this->month->copy()->subMonths(3);
    monthlyTransaction($user, $account, 100000, CategoryType::Income, null, $red);
    monthlyTransaction($user, $account, -300000, CategoryType::Expense, null, $red);

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['streak_months'])->toBe(3);
});

it('names the three biggest categories and what they take of the month', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);

    monthlyTransaction($user, $account, -60000, CategoryType::Expense, 'Housing');
    monthlyTransaction($user, $account, -30000, CategoryType::Expense, 'Groceries');
    monthlyTransaction($user, $account, -10000, CategoryType::Expense, 'Restaurants');

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['categories']['total'])->toBe(100000)
        ->and($payload['categories']['top_share'])->toBe(100.0)
        ->and(array_column($payload['categories']['top'], 'name'))->toBe(['Housing', 'Groceries', 'Restaurants'])
        ->and($payload['categories']['top'][0]['share'])->toBe(60.0);
});

it('picks the biggest drop in money rather than in percent', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
    $previous = $this->month->copy()->subMonth();

    // A tiny category collapsing by 90% must not beat a big one falling by 20%:
    // in percent the small one always wins, and it always says nothing.
    $small = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Stamps']);
    $big = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Restaurants']);

    foreach ([[$small, -1000, -10000], [$big, -80000, -100000]] as [$category, $now, $before]) {
        foreach ([[$this->month, $now], [$previous, $before]] as [$month, $amount]) {
            Transaction::factory()->plaintext()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'category_id' => $category->id,
                'currency_code' => 'EUR',
                'amount' => $amount,
                'transaction_date' => $month->copy()->addDays(4),
            ]);
        }
    }

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['biggest_drop']['name'])->toBe('Restaurants');
});

it('knows a first month has nothing to compare against', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);

    monthlyTransaction($user, $account, 100000, CategoryType::Income);

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['has_history'])->toBeFalse()
        ->and($payload['biggest_drop'])->toBeNull()
        ->and(app(Readiness::class)->hasHistoryBefore($user, $this->month))->toBeFalse();
});

it('carries bank and account names for the analysis but no transaction descriptions', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'name' => 'Salary account', 'type' => AccountType::Checking]);

    monthlyTransaction($user, $account, -1000, CategoryType::Expense, 'Housing');

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect(implode(' | ', $payload['account_names']))->toContain('Salary account')
        ->and(json_encode($payload))->not->toContain('description');
});

it('hands the model amounts a person can read, not minor units', function (): void {
    // The payload stores 352000 for €3,520.00. A model has no way to know that
    // and will faithfully report a hundred times the amount, so what it receives
    // is already formatted.
    $user = summaryUser();
    $summary = MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    $writer = app(AnalysisWriter::class);
    $method = new ReflectionMethod($writer, 'payloadFor');
    $payload = json_decode($method->invoke($writer, $summary, 'es'), true);

    expect($payload['cashflow']['income'])->toBe("3.850,00\u{202F}€")
        ->and($payload['cashflow']['savings_rate'])->toBe("35,5\u{202F}%")
        ->and($payload['categories']['top'][0]['amount'])->toBe("890,00\u{202F}€")
        ->and($payload['budgets']['overspent'][0]['over_by'])->toBe("82,40\u{202F}€")
        ->and($payload['goal']['target'])->toBe("5.000,00\u{202F}€")
        // Counts are not money and must survive untouched.
        ->and($payload['budgets']['met'])->toBe(4)
        ->and($payload['todos']['uncategorised']['count'])->toBe(12);
});

it('counts a streak that runs past the twelve months the payload carries', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);

    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income, 'name' => 'Salary']);
    $rent = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Rent']);

    $book = function (Category $category, int $amount, Carbon\Carbon $month) use ($user, $account): void {
        Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'currency_code' => 'EUR',
            'amount' => $amount,
            'transaction_date' => $month->copy()->addDays(4),
        ]);
    };

    // Fifteen months in the black. The payload only carries twelve, so a streak
    // counted from it alone would stop at the edge of the window and report a
    // number the reader can disprove by scrolling their own history.
    foreach (range(0, 14) as $ago) {
        $month = $this->month->copy()->subMonths($ago);
        $book($salary, 300000, $month);
        $book($rent, -100000, $month);
    }

    $red = $this->month->copy()->subMonths(15);
    $book($salary, 100000, $red);
    $book($rent, -300000, $red);

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['streak_months'])->toBe(15);
});

it('counts budgets rather than budget periods', function (): void {
    $user = summaryUser();

    // A weekly budget has four or five periods inside a month. Counting those
    // told a reader with one budget that they had four, and named it four times.
    $budget = Budget::factory()->weekly()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    foreach (range(0, 3) as $week) {
        $start = $this->month->copy()->startOfMonth()->addWeeks($week);

        BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'start_date' => $start,
            'end_date' => $start->copy()->addDays(6),
            'allocated_amount' => 5000,
        ]);
    }

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['budgets']['total'])->toBe(1)
        ->and($payload['budgets']['met'])->toBe(1);
});

it('judges only the budget periods that closed inside the month', function (): void {
    $user = summaryUser();

    // A yearly period is still running when the month closes: it cannot be met
    // or missed yet, and its spend covers months this report is not about.
    $yearly = Budget::factory()->yearly()->create(['user_id' => $user->id, 'name' => 'Insurance']);
    BudgetPeriod::factory()->create([
        'budget_id' => $yearly->id,
        'start_date' => $this->month->copy()->startOfYear(),
        'end_date' => $this->month->copy()->endOfYear(),
        'allocated_amount' => 100000,
    ]);

    $monthly = Budget::factory()->monthly()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    BudgetPeriod::factory()->create([
        'budget_id' => $monthly->id,
        'start_date' => $this->month->copy()->startOfMonth(),
        'end_date' => $this->month->copy()->endOfMonth(),
        'allocated_amount' => 50000,
    ]);

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['budgets']['total'])->toBe(1);
});

it('converts investment balances into the reader currency before adding them up', function (): void {
    $user = summaryUser();
    $end = $this->month->copy()->endOfMonth();

    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => $end->toDateString(),
        'rates' => ['usd' => 2.0],
    ]);

    // Balances are stored in the account's own currency. Added up raw, a $200
    // gain on a dollar account reaches a euro reader as €200.
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD', 'type' => AccountType::Investment]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $end,
        'balance' => 120000,
        'invested_amount' => 100000,
    ]);

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    // $1,200 and $1,000 at 2 USD per EUR: €600 held against €500 paid in.
    expect($payload['invested']['value'])->toBe(60000)
        ->and($payload['invested']['contributed'])->toBe(50000)
        ->and($payload['invested']['gain'])->toBe(10000);
});

it('drops an archived account from net worth, as the dashboard chart does', function (): void {
    $user = summaryUser();
    $end = $this->month->copy()->endOfMonth();

    $open = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
    $archived = Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Checking,
        'archived_at' => $this->month->copy()->startOfMonth(),
    ]);

    foreach ([$open, $archived] as $account) {
        AccountBalance::factory()->create([
            'account_id' => $account->id,
            'balance_date' => $end,
            'balance' => 500000,
        ]);
    }

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['net_worth']['current'])->toBe(500000);
});

it('measures the top categories against everything spent, not just what was categorised', function (): void {
    $user = summaryUser();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);

    monthlyTransaction($user, $account, 100000, CategoryType::Income);
    monthlyTransaction($user, $account, -20000, CategoryType::Expense, 'Housing');

    // Spending the category service cannot see, because it joins `categories`.
    // The cashflow screen shows it as an "Unknown Expense" row and counts it, so
    // a share taken over the categorised sum alone reads far too high.
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'currency_code' => 'EUR',
        'amount' => -60000,
        'transaction_date' => $this->month->copy()->addDays(4),
    ]);

    $payload = app(SummaryBuilder::class)->build($user, $this->month, complete: true);

    expect($payload['categories']['total'])->toBe(80000)
        ->and($payload['categories']['top'][0]['share'])->toBe(25.0)
        ->and($payload['todos']['uncategorised']['count'])->toBe(1);
});

it('leaves an emptied account out of the investment figures', function (): void {
    $user = summaryUser();
    $end = $this->month->copy()->endOfMonth();

    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Investment]);

    // The invested amount stops being recorded once the money is moved out, and
    // BalanceLookup carries the last non-null one forward over the nulls. Read
    // against a zero balance it says the reader lost the lot.
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $this->month->copy()->startOfMonth(),
        'balance' => 500000,
        'invested_amount' => 450000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $this->month->copy()->addDays(10),
        'balance' => 0,
        'invested_amount' => null,
    ]);

    expect(app(SummaryBuilder::class)->build($user, $this->month, complete: true)['invested'])->toBeNull();
});
