<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/**
 * A EUR user with October 2026's income, expenses (one of them a refund), an
 * uncategorized outflow and a fixed rent budget with 50.00 left.
 *
 * @return array{user: User, account: Account, food: Category, groceries: Category, rent: Category}
 */
function analyticsBook(): array
{
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'name' => 'Main']);
    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income, 'name' => 'Salary']);
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries', 'parent_id' => $food->id]);
    $rent = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Rent']);

    foreach ([
        ['2026-09-01', $salary, 250_000], ['2026-09-03', $rent, -90_000],
        ['2026-10-01', $salary, 260_000], ['2026-10-02', $rent, -90_000], ['2026-10-04', $groceries, -12_345],
        ['2026-10-05', $food, -5_000], ['2026-10-06', $groceries, 2_000], ['2026-10-07', null, -777],
    ] as [$date, $category, $amount]) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'amount' => $amount,
            'currency_code' => 'EUR',
            'transaction_date' => $date,
        ]);
    }

    $budget = Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $user->id, 'name' => 'Rent', 'period_start_day' => 1]);
    $period = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 95_000, 'processing_historical' => false]);
    BudgetTransaction::factory()->create(['budget_period_id' => $period->id, 'amount' => 90_000]);

    return compact('user', 'account', 'food', 'groceries', 'rent');
}

const OCTOBER = '?from=2026-10-01&to=2026-10-31';

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
});

describe('cashflow', function () {
    it('reports the summary with the budget split, the sankey and the trend', function () {
        ['user' => $user, 'food' => $food, 'rent' => $rent] = analyticsBook();

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/cashflow'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.summary.current.income', 260_000)
            ->assertJsonPath('data.summary.current.expense', 106_122)
            ->assertJsonPath('data.summary.current.fixed_expense', 90_000)
            ->assertJsonPath('data.summary.current.variable_expense', 0)
            ->assertJsonPath('data.summary.current.general_expense', 16_122)
            ->assertJsonPath('data.summary.current.reserved', 5_000)
            ->assertJsonPath('data.summary.current.budgets.0.name', 'Rent')
            ->assertJsonPath('data.summary.current.budgets.0.expense_type', 'fixed')
            ->assertJsonPath('data.summary.current.budgets.0.margin', 5_000)
            ->assertJsonPath('data.summary.previous.income', 250_000)
            ->assertJsonPath('data.summary.previous.fixed_expense', 0)
            ->assertJsonMissingPath('data.summary.previous.reserved')
            ->assertJsonMissingPath('data.summary.previous.budgets')
            ->assertJsonPath('data.sankey.total_income', 260_000)
            ->assertJsonPath('data.sankey.total_expense', 106_122)
            ->assertJsonPath('data.trend', [['month' => '2026-10', 'income' => 260_000, 'expense' => 106_122, 'net' => 153_878]]);

        expect(collect($response->json('data.sankey.expense_categories'))->keyBy('name')->all())
            ->toMatchArray([
                'Food' => ['category_id' => $food->id, 'name' => 'Food', 'type' => 'expense', 'color' => $food->color, 'icon' => $food->icon, 'amount' => 15_345, 'has_children' => true, 'is_direct' => false],
                'Rent' => ['category_id' => $rent->id, 'name' => 'Rent', 'type' => 'expense', 'color' => $rent->color, 'icon' => $rent->icon, 'amount' => 90_000, 'has_children' => false, 'is_direct' => false],
                'Unknown Expense' => ['category_id' => null, 'name' => 'Unknown Expense', 'type' => 'expense', 'color' => 'gray', 'icon' => 'HelpCircle', 'amount' => 777, 'has_children' => false, 'is_direct' => false],
            ]);
    });

    it('reads the same figures as the cashflow screen', function () {
        ['user' => $user] = analyticsBook();

        $api = withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/cashflow'.OCTOBER)->json('data');
        app('auth')->forgetGuards();
        actingAs($user);

        expect($api['summary'])->toEqual(getJson('/api/cashflow/summary'.OCTOBER)->json())
            ->and($api['sankey']['total_expense'])->toBe(getJson('/api/cashflow/sankey'.OCTOBER)->json('total_expense'))
            ->and($api['trend'])->toBe(getJson('/api/cashflow/trend'.OCTOBER)->json('data'));
    });

    it('leaves out someone else\'s money', function () {
        analyticsBook();

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/analytics/cashflow'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.summary.current.income', 0)
            ->assertJsonPath('data.summary.current.budgets', [])
            ->assertJsonPath('data.sankey.expense_categories', []);
    });
});

describe('net worth', function () {
    it('reports the net worth against the previous period and each account\'s monthly balances', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $checking = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'name' => 'Main']);
        $broker = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD', 'type' => AccountType::Investment, 'name' => 'Broker']);
        ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-10-15', 'rates' => ['usd' => 1.25]]);
        AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-09-30', 'balance' => 100_000]);
        AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-10-10', 'balance' => 120_000]);
        AccountBalance::factory()->create(['account_id' => $broker->id, 'balance_date' => '2026-10-10', 'balance' => 50_000, 'invested_amount' => 40_000]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/net-worth?from=2026-09-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.granularity', 'monthly')
            ->assertJsonPath('data.net_worth', 160_000)
            ->assertJsonPath('data.accounts', [
                ['id' => $checking->id, 'name' => 'Main', 'type' => 'checking', 'currency' => 'EUR'],
                ['id' => $broker->id, 'name' => 'Broker', 'type' => 'investment', 'currency' => 'USD'],
            ])
            ->assertJsonPath('data.evolution.0.period', '2026-09')
            ->assertJsonPath('data.evolution.1.period', '2026-10');

        expect($response->json('data.evolution.1.balances'))->toBe([
            ['account_id' => $checking->id, 'balance' => 120_000, 'original_balance' => null, 'original_currency' => null, 'invested_amount' => null],
            ['account_id' => $broker->id, 'balance' => 40_000, 'original_balance' => 50_000, 'original_currency' => 'USD', 'invested_amount' => 40_000],
        ]);
    });

    it('reads one point per day with daily granularity', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/net-worth?from=2026-10-01&to=2026-10-03&granularity=daily')
            ->assertOk()
            ->assertJsonPath('data.granularity', 'daily')
            ->assertJsonCount(3, 'data.evolution')
            ->assertJsonPath('data.evolution.2.period', '2026-10-03');
    });

    it('never reads someone else\'s accounts', function () {
        Account::factory()->create(['user_id' => User::factory()->create()->id]);

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/analytics/net-worth'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.net_worth', 0)
            ->assertJsonPath('data.accounts', []);
    });
});

describe('spending by category', function () {
    it('rolls expenses up into top-level categories, and drills into one', function () {
        ['user' => $user, 'food' => $food, 'groceries' => $groceries, 'rent' => $rent] = analyticsBook();
        $headers = apiV1Bearer($user);

        $top = withHeaders($headers)
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR');

        expect(collect($top->json('data.categories'))->pluck('amount', 'category_id')->all())
            ->toEqualCanonicalizing([$food->id => 15_345, $rent->id => 90_000]);

        $drilled = withHeaders($headers)
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER.'&parent_category_id='.$food->id)
            ->assertOk();

        expect(collect($drilled->json('data.categories'))->map(fn (array $row): array => [$row['category_id'], $row['amount'], $row['is_direct']])->all())
            ->toEqualCanonicalizing([[$groceries->id, 10_345, false], [$food->id, 5_000, true]]);
    });

    it('refuses a parent category that is not the user\'s', function () {
        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER.'&parent_category_id='.Category::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_category_id.0', 'The parent_category_id is not one of your categories.');
    });
});

it('refuses a missing or reversed range on every analytics endpoint', function (string $endpoint, string $query, string $field) {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson("/api/v1/analytics/{$endpoint}{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['cashflow', 'net-worth', 'spending-by-category'])->with([
    'no from' => ['?to=2026-10-31', 'from'],
    'no to' => ['?from=2026-10-01', 'to'],
    'not a date' => ['?from=yesterday-ish&to=2026-10-31', 'from'],
    'to before from' => ['?from=2026-10-31&to=2026-10-01', 'to'],
]);

it('refuses an unknown net worth granularity', function () {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson('/api/v1/analytics/net-worth'.OCTOBER.'&granularity=weekly')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('granularity');
});

it('needs a bearer token', function (string $endpoint) {
    getJson("/api/v1/analytics/{$endpoint}".OCTOBER)->assertUnauthorized();
})->with(['cashflow', 'net-worth', 'spending-by-category']);
