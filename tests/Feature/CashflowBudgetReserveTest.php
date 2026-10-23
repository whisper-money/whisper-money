<?php

use App\Enums\BudgetPeriodType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();

    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());

    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->actingAs($this->user);
});

function budgetPeriodWithSpend(Budget $budget, string $start, string $end, int $allocated, int $spent): BudgetPeriod
{
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => $start,
        'end_date' => $end,
        'allocated_amount' => $allocated,
    ]);

    if ($spent !== 0) {
        BudgetTransaction::factory()->create(['budget_period_id' => $period->id, 'amount' => $spent]);
    }

    return $period;
}

function expenseOn(object $test, ?Category $category, int $amount, ?Label $label = null, string $date = '2026-10-10', array $attributes = []): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'transaction_date' => $date,
        ...$attributes,
    ]);

    if ($label) {
        $transaction->labels()->attach($label);
    }

    return $transaction;
}

function cashflowSummary(string $from = '2026-10-01', string $to = '2026-10-31'): array
{
    return test()->getJson('/api/cashflow/summary?'.http_build_query(['from' => $from, 'to' => $to]))
        ->assertOk()
        ->json('current');
}

test('reserved adds up the margins of open budget periods overlapping the range', function () {
    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $leisure = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'name' => 'Leisure']);

    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 15000);
    budgetPeriodWithSpend($leisure, '2026-10-01', '2026-10-31', 20000, 5000);

    $summary = cashflowSummary();

    expect($summary['reserved'])->toBe(25000 + 15000)
        ->and($summary['budgets'])->toHaveCount(2)
        ->and(collect($summary['budgets'])->firstWhere('name', 'Groceries'))->toMatchArray([
            'budget_id' => $groceries->id,
            'allocated' => 40000,
            'spent' => 15000,
            'margin' => 25000,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
});

test('an over-budget period contributes nothing to reserved but keeps its negative margin', function () {
    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id]);
    $leisure = Budget::factory()->monthly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 10000);
    budgetPeriodWithSpend($leisure, '2026-10-01', '2026-10-31', 20000, 26000);

    $summary = cashflowSummary();

    expect($summary['reserved'])->toBe(30000)
        ->and(collect($summary['budgets'])->firstWhere('budget_id', $leisure->id)['margin'])->toBe(-6000);
});

test('closed periods neither reserve money nor appear as rows', function () {
    $budget = Budget::factory()->monthly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($budget, '2026-09-01', '2026-09-30', 40000, 10000);

    $summary = cashflowSummary('2026-09-01', '2026-09-30');

    expect($summary['reserved'])->toBe(0)
        ->and($summary['budgets'])->toBe([]);
});

test('a period ending today is still open', function () {
    $budget = Budget::factory()->weekly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($budget, '2026-10-09', '2026-10-15', 10000, 4000);

    expect(cashflowSummary()['reserved'])->toBe(6000);
});

test('periods outside the range are ignored', function () {
    $budget = Budget::factory()->monthly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($budget, '2026-10-01', '2026-10-31', 40000, 0);

    $summary = cashflowSummary('2026-08-01', '2026-08-31');

    expect($summary['reserved'])->toBe(0)
        ->and($summary['budgets'])->toBe([]);
});

test('a period only partly inside the range still counts', function () {
    $budget = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'period_start_day' => 25]);

    budgetPeriodWithSpend($budget, '2026-09-25', '2026-10-24', 30000, 5000);

    expect(cashflowSummary()['reserved'])->toBe(25000);
});

test('archived budgets and other users budgets are left out', function () {
    $archived = Budget::factory()->monthly()->archived()->create(['user_id' => $this->user->id]);
    $someoneElses = Budget::factory()->monthly()->create(['user_id' => User::factory()]);

    budgetPeriodWithSpend($archived, '2026-10-01', '2026-10-31', 40000, 0);
    budgetPeriodWithSpend($someoneElses, '2026-10-01', '2026-10-31', 40000, 0);

    $summary = cashflowSummary();

    expect($summary['reserved'])->toBe(0)
        ->and($summary['budgets'])->toBe([]);
});

test('expenses on a tracked parent category, its children and tracked labels are variable', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'parent_id' => $food->id]);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $holidays = Label::factory()->create(['user_id' => $this->user->id]);

    $foodBudget = Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]);
    $holidayBudget = Budget::factory()->monthly()->forLabels($holidays)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create(['budget_id' => $foodBudget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 50000]);
    BudgetPeriod::factory()->create(['budget_id' => $holidayBudget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 50000]);

    expenseOn($this, $food, -10000);
    expenseOn($this, $restaurants, -5000);
    expenseOn($this, $rent, -80000);
    expenseOn($this, $rent, -3000, $holidays);

    $summary = cashflowSummary();

    expect($summary['expense'])->toBe(98000)
        ->and($summary['variable_expense'])->toBe(18000)
        ->and($summary['fixed_expense'])->toBe(80000);
});

test('variable and fixed expenses add up to the expense figure, refunds included', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $income = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]);

    $budget = Budget::factory()->monthly()->forCategories([$food, $income])->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 50000]);

    expenseOn($this, $food, -10000);
    expenseOn($this, $food, 2500);
    expenseOn($this, $rent, -80000);
    expenseOn($this, $income, 300000);
    expenseOn($this, $food, -7000, date: '2026-09-20');

    $summary = cashflowSummary();

    expect($summary['variable_expense'])->toBe(7500)
        ->and($summary['fixed_expense'])->toBe(80000)
        ->and($summary['variable_expense'] + $summary['fixed_expense'])->toBe($summary['expense']);
});

test('without budgets every expense is fixed', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    expenseOn($this, $rent, -80000);

    $summary = cashflowSummary();

    expect($summary['variable_expense'])->toBe(0)
        ->and($summary['fixed_expense'])->toBe(80000)
        ->and($summary['reserved'])->toBe(0);
});

test('catch-all and other users budgets do not make expenses variable', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    foreach ([
        Budget::factory()->monthly()->catchAll()->create(['user_id' => $this->user->id]),
        Budget::factory()->monthly()->forCategories($food)->create(['user_id' => User::factory()]),
    ] as $budget) {
        BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 50000]);
    }

    expenseOn($this, $food, -10000);

    expect(cashflowSummary()['variable_expense'])->toBe(0);
});

test('variable expenses are converted to the user currency like the expense total', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $budget = Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 50000]);

    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-10-10', 'rates' => ['usd' => 2.0]]);

    $usdAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'USD']);
    expenseOn($this, $food, -10000, attributes: ['account_id' => $usdAccount->id, 'currency_code' => 'USD']);

    $summary = cashflowSummary();

    expect($summary['variable_expense'])->toBe(5000)
        ->and($summary['expense'])->toBe(5000)
        ->and($summary['fixed_expense'])->toBe(0);
});

test('an archived budget keeps the spending it tracked variable', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $budget = Budget::factory()->monthly()->archived()->forCategories($food)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'allocated_amount' => 50000]);

    expenseOn($this, $food, -10000, date: '2026-09-10');

    expect(cashflowSummary('2026-09-01', '2026-09-30')['variable_expense'])->toBe(10000);
});

test('a catch-all budget still reserves its margin', function () {
    $budget = Budget::factory()->monthly()->catchAll()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($budget, '2026-10-01', '2026-10-31', 30000, 10000);

    expect(cashflowSummary()['reserved'])->toBe(20000);
});

test('a year range reserves only the yearly periods in progress, not future ones', function () {
    $current = Budget::factory()->yearly()->create(['user_id' => $this->user->id]);
    $future = Budget::factory()->yearly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($current, '2026-01-01', '2026-12-31', 120000, 40000);
    budgetPeriodWithSpend($future, '2026-11-01', '2027-10-31', 50000, 0);

    $summary = cashflowSummary('2026-01-01', '2026-12-31');

    expect($summary['reserved'])->toBe(80000)
        ->and($summary['budgets'])->toHaveCount(1)
        ->and($summary['budgets'][0]['budget_id'])->toBe($current->id);
});

test('a yearly budget is left out of the month and quarter ranges', function (string $from, string $to) {
    $subscriptions = Budget::factory()->yearly()->create(['user_id' => $this->user->id]);
    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($subscriptions, '2026-01-01', '2026-12-31', 60000, 22500);
    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 15000);

    $summary = cashflowSummary($from, $to);

    expect($summary['reserved'])->toBe(25000)
        ->and(collect($summary['budgets'])->pluck('budget_id')->all())->toBe([$groceries->id]);
})->with([
    'month' => ['2026-10-01', '2026-10-31'],
    'quarter' => ['2026-10-01', '2026-12-31'],
]);

test('weekly, bi-weekly and monthly budgets are left out of the year range', function () {
    $subscriptions = Budget::factory()->yearly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($subscriptions, '2026-01-01', '2026-12-31', 60000, 22500);

    foreach ([BudgetPeriodType::Monthly, BudgetPeriodType::Weekly, BudgetPeriodType::Biweekly] as $periodType) {
        $budget = Budget::factory()->create(['user_id' => $this->user->id, 'period_type' => $periodType]);
        budgetPeriodWithSpend($budget, '2026-10-09', '2026-10-15', 10000, 0);
    }

    $summary = cashflowSummary('2026-01-01', '2026-12-31');

    expect($summary['reserved'])->toBe(37500)
        ->and(collect($summary['budgets'])->pluck('budget_id')->all())->toBe([$subscriptions->id]);
});

test('a range reads as a year once it is longer than a quarter', function (string $from, string $to, bool $readsAsYear) {
    $subscriptions = Budget::factory()->yearly()->create(['user_id' => $this->user->id]);
    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($subscriptions, '2026-01-01', '2026-12-31', 60000, 0);
    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 0);

    $expected = $readsAsYear ? $subscriptions->id : $groceries->id;

    expect(collect(cashflowSummary($from, $to)['budgets'])->pluck('budget_id')->all())->toBe([$expected]);
})->with([
    '92 days' => ['2026-08-01', '2026-10-31', false],
    '92 days with a time on the end date' => ['2026-08-01', '2026-10-31 23:59:59', false],
    '93 days' => ['2026-07-31', '2026-10-31', true],
]);
