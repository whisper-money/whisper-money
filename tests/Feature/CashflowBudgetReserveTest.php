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
use App\Models\TransactionSplit;
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

function budgetFor(Budget $budget): Budget
{
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 50000]);

    return $budget;
}

test('expenses on a tracked parent category, its children and tracked labels split by budget expense type', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'parent_id' => $food->id]);
    $housing = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense, 'parent_id' => $housing->id]);
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $holidays = Label::factory()->create(['user_id' => $this->user->id]);
    $subscriptions = Label::factory()->create(['user_id' => $this->user->id]);

    budgetFor(Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]));
    budgetFor(Budget::factory()->monthly()->forLabels($holidays)->create(['user_id' => $this->user->id]));
    budgetFor(Budget::factory()->monthly()->fixed()->forCategories($housing)->create(['user_id' => $this->user->id]));
    budgetFor(Budget::factory()->monthly()->fixed()->forLabels($subscriptions)->create(['user_id' => $this->user->id]));

    expenseOn($this, $food, -10000);
    expenseOn($this, $restaurants, -5000);
    expenseOn($this, $shopping, -3000, $holidays);
    expenseOn($this, $rent, -80000);
    expenseOn($this, $shopping, -1500, $subscriptions);
    expenseOn($this, $shopping, -4000);
    expenseOn($this, null, -600);

    $summary = cashflowSummary();

    expect($summary['expense'])->toBe(104100)
        ->and($summary['variable_expense'])->toBe(18000)
        ->and($summary['fixed_expense'])->toBe(81500)
        ->and($summary['general_expense'])->toBe(4600);
});

test('a transaction tracked by a fixed and a variable budget counts once, as fixed', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $mealPlan = Label::factory()->create(['user_id' => $this->user->id]);

    budgetFor(Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]));
    budgetFor(Budget::factory()->monthly()->fixed()->forLabels($mealPlan)->create(['user_id' => $this->user->id]));

    expenseOn($this, $food, -6000, $mealPlan);
    expenseOn($this, $food, -2000);

    $summary = cashflowSummary();

    expect($summary['fixed_expense'])->toBe(6000)
        ->and($summary['variable_expense'])->toBe(2000)
        ->and($summary['general_expense'])->toBe(0)
        ->and($summary['expense'])->toBe(8000);
});

test('fixed, variable and general expenses add up to the expense figure, refunds included', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $income = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]);

    budgetFor(Budget::factory()->monthly()->forCategories([$food, $income])->create(['user_id' => $this->user->id]));
    budgetFor(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]));

    expenseOn($this, $food, -10000);
    expenseOn($this, $food, 2500);
    expenseOn($this, $rent, -80000);
    expenseOn($this, $rent, 90000);
    expenseOn($this, $shopping, -4000);
    expenseOn($this, $income, 300000);
    expenseOn($this, $food, -7000, date: '2026-09-20');

    $summary = cashflowSummary();

    expect($summary['variable_expense'])->toBe(7500)
        ->and($summary['fixed_expense'])->toBe(-10000)
        ->and($summary['general_expense'])->toBe(4000)
        ->and($summary['fixed_expense'] + $summary['variable_expense'] + $summary['general_expense'])->toBe($summary['expense']);
});

test('without budgets every expense is general', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    expenseOn($this, $rent, -80000);

    $summary = cashflowSummary();

    expect($summary['variable_expense'])->toBe(0)
        ->and($summary['fixed_expense'])->toBe(0)
        ->and($summary['general_expense'])->toBe(80000)
        ->and($summary['reserved'])->toBe(0);
});

test('catch-all and other users budgets leave expenses general', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    foreach ([
        Budget::factory()->monthly()->catchAll()->create(['user_id' => $this->user->id]),
        Budget::factory()->monthly()->fixed()->catchAll()->create(['user_id' => $this->user->id]),
        Budget::factory()->monthly()->forCategories($food)->create(['user_id' => User::factory()]),
        Budget::factory()->monthly()->fixed()->forCategories($food)->create(['user_id' => User::factory()]),
    ] as $budget) {
        budgetFor($budget);
    }

    expenseOn($this, $food, -10000);

    $summary = cashflowSummary();

    expect($summary['variable_expense'])->toBe(0)
        ->and($summary['fixed_expense'])->toBe(0)
        ->and($summary['general_expense'])->toBe(10000);
});

test('budgeted expenses are converted to the user currency like the expense total', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    budgetFor(Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]));
    budgetFor(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]));

    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-10-10', 'rates' => ['usd' => 2.0]]);

    $usdAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'USD']);
    expenseOn($this, $food, -10000, attributes: ['account_id' => $usdAccount->id, 'currency_code' => 'USD']);
    expenseOn($this, $rent, -30000, attributes: ['account_id' => $usdAccount->id, 'currency_code' => 'USD']);

    $summary = cashflowSummary();

    expect($summary['variable_expense'])->toBe(5000)
        ->and($summary['fixed_expense'])->toBe(15000)
        ->and($summary['expense'])->toBe(20000)
        ->and($summary['general_expense'])->toBe(0);
});

test('an archived budget keeps the spending it tracked under its expense type', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    foreach ([
        Budget::factory()->monthly()->archived()->forCategories($food)->create(['user_id' => $this->user->id]),
        Budget::factory()->monthly()->archived()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]),
    ] as $budget) {
        BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'allocated_amount' => 50000]);
    }

    expenseOn($this, $food, -10000, date: '2026-09-10');
    expenseOn($this, $rent, -70000, date: '2026-09-10');

    $summary = cashflowSummary('2026-09-01', '2026-09-30');

    expect($summary['variable_expense'])->toBe(10000)
        ->and($summary['fixed_expense'])->toBe(70000)
        ->and($summary['general_expense'])->toBe(0);
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

test('a period with a one-off allocation reserves the one-off amount, not the regular one', function () {
    $budget = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'name' => 'Holidays']);

    $period = budgetPeriodWithSpend($budget, '2026-10-01', '2026-10-31', 40000, 15000);
    $period->assignOneOffAllocation(60000);

    expect($period->fresh()->regularAllocatedAmount())->toBe(40000);

    $summary = cashflowSummary();

    expect($summary['reserved'])->toBe(60000 - 15000)
        ->and($summary['budgets'])->toHaveCount(1)
        ->and($summary['budgets'][0])->toMatchArray([
            'budget_period_id' => $period->id,
            'allocated' => 60000,
            'spent' => 15000,
            'margin' => 45000,
        ]);
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

test('a quarterly budget is reserved in the quarter range only', function (string $from, string $to, bool $reserved) {
    $carCare = Budget::factory()->quarterly()->create(['user_id' => $this->user->id, 'name' => 'Car care']);

    budgetPeriodWithSpend($carCare, '2026-10-01', '2026-12-31', 90000, 30000);

    $summary = cashflowSummary($from, $to);

    expect($summary['reserved'])->toBe($reserved ? 60000 : 0)
        ->and(collect($summary['budgets'])->pluck('budget_id')->all())->toBe($reserved ? [$carCare->id] : []);
})->with([
    'month' => ['2026-10-01', '2026-10-31', false],
    'quarter' => ['2026-10-01', '2026-12-31', true],
    'year' => ['2026-01-01', '2026-12-31', false],
]);

test('a range reads as a quarter once it is longer than a month', function (string $from, string $to, bool $readsAsQuarter) {
    $carCare = Budget::factory()->quarterly()->create(['user_id' => $this->user->id]);
    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id]);

    budgetPeriodWithSpend($carCare, '2026-10-01', '2026-12-31', 90000, 0);
    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 0);

    // Monthly budgets count in both; quarterly ones only once the range is a quarter.
    $expected = $readsAsQuarter ? [$carCare->id, $groceries->id] : [$groceries->id];

    expect(collect(cashflowSummary($from, $to)['budgets'])->pluck('budget_id')->sort()->values()->all())
        ->toBe(collect($expected)->sort()->values()->all());
})->with([
    '31 days' => ['2026-10-01', '2026-10-31', false],
    '32 days' => ['2026-09-30', '2026-10-31', true],
]);

/**
 * A transaction on the test account split the old way, into lines with no
 * category of its own on the parent: one line per [category, amount].
 *
 * @param  list<array{0: Category, 1: int}>  $lines
 */
function splitTransactionOn(object $test, array $lines, ?Label $label = null, string $date = '2026-10-10'): Transaction
{
    $transaction = expenseOn($test, null, array_sum(array_column($lines, 1)), $label, $date);

    foreach (array_values($lines) as $position => [$category, $amount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'position' => $position,
        ]);
    }

    return $transaction;
}

test('a split line in a fixed budget category is fixed and its untracked line general', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    budgetFor(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]));

    splitTransactionOn($this, [[$rent, -60000], [$shopping, -4000]]);

    $summary = cashflowSummary();

    expect($summary['fixed_expense'])->toBe(60000)
        ->and($summary['variable_expense'])->toBe(0)
        ->and($summary['general_expense'])->toBe(4000)
        ->and($summary['fixed_expense'] + $summary['variable_expense'] + $summary['general_expense'])->toBe($summary['expense'])
        ->and($summary['expense'])->toBe(64000);
});

test('a split transaction carrying a variable budget label counts every expense line as variable, to the cent', function () {
    // A half-owned account: each line is weighed on its own, as the expense
    // total weighs it, so the parent's single rounding would leave a cent out.
    $this->account->update(['ownership_percentage' => 50]);
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $holidays = Label::factory()->create(['user_id' => $this->user->id]);

    budgetFor(Budget::factory()->monthly()->forLabels($holidays)->create(['user_id' => $this->user->id]));

    splitTransactionOn($this, [[$food, -1001], [$shopping, -1001], [$food, -999]], $holidays);

    $summary = cashflowSummary();

    expect($summary['expense'])->toBe(501 + 501 + 500)
        ->and($summary['variable_expense'])->toBe($summary['expense'])
        ->and($summary['fixed_expense'])->toBe(0)
        ->and($summary['general_expense'])->toBe(0);
});

test('a split line in an income category is not an expense, even under a tracked label', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $salary = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]);
    $flat = Label::factory()->create(['user_id' => $this->user->id]);

    budgetFor(Budget::factory()->monthly()->fixed()->forLabels($flat)->create(['user_id' => $this->user->id]));

    splitTransactionOn($this, [[$rent, -50000], [$salary, 20000]], $flat);

    $summary = cashflowSummary();

    expect($summary['expense'])->toBe(50000)
        ->and($summary['income'])->toBe(20000)
        ->and($summary['fixed_expense'])->toBe(50000)
        ->and($summary['variable_expense'])->toBe(0)
        ->and($summary['general_expense'])->toBe(0);
});

test('a split line reached by a fixed and a variable budget counts once, as fixed', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $move = Label::factory()->create(['user_id' => $this->user->id]);

    budgetFor(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]));
    budgetFor(Budget::factory()->monthly()->forLabels($move)->create(['user_id' => $this->user->id]));

    splitTransactionOn($this, [[$rent, -70000], [$shopping, -8000]], $move);

    $summary = cashflowSummary();

    expect($summary['fixed_expense'])->toBe(70000)
        ->and($summary['variable_expense'])->toBe(8000)
        ->and($summary['general_expense'])->toBe(0)
        ->and($summary['expense'])->toBe(78000);
});

test('each budget row carries its expense type', function () {
    $rent = Budget::factory()->monthly()->fixed()->create(['user_id' => $this->user->id, 'name' => 'Rent']);
    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    budgetPeriodWithSpend($rent, '2026-10-01', '2026-10-31', 90000, 90000);
    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 15000);

    $budgets = collect(cashflowSummary()['budgets']);

    expect($budgets->firstWhere('budget_id', $rent->id)['expense_type'])->toBe('fixed')
        ->and($budgets->firstWhere('budget_id', $groceries->id)['expense_type'])->toBe('variable');
});

test('the previous period carries the expense split but nothing reserved', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $other = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    foreach ([
        Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]),
        Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]),
    ] as $budget) {
        BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'allocated_amount' => 50000]);
    }

    expenseOn($this, $food, -10000, date: '2026-09-10');
    expenseOn($this, $rent, -70000, date: '2026-09-10');
    expenseOn($this, $other, -3000, date: '2026-09-12');

    $previous = $this->getJson('/api/cashflow/summary?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->json('previous');

    expect($previous['fixed_expense'])->toBe(70000)
        ->and($previous['variable_expense'])->toBe(10000)
        ->and($previous['general_expense'])->toBe(3000)
        ->and($previous['expense'])->toBe(83000)
        ->and($previous)->not->toHaveKeys(['reserved', 'budgets']);
});

test('the previous period reads a split transaction line by line', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    foreach ([
        Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]),
        Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]),
    ] as $budget) {
        BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'allocated_amount' => 50000]);
    }

    splitTransactionOn($this, [[$rent, -60000], [$food, -9000], [$shopping, -2500]], date: '2026-09-05');

    // Budgets tracking only categories never need the transactions' labels.
    $queries = countQueries(function () use (&$previous): void {
        $previous = $this->getJson('/api/cashflow/summary?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->json('previous');
    })['queries'];

    expect($previous['fixed_expense'])->toBe(60000)
        ->and($previous['variable_expense'])->toBe(9000)
        ->and($previous['general_expense'])->toBe(2500)
        ->and($previous['expense'])->toBe(71500)
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'pivot_transaction_id'))->count())->toBe(0);
});

test('each period splits by its own budgets, from the transactions the summary already loaded', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $trip = Label::factory()->create(['user_id' => $this->user->id]);

    // Fixed rent only from October; the trip label is tracked in both months.
    budgetPeriodWithSpend(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]), '2026-10-01', '2026-10-31', 80000, 0);
    $tripBudget = Budget::factory()->monthly()->forLabels($trip)->create(['user_id' => $this->user->id]);
    budgetPeriodWithSpend($tripBudget, '2026-09-01', '2026-09-30', 50000, 0);
    budgetPeriodWithSpend($tripBudget, '2026-10-01', '2026-10-31', 50000, 0);

    expenseOn($this, $rent, -70000, date: '2026-09-03');
    splitTransactionOn($this, [[$food, -4000], [$rent, -1000]], $trip, '2026-09-20');
    expenseOn($this, $rent, -70000, date: '2026-10-03');
    splitTransactionOn($this, [[$food, -6000], [$rent, -2000]], date: '2026-10-20');

    $queries = countQueries(function () use (&$response): void {
        $response = $this->getJson('/api/cashflow/summary?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();
    })['queries'];

    expect($response->json('current'))->toMatchArray(['fixed_expense' => 72000, 'variable_expense' => 0, 'general_expense' => 6000, 'expense' => 78000])
        ->and($response->json('previous'))->toMatchArray(['fixed_expense' => 0, 'variable_expense' => 5000, 'general_expense' => 70000, 'expense' => 75000])
        ->and(collect($queries)->filter(fn (string $sql): bool => str_starts_with($sql, 'select `transactions`.*'))->count())->toBe(1)
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'pivot_transaction_id'))->count())->toBe(1)
        ->and(collect($queries)->filter(fn (string $sql): bool => str_starts_with($sql, 'select `budgets`.*'))->count())->toBe(1);
});

test('reserved reads the periods in progress on the user\'s today', function () {
    // 1 November 00:30 UTC is still 31 October in Buenos Aires: October holds
    // its margin back, November has not started.
    $this->travelTo(now()->setDate(2026, 11, 1)->setTime(0, 30));
    $this->user->update(['timezone' => 'America/Argentina/Buenos_Aires']);

    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 15000);
    budgetPeriodWithSpend($groceries, '2026-11-01', '2026-11-30', 40000, 0);

    expect(cashflowSummary('2026-10-01', '2026-11-30')['reserved'])->toBe(25000);
});

test('reserved reads the owner\'s period east of UTC too', function () {
    // 31 October 12:00 UTC is already 1 November in Auckland: October gave
    // back what it left, November holds its whole margin.
    $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
    $this->user->update(['timezone' => 'Pacific/Auckland']);

    $groceries = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    budgetPeriodWithSpend($groceries, '2026-10-01', '2026-10-31', 40000, 15000);
    budgetPeriodWithSpend($groceries, '2026-11-01', '2026-11-30', 40000, 0);

    expect(cashflowSummary('2026-10-01', '2026-11-30')['reserved'])->toBe(40000);
});
