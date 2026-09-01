<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
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
