<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();

    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
    $this->savingsAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'type' => AccountType::Savings]);
    $this->actingAs($this->user);
});

function blockCategory(CategoryType $type, ?Category $parent = null): Category
{
    return Category::factory()->create(['user_id' => test()->user->id, 'type' => $type, 'parent_id' => $parent?->id]);
}

function blockBudget(Budget $budget, string $start = '2026-10-01', string $end = '2026-10-31'): Budget
{
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => $start, 'end_date' => $end, 'allocated_amount' => 50000]);

    return $budget;
}

function blockTransaction(?Category $category, int $amount, array $attributes = [], ?Label $label = null): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->checking->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'currency_code' => 'EUR',
        'transaction_date' => '2026-10-10',
        ...$attributes,
    ]);

    if ($label) {
        $transaction->labels()->attach($label);
    }

    return $transaction;
}

/**
 * @return array<int, array<string, mixed>>
 */
function transactionsInBlock(string $block, string $from = '2026-10-01', string $to = '2026-10-31'): array
{
    return test()->get(route('transactions.index', ['cashflow_block' => $block, 'date_from' => $from, 'date_to' => $to, 'per_page' => 100]))
        ->assertOk()
        ->original->getData()['page']['props']['transactions']['data'];
}

/**
 * @return list<string>
 */
function idsInBlock(string $block): array
{
    return collect(transactionsInBlock($block))->pluck('id')->sort()->values()->all();
}

/**
 * A month touching every rule the cashflow summary applies: tracked parents
 * and children, labels, a category tracked by both a fixed and a variable
 * budget, a catch-all budget, refunds, uncategorized rows, both legs of
 * savings and investment transfers, an archived account and rows outside the
 * range.
 *
 * @return array<string, Transaction>
 */
function seedCashflowMonth(): array
{
    $housing = blockCategory(CategoryType::Expense);
    $rent = blockCategory(CategoryType::Expense, $housing);
    $food = blockCategory(CategoryType::Expense);
    $restaurants = blockCategory(CategoryType::Expense, $food);
    $shopping = blockCategory(CategoryType::Expense);
    $salary = blockCategory(CategoryType::Income);
    $transfer = blockCategory(CategoryType::Transfer);
    $savings = blockCategory(CategoryType::Savings);
    $investments = blockCategory(CategoryType::Investment);
    $subscriptions = Label::factory()->create(['user_id' => test()->user->id]);
    $holidays = Label::factory()->create(['user_id' => test()->user->id]);
    $userId = test()->user->id;

    blockBudget(Budget::factory()->monthly()->fixed()->forCategories($housing)->create(['user_id' => $userId]));
    blockBudget(Budget::factory()->monthly()->fixed()->forLabels($subscriptions)->create(['user_id' => $userId]));
    blockBudget(Budget::factory()->monthly()->forCategories([$food, $housing])->create(['user_id' => $userId]));
    blockBudget(Budget::factory()->monthly()->forLabels($holidays)->create(['user_id' => $userId]));
    blockBudget(Budget::factory()->monthly()->catchAll()->create(['user_id' => $userId]));

    $archived = Account::factory()->create(['user_id' => $userId, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'archived_at' => '2026-10-05']);

    return [
        'rent' => blockTransaction($rent, -80000),
        'rent_refund' => blockTransaction($rent, 5000),
        'subscription' => blockTransaction($shopping, -1500, label: $subscriptions),
        'food' => blockTransaction($food, -10000),
        'restaurant' => blockTransaction($restaurants, -5000),
        'holiday_shopping' => blockTransaction($shopping, -3000, label: $holidays),
        'subscription_on_holiday' => blockTransaction($restaurants, -700, label: $subscriptions),
        'shopping' => blockTransaction($shopping, -4000),
        'shopping_refund' => blockTransaction($shopping, 1000),
        'uncategorized_out' => blockTransaction(null, -600),
        'uncategorized_in' => blockTransaction(null, 900),
        'salary' => blockTransaction($salary, 300000),
        'transfer' => blockTransaction($transfer, -20000),
        'to_savings' => blockTransaction($savings, -25000),
        'savings_leg' => blockTransaction($savings, 25000, ['account_id' => test()->savingsAccount->id]),
        'from_savings' => blockTransaction($savings, 4000),
        'to_investments' => blockTransaction($investments, -15000),
        'investment_leg' => blockTransaction($investments, 15000, ['account_id' => test()->savingsAccount->id]),
        'before_archive' => blockTransaction($shopping, -2000, ['account_id' => $archived->id, 'transaction_date' => '2026-10-02']),
        'after_archive' => blockTransaction($shopping, -2500, ['account_id' => $archived->id, 'transaction_date' => '2026-10-20']),
        'last_month' => blockTransaction($rent, -80000, ['transaction_date' => '2026-09-30']),
        'next_month' => blockTransaction($shopping, -900, ['transaction_date' => '2026-11-01']),
    ];
}

/**
 * @param  array<string, Transaction>  $seeded
 * @return list<string>
 */
function seededIds(array $seeded, string ...$names): array
{
    return collect($names)->map(fn (string $name): string => $seeded[$name]->id)->sort()->values()->all();
}

test('each block lists exactly the transactions the cashflow summary counts for it', function () {
    $seeded = seedCashflowMonth();

    expect(idsInBlock('fixed'))->toBe(seededIds($seeded, 'rent', 'rent_refund', 'subscription', 'subscription_on_holiday'))
        ->and(idsInBlock('variable'))->toBe(seededIds($seeded, 'food', 'restaurant', 'holiday_shopping'))
        ->and(idsInBlock('general'))->toBe(seededIds($seeded, 'shopping', 'shopping_refund', 'uncategorized_out', 'before_archive'))
        ->and(idsInBlock('saved'))->toBe(seededIds($seeded, 'to_savings', 'from_savings'))
        ->and(idsInBlock('invested'))->toBe(seededIds($seeded, 'to_investments'));
});

test('the sum of each block list equals its figure in the cashflow summary', function () {
    seedCashflowMonth();

    $summary = $this->getJson('/api/cashflow/summary?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->json('current');

    $listed = fn (string $block): int => -collect(transactionsInBlock($block))->sum('amount');

    expect($listed('fixed'))->toBe($summary['fixed_expense'])
        ->and($listed('variable'))->toBe($summary['variable_expense'])
        ->and($listed('general'))->toBe($summary['general_expense'])
        ->and($listed('saved'))->toBe($summary['savings'])
        ->and($listed('invested'))->toBe($summary['investments'])
        ->and($listed('fixed') + $listed('variable') + $listed('general'))->toBe($summary['expense']);
});

test('a budget whose period misses the range tracks nothing in it', function () {
    $gym = blockCategory(CategoryType::Expense);
    blockBudget(Budget::factory()->monthly()->fixed()->forCategories($gym)->create(['user_id' => $this->user->id]), '2026-09-01', '2026-09-30');
    $fee = blockTransaction($gym, -3000);

    expect(idsInBlock('fixed'))->toBe([])
        ->and(idsInBlock('general'))->toBe([$fee->id]);
});

test('the block combines with the other filters', function () {
    $seeded = seedCashflowMonth();

    $data = $this->get(route('transactions.index', [
        'cashflow_block' => 'fixed',
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-31',
        'amount_max' => -10,
    ]))->original->getData()['page']['props'];

    expect(collect($data['transactions']['data'])->pluck('id')->sort()->values()->all())
        ->toBe(seededIds($seeded, 'rent', 'subscription'))
        ->and($data['appliedFilters']['cashflow_block'])->toBe('fixed');
});

test('another user transactions never show up in a block', function () {
    $someoneElse = User::factory()->create();
    Transaction::factory()->create([
        'user_id' => $someoneElse->id,
        'account_id' => Account::factory()->create(['user_id' => $someoneElse->id]),
        'category_id' => null,
        'amount' => -5000,
        'transaction_date' => '2026-10-10',
    ]);

    expect(idsInBlock('general'))->toBe([]);
});

test('a cashflow block needs both dates and a known value', function (array $query, array $errors) {
    $this->get(route('transactions.index', $query))->assertSessionHasErrors($errors);
})->with([
    'no dates' => [['cashflow_block' => 'fixed'], ['date_from', 'date_to']],
    'no end date' => [['cashflow_block' => 'fixed', 'date_from' => '2026-10-01'], ['date_to']],
    'unknown block' => [['cashflow_block' => 'income', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'], ['cashflow_block']],
]);

test('a bulk update over a filtered block touches only that block', function () {
    $seeded = seedCashflowMonth();
    $note = 'Reviewed fixed costs';

    $this->patchJson(route('transactions.bulk-update'), [
        'filters' => ['cashflow_block' => 'fixed', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'],
        'notes' => $note,
    ])->assertOk()->assertJsonPath('count', 4);

    expect(Transaction::query()->where('notes', $note)->pluck('id')->sort()->values()->all())
        ->toBe(seededIds($seeded, 'rent', 'rent_refund', 'subscription', 'subscription_on_holiday'));
});

test('a bulk update rejects a block without dates', function () {
    $this->patchJson(route('transactions.bulk-update'), [
        'filters' => ['cashflow_block' => 'fixed'],
        'notes' => 'x',
    ])->assertUnprocessable()->assertJsonValidationErrors(['filters.date_from', 'filters.date_to']);
});
