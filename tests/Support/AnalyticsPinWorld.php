<?php

namespace Tests\Support;

use App\Enums\AccountType;
use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\SpendingByCategory;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse as McpTestResponse;

/**
 * The invented household of tests/Feature/AnalyticsOutputPinTest.php and the
 * answers it pins, shared with the tests that must leave those answers as
 * they are.
 */
final class AnalyticsPinWorld
{
    public const GOLDEN = __DIR__.'/../Fixtures/analytics-output-pin.json';

    /**
     * @return array{user: User, partner: User, spaces: array<string, string>, categories: array<string, Category>}
     */
    public static function build(): array
    {
        $counter = 0;
        $id = function () use (&$counter): string {
            return sprintf('00000000-0000-4000-8000-%012d', ++$counter);
        };

        $user = User::factory()->onboarded()->create(['id' => $id(), 'currency_code' => 'EUR']);
        $partner = User::factory()->onboarded()->create(['id' => $id(), 'currency_code' => 'EUR']);
        $personal = $user->personalSpace()->firstOrFail();
        $household = Space::factory()->create(['id' => $id(), 'owner_id' => $user->id, 'name' => 'Household']);
        $household->members()->attach($partner->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

        foreach ([
            '2026-01-15' => ['usd' => 1.0912, 'gbp' => 0.8433],
            '2026-01-31' => ['usd' => 1.0897, 'gbp' => 0.8421],
            '2026-02-03' => ['usd' => 1.0791, 'gbp' => 0.8402],
            '2026-02-28' => ['usd' => 1.0805, 'gbp' => 0.8366],
            '2026-03-04' => ['usd' => 1.0837, 'gbp' => 0.8571],
            '2026-03-10' => ['usd' => 1.0866, 'gbp' => 0.8590],
            '2026-03-16' => ['usd' => 1.0873, 'gbp' => 0.8601],
            '2026-03-18' => ['usd' => 1.0881, 'gbp' => 0.8607],
        ] as $date => $rates) {
            ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => $date, 'rates' => $rates]);
        }

        $bank = Bank::factory()->create(['id' => $id(), 'name' => 'Pin Bank', 'logo' => 'pin-bank.png']);

        $account = fn (string $name, AccountType $type, string $currency = 'EUR', array $attributes = []): Account => Account::factory()->create([
            'id' => $id(),
            'user_id' => $user->id,
            'space_id' => $personal->id,
            'name' => $name,
            'type' => $type,
            'currency_code' => $currency,
            'bank_id' => $bank->id,
            ...$attributes,
        ]);

        $checking = $account('Checking', AccountType::Checking);
        $dollars = $account('Dollars', AccountType::Checking, 'USD');
        $savings = $account('Savings', AccountType::Savings);
        $brokerage = $account('Brokerage', AccountType::Investment, 'USD');
        $mortgage = $account('Mortgage', AccountType::Loan);
        $flat = $account('Flat', AccountType::RealEstate);
        $card = $account('Card', AccountType::CreditCard);
        // Switched off in net worth, one account at a time.
        $leftOut = $account('Left out', AccountType::Checking, attributes: ['include_in_net_worth' => false]);
        $hidden = $account('Hidden on the dashboard', AccountType::Checking, attributes: ['hidden_on_dashboard' => true]);
        $archived = $account('Archived', AccountType::Checking, attributes: ['archived_at' => '2026-03-05 10:00:00']);
        $encrypted = $account('c2VjcmV0', AccountType::Checking, attributes: ['name_iv' => 'aXZpdml2aXZpdml2', 'encrypted' => true]);
        $trashed = $account('Trashed', AccountType::Checking);
        $third = $account('Shared at a third', AccountType::Checking, attributes: ['ownership_percentage' => 33]);
        $joint = $account('Joint', AccountType::Checking, attributes: ['space_id' => $household->id]);
        $partnerAccount = $account('Partner checking', AccountType::Checking, attributes: ['user_id' => $partner->id, 'space_id' => $household->id]);

        foreach ([
            [$checking, ['2026-01-01' => 100000, '2026-02-15' => 120000, '2026-03-10' => 130000]],
            [$dollars, ['2026-01-01' => 50000, '2026-03-04' => 75000]],
            [$mortgage, ['2026-01-01' => 900000, '2026-02-01' => 895000, '2026-03-01' => 890000]],
            [$flat, ['2026-01-01' => 2000000]],
            [$leftOut, ['2026-01-01' => 777777]],
            [$hidden, ['2026-01-01' => 11111]],
            [$archived, ['2026-01-01' => 22222]],
            [$encrypted, ['2026-01-01' => 33333]],
            [$trashed, ['2026-01-01' => 44444]],
            [$third, ['2026-01-01' => 66666]],
            [$joint, ['2026-02-01' => 5000]],
            [$partnerAccount, ['2026-01-01' => 888888]],
        ] as [$owner, $balances]) {
            foreach ($balances as $date => $balance) {
                AccountBalance::factory()->create(['id' => $id(), 'account_id' => $owner->id, 'balance_date' => $date, 'balance' => $balance]);
            }
        }

        foreach ([
            [$savings, ['2026-01-01' => [300000, 250000], '2026-03-01' => [310000, 255000]]],
            [$brokerage, ['2026-01-01' => [400000, 350000], '2026-02-20' => [420000, 355000]]],
        ] as [$owner, $balances]) {
            foreach ($balances as $date => [$balance, $invested]) {
                AccountBalance::factory()->create(['id' => $id(), 'account_id' => $owner->id, 'balance_date' => $date, 'balance' => $balance, 'invested_amount' => $invested]);
            }
        }

        $category = function (string $name, CategoryType $type, ?Category $parent = null, CategoryCashflowDirection $direction = CategoryCashflowDirection::Hidden, ?User $owner = null, ?Space $space = null) use ($id, $user, $personal): Category {
            fake()->unique(true);

            return Category::factory()->create([
                'id' => $id(),
                'user_id' => ($owner ?? $user)->id,
                'space_id' => ($space ?? $personal)->id,
                'name' => $name,
                'icon' => 'Tag',
                'color' => 'blue',
                'type' => $parent->type ?? $type,
                'cashflow_direction' => $parent->cashflow_direction ?? $direction,
                'parent_id' => $parent?->id,
            ]);
        };

        $salary = $category('Salary', CategoryType::Income);
        $gifts = $category('Gifts', CategoryType::Income);
        $home = $category('Home', CategoryType::Expense);
        $rent = $category('Rent', CategoryType::Expense, $home);
        $food = $category('Food', CategoryType::Expense);
        $groceries = $category('Groceries', CategoryType::Expense, $food);
        $dining = $category('Dining', CategoryType::Expense, $food);
        $fun = $category('Fun', CategoryType::Expense);
        $fund = $category('Emergency fund', CategoryType::Savings, direction: CategoryCashflowDirection::Outflow);
        $index = $category('Index funds', CategoryType::Investment, direction: CategoryCashflowDirection::Outflow);
        $moveIn = $category('Transfer in', CategoryType::Transfer, direction: CategoryCashflowDirection::Inflow);
        $partnerFood = $category('Partner food', CategoryType::Expense, owner: $partner, space: $household);

        $holiday = Label::factory()->create(['id' => $id(), 'user_id' => $user->id, 'space_id' => $personal->id, 'name' => 'Holiday', 'color' => 'teal']);

        /**
         * @param  list<array{0: Category, 1: int}>  $lines
         * @param  list<Label>  $labels
         */
        $transaction = function (Account $on, ?Category $in, int $amount, string $date, array $lines = [], array $labels = [], ?string $currency = null) use ($id): Transaction {
            $transaction = Transaction::factory()->plaintext()->create([
                'id' => $id(),
                'user_id' => $on->user_id,
                'account_id' => $on->id,
                'category_id' => $in?->id,
                'description' => 'Pinned',
                'amount' => $amount,
                'currency_code' => $currency ?? $on->currency_code,
                'transaction_date' => $date,
            ]);

            foreach ($lines as $position => [$lineCategory, $lineAmount]) {
                TransactionSplit::factory()->create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $lineCategory->id,
                    'amount' => $lineAmount,
                    'position' => $position,
                ]);
            }

            foreach ($labels as $label) {
                $transaction->labels()->attach($label);
            }

            return $transaction;
        };

        $transaction($checking, $salary, 250000, '2026-01-15');
        $transaction($checking, $rent, -95000, '2026-01-15');
        $transaction($checking, $salary, 250000, '2026-02-03');
        $febRent = $transaction($checking, $rent, -95000, '2026-02-03');
        $transaction($dollars, $fun, -5099, '2026-02-03');
        $transaction($checking, $gifts, -2500, '2026-02-20');
        $transaction($checking, null, -1234, '2026-02-20');
        $transaction($checking, null, 777, '2026-02-20');
        $transaction($checking, $salary, 260000, '2026-03-02');
        $marSplit = $transaction($checking, null, -40000, '2026-03-02', lines: [[$rent, -50000], [$salary, 10000]]);
        $marFood = $transaction($checking, null, -30000, '2026-03-03', lines: [[$groceries, -10000], [$fund, -20000]], labels: [$holiday]);
        $transaction($dollars, $dining, -10001, '2026-03-04', lines: [[$dining, -3333], [$fun, -3333], [$groceries, -3333]]);
        $transaction($third, $fun, -2999, '2026-03-07');
        $transaction($archived, $groceries, -1111, '2026-03-04');
        $transaction($archived, $groceries, -3333, '2026-03-12');
        $transaction($trashed, $fun, -2000, '2026-03-12');
        $transaction($checking, $fun, 6500, '2026-03-08');
        $transaction($checking, $groceries, 25000, '2026-03-13');
        $transaction($checking, null, -2345, '2026-03-10');
        $transaction($dollars, null, 1111, '2026-03-10');
        $transaction($checking, $moveIn, 30000, '2026-03-10');
        $transaction($checking, $fund, -25000, '2026-03-11');
        $transaction($savings, $fund, 25000, '2026-03-11');
        $transaction($checking, $index, -40000, '2026-03-11');
        $transaction($brokerage, $index, 43474, '2026-03-11');
        $transaction($joint, $groceries, -6000, '2026-03-13');
        $transaction($partnerAccount, $partnerFood, -4567, '2026-03-13');
        $transaction($partnerAccount, $category('Partner pay', CategoryType::Income, owner: $partner, space: $household), 180000, '2026-03-01');

        $budget = function (string $name, BudgetPeriodType $type, BudgetExpenseType $expenseType, array $categories = [], array $labels = [], bool $catchAll = false) use ($id, $user, $personal): Budget {
            $budget = Budget::factory()->create([
                'id' => $id(),
                'user_id' => $user->id,
                'space_id' => $personal->id,
                'name' => $name,
                'period_type' => $type,
                'period_start_day' => 1,
                'expense_type' => $expenseType,
                'is_catch_all' => $catchAll,
            ]);
            $budget->categories()->sync(array_map(fn (Category $tracked): string => $tracked->id, $categories));
            $budget->labels()->sync(array_map(fn (Label $tracked): string => $tracked->id, $labels));

            return $budget;
        };
        $period = function (Budget $budget, string $start, string $end, int $allocated, ?Transaction $spentOn = null, int $spent = 0) use ($id): void {
            $period = BudgetPeriod::factory()->create([
                'id' => $id(),
                'budget_id' => $budget->id,
                'start_date' => $start,
                'end_date' => $end,
                'allocated_amount' => $allocated,
                'carried_over_amount' => 0,
            ]);

            if ($spentOn !== null) {
                BudgetTransaction::factory()->create(['transaction_id' => $spentOn->id, 'budget_period_id' => $period->id, 'amount' => $spent]);
            }
        };

        $housing = $budget('Housing', BudgetPeriodType::Monthly, BudgetExpenseType::Fixed, categories: [$home]);
        $period($housing, '2026-02-01', '2026-02-28', 100000, $febRent, 95000);
        $period($housing, '2026-03-01', '2026-03-31', 100000, $marSplit, 50000);
        $groceryBudget = $budget('Food budget', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, categories: [$food]);
        $period($groceryBudget, '2026-03-01', '2026-03-31', 8000, $marFood, 10000);
        $holidays = $budget('Holidays', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, labels: [$holiday]);
        $period($holidays, '2026-03-01', '2026-03-31', 30000, $marFood, 10000);
        $catchAll = $budget('Everything else', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, catchAll: true);
        $period($catchAll, '2026-03-01', '2026-03-31', 20000);
        $yearly = $budget('Yearly fun', BudgetPeriodType::Yearly, BudgetExpenseType::Variable, categories: [$fun]);
        $period($yearly, '2026-01-01', '2026-12-31', 60000);

        $trashed->delete();

        return [
            'user' => $user,
            'partner' => $partner,
            'spaces' => [
                $personal->id => 'space:personal',
                $partner->personalSpace()->firstOrFail()->id => 'space:partner',
            ],
            'categories' => ['home' => $home, 'food' => $food],
        ];
    }

    /**
     * Every answer for the household, as the bytes it went out with, keyed by a
     * name saying which call it is.
     *
     * @param  array{user: User, partner: User, spaces: array<string, string>, categories: array<string, Category>}  $world
     * @return array<string, string>
     */
    public static function outputs(array $world): array
    {
        $user = $world['user'];
        test()->actingAs($user);

        $web = fn (string $path, array $query): string => (string) test()
            ->getJson("/api/{$path}?".http_build_query($query))
            ->assertOk()
            ->getContent();
        if (! McpTestResponse::hasMacro('rawMcpText')) {
            McpTestResponse::macro('rawMcpText', function (): string {
                /** @var McpTestResponse $this */
                // @phpstan-ignore-next-line — a macro closure is bound to the instance.
                return $this->content()[0] ?? '';
            });
        }

        // @phpstan-ignore-next-line — the macro added above.
        $tool = fn (string $class, array $arguments): string => WhisperMoneyServer::actingAs($user)->tool($class, $arguments)->assertOk()->rawMcpText();

        $march = ['from' => '2026-03-01', 'to' => '2026-03-31'];
        $firstDays = ['from' => '2026-03-01', 'to' => '2026-03-09'];
        $quarter = ['from' => '2026-01-01', 'to' => '2026-03-31'];
        // Past the 24 months the trend has always kept.
        $thirtyMonths = ['from' => '2023-10-01', 'to' => '2026-03-31'];
        $backwards = ['from' => '2026-03-31', 'to' => '2026-03-01'];
        $home = $world['categories']['home']->id;
        $food = $world['categories']['food']->id;

        $outputs = [
            'web summary march' => $web('cashflow/summary', $march),
            'web summary first days' => $web('cashflow/summary', $firstDays),
            'web summary quarter' => $web('cashflow/summary', $quarter),
            'web sankey march' => $web('cashflow/sankey', $march),
            'web sankey drilled into Home' => $web('cashflow/sankey', [...$march, 'parent' => $home]),
            'web sankey drilled into Food' => $web('cashflow/sankey', [...$march, 'parent' => $food]),
            'web trend range' => $web('cashflow/trend', $quarter),
            'web trend default' => $web('cashflow/trend', []),
            'web trend six months' => $web('cashflow/trend', ['months' => 6]),
            'web trend three months back from a date' => $web('cashflow/trend', ['months' => 3, 'to' => '2026-02-15']),
            'web trend past its cap' => $web('cashflow/trend', $thirtyMonths),
            'web trend backwards' => $web('cashflow/trend', $backwards),
            'web breakdown expense' => $web('cashflow/breakdown', [...$march, 'type' => 'expense']),
            'web breakdown income' => $web('cashflow/breakdown', [...$march, 'type' => 'income']),
            'web breakdown drilled into Food' => $web('cashflow/breakdown', [...$march, 'type' => 'expense', 'parent' => $food]),
            'web net worth march' => $web('dashboard/net-worth', $march),
            'web net worth first days' => $web('dashboard/net-worth', $firstDays),
            'web evolution' => $web('dashboard/net-worth-evolution', $quarter),
            'web evolution with the excluded accounts' => $web('dashboard/net-worth-evolution', [...$quarter, 'include_excluded' => 1]),
            'web daily evolution' => $web('dashboard/net-worth-daily-evolution', $firstDays),
            'web daily evolution with the excluded accounts' => $web('dashboard/net-worth-daily-evolution', [...$firstDays, 'include_excluded' => 1]),
            'web top categories' => $web('dashboard/top-categories', $march),
            'web top categories drilled into Food' => $web('dashboard/top-categories', [...$march, 'parent' => $food]),
            'get_cashflow march' => $tool(GetCashflow::class, $march),
            'get_cashflow first days' => $tool(GetCashflow::class, $firstDays),
            'get_cashflow quarter' => $tool(GetCashflow::class, $quarter),
            'get_cashflow past the trend cap' => $tool(GetCashflow::class, $thirtyMonths),
            'get_cashflow backwards' => $tool(GetCashflow::class, $backwards),
            'get_net_worth default' => $tool(GetNetWorth::class, $quarter),
            'get_net_worth monthly' => $tool(GetNetWorth::class, [...$quarter, 'granularity' => 'monthly']),
            'get_net_worth daily' => $tool(GetNetWorth::class, [...$firstDays, 'granularity' => 'daily']),
            'get_net_worth backwards' => $tool(GetNetWorth::class, $backwards),
            'spending_by_category march' => $tool(SpendingByCategory::class, $march),
            'spending_by_category drilled into Food' => $tool(SpendingByCategory::class, [...$march, 'parent_category_id' => $food]),
        ];

        return array_map(fn (string $bytes): string => strtr($bytes, $world['spaces']), $outputs);
    }
}
