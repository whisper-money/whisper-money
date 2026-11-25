<?php

namespace Tests\Support;

use App\Enums\AccountType;
use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Models\Account;
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
use App\Services\CashflowSummaryService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * The invented household of tests/Feature/CashflowEquivalenceTest.php and the
 * outputs it pins, shared with the tests that must leave those outputs as
 * they are.
 */
final class CashflowEquivalenceWorld
{
    public const GOLDEN = __DIR__.'/../Fixtures/cashflow-equivalence.json';

    /**
     * The invented household every consumer reads.
     *
     * Every row gets an id from a counter, in creation order, so the order rows
     * come back in never depends on a random uuid. `names` maps each id to a
     * readable name for the golden file.
     *
     * @return array{user: User, household: Space, personal: Space, names: array<string, string>, ids: array<string, string>}
     */
    public static function build(): array
    {
        $names = [];
        $counter = 0;
        $id = function (string $name) use (&$names, &$counter): string {
            $uuid = sprintf('00000000-0000-4000-8000-%012d', ++$counter);
            $names[$uuid] = $name;

            return $uuid;
        };

        $user = User::factory()->onboarded()->create(['id' => $id('user:Main'), 'currency_code' => 'EUR']);
        $partner = User::factory()->onboarded()->create(['id' => $id('user:Partner'), 'currency_code' => 'EUR']);
        $personal = $user->personalSpace()->firstOrFail();
        $names[$personal->id] = 'space:Main personal';
        $names[$partner->personalSpace()->firstOrFail()->id] = 'space:Partner personal';

        $household = Space::factory()->create(['id' => $id('space:Household'), 'owner_id' => $user->id, 'name' => 'Household']);
        $household->members()->attach($partner->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

        // Rates are stored against the user's currency: an amount in USD is
        // divided by the USD rate. Four decimals, so conversions round.
        foreach ([
            '2026-01-15' => ['usd' => 1.0912, 'gbp' => 0.8433],
            '2026-02-03' => ['usd' => 1.0791, 'gbp' => 0.8402],
            '2026-02-20' => ['usd' => 1.0805, 'gbp' => 0.8366],
            '2026-03-04' => ['usd' => 1.0837, 'gbp' => 0.8571],
            '2026-03-06' => ['usd' => 1.0851, 'gbp' => 0.8571],
            '2026-03-10' => ['usd' => 1.0866, 'gbp' => 0.8590],
            '2026-03-11' => ['usd' => 1.0869, 'gbp' => 0.8594],
            '2026-03-12' => ['usd' => 1.0873, 'gbp' => 0.8601],
        ] as $date => $rates) {
            ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => $date, 'rates' => $rates]);
        }

        $account = fn (string $name, AccountType $type, string $currency, int $share = 100, ?User $owner = null, ?Space $space = null): Account => Account::factory()->create([
            'id' => $id("account:{$name}"),
            'user_id' => ($owner ?? $user)->id,
            'space_id' => ($space ?? $personal)->id,
            'name' => $name,
            'type' => $type,
            'currency_code' => $currency,
            'ownership_percentage' => $share,
        ]);

        $checking = $account('Checking EUR', AccountType::Checking, 'EUR');
        $dollars = $account('Checking USD', AccountType::Checking, 'USD');
        $sharedPounds = $account('Shared GBP 50%', AccountType::Checking, 'GBP', 50);
        $thirdEuros = $account('Shared EUR 33%', AccountType::Checking, 'EUR', 33);
        $savings = $account('Savings EUR', AccountType::Savings, 'EUR');
        $brokerage = $account('Brokerage USD', AccountType::Investment, 'USD');
        $archived = $account('Archived checking', AccountType::Checking, 'EUR');
        $trashed = $account('Trashed card USD 50%', AccountType::CreditCard, 'USD', 50);
        $joint = $account('Joint EUR', AccountType::Checking, 'EUR', space: $household);
        $partnerChecking = $account('Partner checking', AccountType::Checking, 'EUR', owner: $partner, space: $household);

        $category = function (string $name, CategoryType $type, ?Category $parent = null, ?CategoryCashflowDirection $direction = null, ?User $owner = null, ?Space $space = null) use ($id, $user, $personal): Category {
            // The factory draws a unique name from a list shorter than this
            // household's categories; the name is set here anyway.
            fake()->unique(true);

            return Category::factory()->create([
                'id' => $id("category:{$name}"),
                'user_id' => ($owner ?? $user)->id,
                'space_id' => ($space ?? $personal)->id,
                'name' => $name,
                'icon' => 'Tag',
                'color' => 'blue',
                'type' => $parent->type ?? $type,
                'cashflow_direction' => $parent->cashflow_direction ?? $direction ?? CategoryCashflowDirection::Hidden,
                'parent_id' => $parent?->id,
            ]);
        };

        $work = $category('Work', CategoryType::Income);
        $salary = $category('Salary', CategoryType::Income, $work);
        $bonus = $category('Bonus', CategoryType::Income, $work);
        $home = $category('Home', CategoryType::Expense);
        $rent = $category('Rent', CategoryType::Expense, $home);
        $utilities = $category('Utilities', CategoryType::Expense, $home);
        $food = $category('Food', CategoryType::Expense);
        $groceries = $category('Groceries', CategoryType::Expense, $food);
        $dining = $category('Dining', CategoryType::Expense, $food);
        $shopping = $category('Shopping', CategoryType::Expense);
        $fun = $category('Fun', CategoryType::Expense);
        $oldCategory = $category('Old category', CategoryType::Expense);
        $emergencyFund = $category('Emergency fund', CategoryType::Savings, direction: CategoryCashflowDirection::Outflow);
        $carFund = $category('Car fund', CategoryType::Savings, direction: CategoryCashflowDirection::Outflow);
        $indexFunds = $category('Index funds', CategoryType::Investment, direction: CategoryCashflowDirection::Outflow);
        $transferIn = $category('Transfer in', CategoryType::Transfer, direction: CategoryCashflowDirection::Inflow);
        $transferOut = $category('Transfer out', CategoryType::Transfer, direction: CategoryCashflowDirection::Outflow);
        $internalMove = $category('Internal move', CategoryType::Transfer, direction: CategoryCashflowDirection::Hidden);
        $gifts = $category('Gifts', CategoryType::Income);
        $pharmacy = $category('Pharmacy', CategoryType::Expense);
        $partnerSalary = $category('Partner salary', CategoryType::Income, owner: $partner, space: $household);
        $partnerGroceries = $category('Partner groceries', CategoryType::Expense, owner: $partner, space: $household);

        $label = fn (string $name): Label => Label::factory()->create([
            'id' => $id("label:{$name}"),
            'user_id' => $user->id,
            'space_id' => $personal->id,
            'name' => $name,
            'color' => 'teal',
        ]);

        $holiday = $label('Holiday');
        $subscription = $label('Subscription');
        $gone = $label('Gone');
        $untracked = $label('Untracked');

        /**
         * @param  string|null  $currency  null for the account's own; '' for none, which falls back to the account's
         * @param  list<Label>  $labels
         * @param  list<array{0: Category, 1: int}>  $lines  split lines, in position order
         */
        $transaction = function (string $name, Account $on, ?Category $in, int $amount, string $date, ?string $currency = null, array $labels = [], array $lines = []) use ($id): Transaction {
            $transaction = Transaction::factory()->plaintext()->create([
                'id' => $id("transaction:{$name}"),
                'user_id' => $on->user_id,
                'account_id' => $on->id,
                'category_id' => $in?->id,
                'description' => $name,
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

            foreach ($labels as $attached) {
                $transaction->labels()->attach($attached);
            }

            return $transaction;
        };

        // January: only the trend, forMonths and the quarter and year summaries read it.
        $transaction('Jan salary', $checking, $salary, 250000, '2026-01-15');
        $janRent = $transaction('Jan rent', $checking, $rent, -95000, '2026-01-15');
        $transaction('Jan groceries USD', $dollars, $groceries, -4321, '2026-01-15');
        $transaction('Jan emergency fund out', $checking, $emergencyFund, -20000, '2026-01-20');
        $transaction('Jan emergency fund in', $savings, $emergencyFund, 20000, '2026-01-20');

        // February: the previous period.
        $transaction('Feb salary', $checking, $salary, 250000, '2026-02-03');
        $febRent = $transaction('Feb rent', $checking, $rent, -95000, '2026-02-03');
        $febSplit = $transaction('Feb food split', $checking, null, -12000, '2026-02-03', labels: [$holiday], lines: [[$groceries, -7000], [$dining, -5000]]);
        $transaction('Feb fun, no currency on a USD account', $dollars, $fun, -5099, '2026-02-03', currency: '');
        $transaction('Feb archived fun', $archived, $fun, -444, '2026-02-10');
        $transaction('Feb shopping refund', $checking, $shopping, 3000, '2026-02-20');
        $transaction('Feb uncategorized out', $checking, null, -1234, '2026-02-20');
        $transaction('Feb uncategorized in', $checking, null, 777, '2026-02-20');
        $transaction('Feb utilities GBP 50%', $sharedPounds, $utilities, -8765, '2026-02-20');
        // Gifts nets negative in February and crosses to the expense side as a reversal.
        $transaction('Feb gift given back USD', $dollars, $gifts, -2500, '2026-02-20');
        $transaction('Feb partner salary', $partnerChecking, $partnerSalary, 180000, '2026-02-25');

        // March: the current period.
        $transaction('Mar partner salary', $partnerChecking, $partnerSalary, 180000, '2026-03-01');
        $transaction('Mar gift', $checking, $gifts, 4321, '2026-03-01');
        $transaction('Mar pharmacy', $checking, $pharmacy, -999, '2026-03-01');
        $transaction('Mar salary', $checking, $salary, 260000, '2026-03-02');
        $transaction('Mar bonus', $checking, $bonus, 15001, '2026-03-02');
        $marRentSplit = $transaction('Mar rent and sublet split', $checking, null, -40000, '2026-03-02', lines: [[$rent, -50000], [$salary, 10000]]);
        $marHolidaySplit = $transaction('Mar groceries and savings split', $checking, null, -30000, '2026-03-03', labels: [$holiday], lines: [[$groceries, -10000], [$emergencyFund, -20000]]);
        // Lines add up to -9999 against a -10001 parent: the last line takes the residue.
        // The Holiday label (tracked by a variable budget) claims the untracked
        // shopping line; dining stays fixed, since fixed wins an overlap.
        $transaction('Mar USD split with residue', $dollars, $shopping, -10001, '2026-03-04', labels: [$holiday], lines: [[$dining, -3333], [$fun, -3333], [$shopping, -3333]]);
        $transaction('Mar archived groceries before archiving', $archived, $groceries, -1111, '2026-03-04');
        $transaction('Mar archived groceries on archive day', $archived, $groceries, -2222, '2026-03-05');
        $transaction('Mar GBP 50% split', $sharedPounds, null, -5001, '2026-03-06', lines: [[$groceries, -2501], [$dining, -2500]]);
        $transaction('Mar groceries 33%', $thirdEuros, $groceries, -1001, '2026-03-07');
        $transaction('Mar fun 33%', $thirdEuros, $fun, -2999, '2026-03-07');
        $transaction('Mar 33% split', $thirdEuros, null, -1003, '2026-03-07', lines: [[$shopping, -501], [$fun, -501]]);
        $transaction('Mar shopping under a trashed label', $checking, $shopping, -2000, '2026-03-08', labels: [$gone, $untracked]);
        $transaction('Mar shopping refund', $checking, $shopping, 6500, '2026-03-08');
        $marSubscription = $transaction('Mar streaming subscription', $checking, $fun, -1299, '2026-03-08', labels: [$subscription]);
        $marDining = $transaction('Mar dining', $checking, $dining, -4550, '2026-03-09');
        $transaction('Mar utilities', $checking, $utilities, -6789, '2026-03-09');
        $transaction('Mar home direct', $checking, $home, -1500, '2026-03-09');
        $transaction('Mar uncategorized out', $checking, null, -2345, '2026-03-10');
        $transaction('Mar uncategorized in USD', $dollars, null, 1111, '2026-03-10');
        $transaction('Mar transfer in', $checking, $transferIn, 30000, '2026-03-10');
        $transaction('Mar transfer out', $checking, $transferOut, -12000, '2026-03-10');
        $transaction('Mar internal move out', $checking, $internalMove, -50000, '2026-03-10');
        $transaction('Mar internal move in', $savings, $internalMove, 50000, '2026-03-10');
        $transaction('Mar emergency fund out', $checking, $emergencyFund, -25000, '2026-03-11');
        $transaction('Mar emergency fund in', $savings, $emergencyFund, 25000, '2026-03-11');
        $transaction('Mar emergency fund withdrawal', $checking, $emergencyFund, 5000, '2026-03-11');
        $transaction('Mar car fund withdrawal', $checking, $carFund, 8000, '2026-03-11');
        $transaction('Mar index funds out', $checking, $indexFunds, -40000, '2026-03-11');
        $transaction('Mar index funds in USD', $brokerage, $indexFunds, 43474, '2026-03-11');
        $transaction('Mar archived groceries after archiving', $archived, $groceries, -3333, '2026-03-12');
        $transaction('Mar trashed account fun, no currency', $trashed, $fun, -1000, '2026-03-12', currency: '');
        $transaction('Mar trashed account fun USD', $trashed, $fun, -2000, '2026-03-12');
        $transaction('Mar trashed category', $checking, $oldCategory, -7777, '2026-03-12');
        $transaction('Mar groceries in USD on a EUR account', $checking, $groceries, -2500, '2026-03-12', currency: 'USD');
        $transaction('Mar fun in EUR on a USD account', $dollars, $fun, -1800, '2026-03-12', currency: 'EUR');
        $transaction('Mar salary, no currency on a USD account', $dollars, $salary, 1234, '2026-03-12', currency: '');
        // Converted row by row, each -5 USD rounds to -5 EUR (-10); converted as a sum it would be -9.
        $transaction('Mar coin fun USD, first', $dollars, $fun, -5, '2026-03-12');
        $transaction('Mar coin fun USD, second', $dollars, $fun, -5, '2026-03-12');
        // The user's currency spelled in lowercase is still the user's currency.
        $transaction('Mar dining in lowercase eur on a USD account', $dollars, $dining, -321, '2026-03-12', currency: 'eur');
        $transaction('Mar joint groceries', $joint, $groceries, -6000, '2026-03-13');
        $transaction('Mar partner groceries', $partnerChecking, $partnerGroceries, -4567, '2026-03-13');
        // A refund big enough that Groceries nets positive inside the Food drill-down.
        $transaction('Mar groceries refund', $checking, $groceries, 25000, '2026-03-13');

        // Budgets come after the transactions, so nothing assigns them on the way
        // in: what each period has spent is set below, as stored.
        $budget = function (string $name, BudgetPeriodType $type, BudgetExpenseType $expenseType, array $categories = [], array $labels = [], bool $catchAll = false) use ($id, $user, $personal): Budget {
            $budget = Budget::factory()->create([
                'id' => $id("budget:{$name}"),
                'user_id' => $user->id,
                'space_id' => $personal->id,
                'name' => $name,
                'period_type' => $type,
                'period_start_day' => 1,
                'expense_type' => $expenseType,
                'is_catch_all' => $catchAll,
            ]);
            $budget->categories()->sync(array_map(fn (Category $category): string => $category->id, $categories));
            $budget->labels()->sync(array_map(fn (Label $label): string => $label->id, $labels));

            return $budget;
        };
        $period = function (Budget $budget, string $start, string $end, int $allocated, ?Transaction $spentOn = null, int $spent = 0) use ($id): BudgetPeriod {
            $period = BudgetPeriod::factory()->create([
                'id' => $id("budget period:{$budget->name} {$start}"),
                'budget_id' => $budget->id,
                'start_date' => $start,
                'end_date' => $end,
                'allocated_amount' => $allocated,
                'carried_over_amount' => 0,
            ]);

            if ($spentOn !== null) {
                BudgetTransaction::factory()->create(['transaction_id' => $spentOn->id, 'budget_period_id' => $period->id, 'amount' => $spent]);
            }

            return $period;
        };

        $housing = $budget('Housing', BudgetPeriodType::Monthly, BudgetExpenseType::Fixed, categories: [$home]);
        $period($housing, '2026-02-01', '2026-02-28', 100000, $febRent, 95000);
        $period($housing, '2026-03-01', '2026-03-31', 100000, $marRentSplit, 50000);
        $period($housing, '2026-01-01', '2026-01-31', 100000, $janRent, 95000);

        $foodBudget = $budget('Food budget', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, categories: [$food]);
        $period($foodBudget, '2026-02-01', '2026-02-28', 40000, $febSplit, 12000);
        // Over budget: a negative margin that reserves nothing.
        $period($foodBudget, '2026-03-01', '2026-03-31', 40000, $marHolidaySplit, 45000);

        $holidays = $budget('Holidays', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, labels: [$holiday]);
        $period($holidays, '2026-03-01', '2026-03-31', 30000, $marHolidaySplit, 10000);

        // Tracks a label that is trashed below: it no longer reaches anything.
        $subscriptions = $budget('Subscriptions', BudgetPeriodType::Monthly, BudgetExpenseType::Fixed, labels: [$subscription, $gone]);
        $period($subscriptions, '2026-03-01', '2026-03-31', 5000, $marSubscription, 1299);

        // Dining is tracked by this fixed budget and by the variable Food budget: fixed wins.
        $diningOut = $budget('Dining out', BudgetPeriodType::Monthly, BudgetExpenseType::Fixed, categories: [$dining]);
        $period($diningOut, '2026-03-01', '2026-03-31', 10000, $marDining, 4550);

        $catchAll = $budget('Everything else', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, catchAll: true);
        $period($catchAll, '2026-03-01', '2026-03-31', 20000, $marDining, 7000);

        $yearlyFun = $budget('Yearly fun', BudgetPeriodType::Yearly, BudgetExpenseType::Variable, categories: [$fun]);
        $period($yearlyFun, '2026-01-01', '2026-12-31', 60000, $marSubscription, 9000);

        $archived->forceFill(['archived_at' => '2026-03-05 10:00:00'])->save();
        $trashed->delete();
        $oldCategory->delete();
        $gone->delete();

        return [
            'user' => $user,
            'household' => $household,
            'personal' => $personal,
            'names' => $names,
            'ids' => array_flip($names),
        ];
    }

    /**
     * Every uuid replaced by the name of what it points at, keys included; an id
     * nobody named fails the test rather than landing in the golden file. Encoded
     * and decoded once, so the service outputs and the endpoint responses compare
     * the same way, and a float stays a float.
     *
     * @param  array<string, string>  $names
     */
    public static function normalize(mixed $value, array $names): mixed
    {
        $value = json_decode(json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $rename = function (mixed $value) use (&$rename, $names): mixed {
            if (is_array($value)) {
                $renamed = [];
                foreach ($value as $key => $item) {
                    $renamed[is_string($key) ? $rename($key) : $key] = $rename($item);
                }

                return $renamed;
            }

            if (is_string($value) && Str::isUuid($value)) {
                expect($names)->toHaveKey($value);

                return $names[$value];
            }

            return $value;
        };

        return $rename($value);
    }

    /**
     * The full output of every cashflow consumer for the household, keyed by a
     * name saying which call it is.
     *
     * @param  array{user: User, household: Space, personal: Space, names: array<string, string>, ids: array<string, string>}  $world
     * @return array<string, mixed>
     */
    public static function outputs(array $world): array
    {
        $user = $world['user'];
        $ids = $world['ids'];
        test()->actingAs($user);

        $api = fn (string $endpoint, array $query): array => test()
            ->getJson("/api/cashflow/{$endpoint}?".http_build_query($query))
            ->assertOk()
            ->json();
        $march = ['from' => '2026-03-01', 'to' => '2026-03-31'];
        $february = ['from' => '2026-02-01', 'to' => '2026-02-28'];

        $outputs = [
            'summary march' => $api('summary', $march),
            'summary first days of march' => $api('summary', ['from' => '2026-03-01', 'to' => '2026-03-09']),
            'summary first quarter' => $api('summary', ['from' => '2026-01-01', 'to' => '2026-03-31']),
            'summary year' => $api('summary', ['from' => '2026-01-01', 'to' => '2026-12-31']),
            'sankey march' => $api('sankey', $march),
            'sankey february' => $api('sankey', $february),
            'sankey march drilled into Home' => $api('sankey', [...$march, 'parent' => $ids['category:Home']]),
            'sankey march drilled into Food' => $api('sankey', [...$march, 'parent' => $ids['category:Food']]),
            'sankey march drilled into Work' => $api('sankey', [...$march, 'parent' => $ids['category:Work']]),
            'trend default' => $api('trend', []),
            'trend 3 months' => $api('trend', ['months' => 3]),
            'trend january to march' => $api('trend', ['from' => '2026-01-01', 'to' => '2026-03-31']),
            'trend capped at the longest window' => $api('trend', ['from' => '2023-01-01', 'to' => '2026-03-31']),
            'breakdown march expense' => $api('breakdown', [...$march, 'type' => 'expense']),
            'breakdown march income' => $api('breakdown', [...$march, 'type' => 'income']),
            'breakdown march expense drilled into Home' => $api('breakdown', [...$march, 'type' => 'expense', 'parent' => $ids['category:Home']]),
            'breakdown march expense drilled into Food' => $api('breakdown', [...$march, 'type' => 'expense', 'parent' => $ids['category:Food']]),
            'breakdown march income drilled into Work' => $api('breakdown', [...$march, 'type' => 'income', 'parent' => $ids['category:Work']]),
            'dashboard cashflowSummary' => test()->get(route('dashboard'), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'dashboard',
                'X-Inertia-Partial-Data' => 'cashflowSummary',
            ])->assertOk()->json('props.cashflowSummary'),
        ];

        $summaries = app(CashflowSummaryService::class);
        $outputs['forMonths'] = $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));
        $outputs['forMonths household space'] = $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $world['household']->id);
        $outputs['forMonths personal space'] = $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $world['personal']->id);

        $outputs['mcp get_cashflow march'] = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetCashflow::class, $march)->assertOk());

        return self::normalize($outputs, $world['names']);
    }
}
