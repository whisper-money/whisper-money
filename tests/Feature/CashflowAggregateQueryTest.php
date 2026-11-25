<?php

use App\Enums\AccountType;
use App\Enums\BudgetExpenseType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\CashflowAggregate;
use App\Services\CashflowAggregateQuery;
use App\Services\CashflowSummaryService;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\Http;

/*
 * The cashflow figures are added up in SQL, but must come out exactly as the
 * row-by-row path did: per-row rounding (currency conversion, ownership share)
 * included. Each case below would drift by a cent or more if the rows were
 * summed first and converted or shared once.
 *
 * Split transactions are the fork's TransactionSplit lines: their postings are
 * still expanded one by one, each line converted on its own and the last one
 * taking the residue, then shared per posting.
 */

beforeEach(function () {
    Http::fake();

    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->actingAs($this->user);
    $this->period = ['from' => '2026-05-01', 'to' => '2026-05-31'];
});

function aggregateAccount(User $user, array $attributes = []): Account
{
    return Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Checking,
        'ownership_percentage' => 100,
        ...$attributes,
    ]);
}

function aggregateCategory(User $user, CategoryType $type, array $attributes = []): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'type' => $type, ...$attributes]);
}

function aggregateRow(Account $account, ?Category $category, int $amount, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'currency_code' => $account->currency_code,
        'transaction_date' => '2026-05-10',
        ...$attributes,
    ]);
}

/**
 * A transaction split into lines, in position order.
 *
 * @param  list<array{0: Category, 1: int}>  $lines
 */
function aggregateSplitRow(Account $account, int $amount, array $lines, array $attributes = []): Transaction
{
    $transaction = aggregateRow($account, null, $amount, $attributes);

    foreach ($lines as $position => [$category, $lineAmount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $lineAmount,
            'position' => $position,
        ]);
    }

    return $transaction;
}

/**
 * The expense total as every cashflow surface reports it.
 *
 * @return array<string, int>
 */
function expenseEverywhere(object $test): array
{
    $period = $test->period;

    return [
        'summary' => $test->getJson('/api/cashflow/summary?'.http_build_query($period))->assertOk()->json('current.expense'),
        'trend' => $test->getJson('/api/cashflow/trend?'.http_build_query(['months' => 1, 'to' => $period['to']]))->assertOk()->json('data.0.expense'),
        'sankey' => $test->getJson('/api/cashflow/sankey?'.http_build_query($period))->assertOk()->json('total_expense'),
        'breakdown' => $test->getJson('/api/cashflow/breakdown?'.http_build_query([...$period, 'type' => 'expense']))->assertOk()->json('total'),
    ];
}

/**
 * A budget of $expenseType tracking $labels, with a period over May 2026.
 *
 * @param  list<Label>  $labels
 */
function aggregateLabelBudget(User $user, BudgetExpenseType $expenseType, array $labels): Budget
{
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
        'expense_type' => $expenseType,
        'is_catch_all' => false,
    ]);
    $budget->labels()->sync(array_map(fn (Label $label): string => $label->id, $labels));
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'allocated_amount' => 100000]);

    return $budget;
}

test('a partly owned account rounds its share per row, not per total', function () {
    $account = aggregateAccount($this->user, ['ownership_percentage' => 50]);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);

    // Half of 1.01 rounds to 0.51 on each row. Halving the 2.02 total would
    // give 1.01 and lose a cent.
    aggregateRow($account, $groceries, -101);
    aggregateRow($account, $groceries, -101);
    aggregateRow($account, null, -101);

    expect(expenseEverywhere($this))->toBe(['summary' => 153, 'trend' => 153, 'sankey' => 153, 'breakdown' => 153]);
});

test('a foreign-currency row is converted on its own date, one row at a time', function () {
    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-05-10', 'rates' => ['usd' => 3]]);
    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-05-11', 'rates' => ['usd' => 2]]);

    $account = aggregateAccount($this->user, ['currency_code' => 'USD']);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);

    // 1.00 USD at 3 USD per EUR is 0.33 EUR per row, so 0.66 for two
    // identical rows, where converting their 2.00 total would give 0.67. The
    // 11th has its own rate: 1.00 USD at 2 is 0.50.
    aggregateRow($account, $groceries, -100);
    aggregateRow($account, $groceries, -100);
    aggregateRow($account, $groceries, -100, ['transaction_date' => '2026-05-11']);

    expect(expenseEverywhere($this))->toBe(['summary' => 116, 'trend' => 116, 'sankey' => 116, 'breakdown' => 116]);
});

test('a row booked in another currency than its account is converted from its own currency', function () {
    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-05-10', 'rates' => ['usd' => 2]]);

    $account = aggregateAccount($this->user);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);

    aggregateRow($account, $groceries, -1000, ['currency_code' => 'USD']);
    // A lowercase code is still the user's currency, summed as it is.
    aggregateRow($account, $groceries, -1000, ['currency_code' => 'eur']);
    // No code of its own: the account's.
    aggregateRow($account, $groceries, -1000, ['currency_code' => '']);

    expect(expenseEverywhere($this))->toBe(['summary' => 2500, 'trend' => 2500, 'sankey' => 2500, 'breakdown' => 2500]);
});

test('a row on a deleted account counts at face value in its own currency', function () {
    $account = aggregateAccount($this->user, ['currency_code' => 'USD', 'ownership_percentage' => 40]);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);

    // The account relation does not load a trashed account, so neither its
    // currency nor its ownership share applies to the row.
    aggregateRow($account, $groceries, -1000, ['currency_code' => '']);
    $account->delete();

    expect(expenseEverywhere($this))->toBe(['summary' => 1000, 'trend' => 1000, 'sankey' => 1000, 'breakdown' => 1000]);
});

test('a row on a deleted category counts on neither side', function () {
    $account = aggregateAccount($this->user);
    $gone = aggregateCategory($this->user, CategoryType::Expense);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);

    aggregateRow($account, $gone, -700);
    aggregateRow($account, $groceries, -300);
    $gone->delete();

    expect(expenseEverywhere($this))->toBe(['summary' => 300, 'trend' => 300, 'sankey' => 300, 'breakdown' => 300]);
});

test('uncategorized rows land on the side of their own sign, and a zero on neither', function () {
    $account = aggregateAccount($this->user);

    aggregateRow($account, null, 5000);
    aggregateRow($account, null, -1200);
    aggregateRow($account, null, -800);
    aggregateRow($account, null, 0);

    $this->getJson('/api/cashflow/summary?'.http_build_query($this->period))
        ->assertOk()
        ->assertJsonPath('current.income', 5000)
        ->assertJsonPath('current.expense', 2000);

    $this->getJson('/api/cashflow/trend?'.http_build_query(['months' => 1, 'to' => $this->period['to']]))
        ->assertOk()
        ->assertJsonPath('data.0.income', 5000)
        ->assertJsonPath('data.0.expense', 2000);

    $this->getJson('/api/cashflow/breakdown?'.http_build_query([...$this->period, 'type' => 'income']))
        ->assertOk()
        ->assertJsonPath('total', 5000)
        ->assertJsonPath('data.0.category.name', 'Unknown Income');
});

test('a split counts its lines and not the transaction they split', function () {
    $account = aggregateAccount($this->user);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);
    $household = aggregateCategory($this->user, CategoryType::Expense);

    $split = aggregateSplitRow($account, -1000, [[$groceries, -600], [$household, -400]]);
    $split->forceFill(['category_id' => $groceries->id])->save();

    expect(expenseEverywhere($this))->toBe(['summary' => 1000, 'trend' => 1000, 'sankey' => 1000, 'breakdown' => 1000]);

    $breakdown = $this->getJson('/api/cashflow/breakdown?'.http_build_query([...$this->period, 'type' => 'expense']))->assertOk();

    expect(collect($breakdown->json('data'))->pluck('amount', 'category_id')->all())
        ->toBe([$groceries->id => 600, $household->id => 400]);
});

test('a split counts its parts and not the original it replaced', function () {
    $account = aggregateAccount($this->user);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);
    $household = aggregateCategory($this->user, CategoryType::Expense);

    $original = aggregateRow($account, $groceries, -1000);
    aggregateRow($account, $groceries, -600, ['split_parent_id' => $original->id]);
    aggregateRow($account, $household, -400, ['split_parent_id' => $original->id]);
    $original->delete();

    expect(expenseEverywhere($this))->toBe(['summary' => 1000, 'trend' => 1000, 'sankey' => 1000, 'breakdown' => 1000]);
});

test('a foreign split converts each line on its own, the last takes the residue, and the share rounds per line', function () {
    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-05-10', 'rates' => ['usd' => 3]]);

    $account = aggregateAccount($this->user, ['currency_code' => 'USD', 'ownership_percentage' => 50]);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);
    $household = aggregateCategory($this->user, CategoryType::Expense);
    $fun = aggregateCategory($this->user, CategoryType::Expense);

    // The parent: -10.00 USD at 3 is -3.33 EUR. The lines do not add up to it
    // (-9.00): -4.00 is -1.33 twice, and the last takes what is left of the
    // parent, -3.33 + 2.66 = -0.67 (not -1.00 / 3 = -0.33). Each half rounds
    // on its own: -0.67, -0.67 and -0.34, so 1.68, where halving the parent
    // would give 1.67.
    aggregateSplitRow($account, -1000, [[$groceries, -400], [$household, -400], [$fun, -100]]);

    expect(expenseEverywhere($this))->toBe(['summary' => 168, 'trend' => 168, 'sankey' => 168, 'breakdown' => 168]);

    $breakdown = $this->getJson('/api/cashflow/breakdown?'.http_build_query([...$this->period, 'type' => 'expense']))->assertOk();

    expect(collect($breakdown->json('data'))->pluck('amount', 'category_id')->all())
        ->toBe([$groceries->id => 67, $household->id => 67, $fun->id => 34]);
});

test('each split line counts on the side of its own category', function () {
    $account = aggregateAccount($this->user);
    $rent = aggregateCategory($this->user, CategoryType::Expense);
    $sublet = aggregateCategory($this->user, CategoryType::Income);
    $savings = aggregateCategory($this->user, CategoryType::Savings);

    aggregateSplitRow($account, -1200, [[$rent, -1500], [$sublet, 500], [$savings, -200]]);

    $this->getJson('/api/cashflow/summary?'.http_build_query($this->period))
        ->assertOk()
        ->assertJsonPath('current.income', 500)
        ->assertJsonPath('current.expense', 1500)
        ->assertJsonPath('current.savings', 200);

    $this->getJson('/api/cashflow/trend?'.http_build_query(['months' => 1, 'to' => $this->period['to']]))
        ->assertOk()
        ->assertJsonPath('data.0.income', 500)
        ->assertJsonPath('data.0.expense', 1500);
});

test('set-aside rows count once, from the account the money left', function () {
    $checking = aggregateAccount($this->user);
    $savingsAccount = aggregateAccount($this->user, ['type' => AccountType::Savings]);
    $brokerage = aggregateAccount($this->user, ['type' => AccountType::Investment, 'ownership_percentage' => 50]);
    $savings = aggregateCategory($this->user, CategoryType::Savings);
    $investment = aggregateCategory($this->user, CategoryType::Investment);

    aggregateRow($checking, $savings, -3000);
    aggregateRow($savingsAccount, $savings, 3000);
    aggregateRow($checking, $investment, -2001);
    aggregateRow($brokerage, $investment, 2001);
    // Money taken back out of savings, booked on the checking side.
    aggregateRow($checking, $savings, 500);

    $this->getJson('/api/cashflow/summary?'.http_build_query($this->period))
        ->assertOk()
        ->assertJsonPath('current.savings', 2500)
        ->assertJsonPath('current.investments', 2001);

    $sankey = $this->getJson('/api/cashflow/sankey?'.http_build_query($this->period))->assertOk();

    expect($sankey->json('total_expense'))->toBe(4501);
});

test('transfers stay out of the trend and the summary', function () {
    $account = aggregateAccount($this->user);
    $transfer = aggregateCategory($this->user, CategoryType::Transfer);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);

    aggregateRow($account, $transfer, -9000);
    aggregateRow($account, $transfer, 9000);
    aggregateRow($account, $groceries, -100);

    expect(expenseEverywhere($this))->toBe(['summary' => 100, 'trend' => 100, 'sankey' => 100, 'breakdown' => 100]);
});

test('categories come in the order of their first posting by date, a split line in its transaction\'s place', function () {
    $account = aggregateAccount($this->user);
    $late = aggregateCategory($this->user, CategoryType::Expense, ['name' => 'Late']);
    $early = aggregateCategory($this->user, CategoryType::Expense, ['name' => 'Early']);
    $middle = aggregateCategory($this->user, CategoryType::Expense, ['name' => 'Middle']);

    // Recorded in the opposite order of their dates.
    aggregateRow($account, $late, -100, ['transaction_date' => '2026-05-20']);
    aggregateSplitRow($account, -300, [[$middle, -200], [$late, -100]], ['transaction_date' => '2026-05-10']);
    aggregateRow($account, $early, -100, ['transaction_date' => '2026-05-05']);

    $sankey = $this->getJson('/api/cashflow/sankey?'.http_build_query($this->period))->assertOk();

    expect(collect($sankey->json('expense_categories'))->pluck('category.name')->all())->toBe(['Early', 'Middle', 'Late']);

    $aggregates = app(CashflowAggregateQuery::class)->aggregate(
        Transaction::query()->where('transactions.user_id', $this->user->id),
        'EUR',
    );

    expect($aggregates->map(fn (CashflowAggregate $aggregate): array => [$aggregate->transaction->category?->name, $aggregate->amount])->all())
        ->toBe([['Early', -100], ['Middle', -200], ['Late', -100], ['Late', -100]]);
});

test('tracked labels split the groups and ride on their stand-ins, trashed labels left out', function () {
    $account = aggregateAccount($this->user);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);
    [$rent, $holiday, $gone, $untracked] = Label::factory()->count(4)->create(['user_id' => $this->user->id]);

    $labelled = function (int $amount, array $labels) use ($account, $groceries): void {
        aggregateRow($account, $groceries, $amount)->labels()->attach(array_map(fn (Label $label): string => $label->id, $labels));
    };
    $labelled(-1, [$rent]);
    $labelled(-2, [$rent, $untracked]);
    $labelled(-4, [$holiday, $rent]);
    $labelled(-8, [$gone]);
    $labelled(-16, [$untracked]);
    $labelled(-32, []);
    $gone->delete();

    $query = app(CashflowAggregateQuery::class);
    $window = fn () => Transaction::query()->where('transactions.user_id', $this->user->id);
    $labelsOf = fn (CashflowAggregate $aggregate): array => $aggregate->transaction->labels->pluck('id')->sort()->values()->all();

    $groups = $query->aggregate($window(), 'EUR', [$rent->id, $holiday->id, $gone->id])
        ->mapWithKeys(fn (CashflowAggregate $aggregate): array => [$aggregate->amount => $labelsOf($aggregate)]);

    expect($groups->sortKeys()->all())->toBe([
        -56 => [],
        -4 => collect([$holiday->id, $rent->id])->sort()->values()->all(),
        -3 => [$rent->id],
    ]);

    // Without tracked labels the label key is not read at all.
    $untrackedGroups = $query->aggregate($window(), 'EUR');

    expect($untrackedGroups)->toHaveCount(1)
        ->and($untrackedGroups->first()->amount)->toBe(-63)
        ->and($untrackedGroups->first()->transaction->relationLoaded('labels'))->toBeFalse();
});

test('a label tracked fixed, one tracked variable and a trashed one split the summary\'s expense by label', function () {
    $this->travelTo(now()->setDate(2026, 5, 15));

    $account = aggregateAccount($this->user);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);
    $fun = aggregateCategory($this->user, CategoryType::Expense);
    [$rent, $holiday, $gone, $untracked] = Label::factory()->count(4)->create(['user_id' => $this->user->id]);

    aggregateLabelBudget($this->user, BudgetExpenseType::Fixed, [$rent, $gone]);
    aggregateLabelBudget($this->user, BudgetExpenseType::Variable, [$holiday]);

    $labelled = fn (Transaction $transaction, array $labels) => $transaction->labels()->attach(array_map(fn (Label $label): string => $label->id, $labels));
    $labelled(aggregateRow($account, $groceries, -1000), [$rent]);
    $labelled(aggregateRow($account, $groceries, -2000), [$holiday]);
    // Fixed wins an overlap.
    $labelled(aggregateRow($account, $groceries, -4000), [$rent, $holiday]);
    // A trashed label reaches nothing any more.
    $labelled(aggregateRow($account, $groceries, -8000), [$gone]);
    $labelled(aggregateRow($account, $groceries, -16000), [$untracked]);
    aggregateRow($account, $groceries, -32000);
    // A tracked label on a split claims every line.
    $labelled(aggregateSplitRow($account, -192, [[$groceries, -64], [$fun, -128]]), [$holiday]);
    $gone->delete();

    $this->getJson('/api/cashflow/summary?'.http_build_query($this->period))
        ->assertOk()
        ->assertJsonPath('current.expense', 63192)
        ->assertJsonPath('current.fixed_expense', 5000)
        ->assertJsonPath('current.variable_expense', 2192)
        ->assertJsonPath('current.general_expense', 56000);
});

test('the monthly summary puts each row in its own month', function () {
    $account = aggregateAccount($this->user, ['ownership_percentage' => 50]);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);
    $salary = aggregateCategory($this->user, CategoryType::Income);

    aggregateRow($account, $groceries, -101, ['transaction_date' => '2026-04-30']);
    aggregateRow($account, $groceries, -101, ['transaction_date' => '2026-05-01']);
    aggregateRow($account, $salary, 10001, ['transaction_date' => '2026-05-31']);
    aggregateSplitRow($account, -303, [[$groceries, -101], [$salary, -202]], ['transaction_date' => '2026-03-31']);

    $months = app(CashflowSummaryService::class)->forMonths(
        $this->user->id,
        'EUR',
        now()->setDate(2026, 3, 15),
        now()->setDate(2026, 5, 15),
    );

    expect(array_keys($months))->toBe(['2026-03', '2026-04', '2026-05'])
        ->and($months['2026-03'])->toMatchArray(['income' => -101, 'expense' => 51])
        ->and($months['2026-04'])->toMatchArray(['income' => 0, 'expense' => 51])
        ->and($months['2026-05'])->toMatchArray(['income' => 5001, 'expense' => 51, 'net' => 4950]);
});

test('the monthly figures match adding every posting up one at a time', function () {
    foreach (['2026-03-03', '2026-03-17', '2026-04-09', '2026-05-10', '2026-05-28'] as $date) {
        ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => $date, 'rates' => ['usd' => 1.0873, 'jpy' => 161.37]]);
    }

    $accounts = [
        aggregateAccount($this->user),
        aggregateAccount($this->user, ['ownership_percentage' => 33]),
        aggregateAccount($this->user, ['currency_code' => 'USD', 'ownership_percentage' => 50]),
        aggregateAccount($this->user, ['currency_code' => 'JPY']),
        aggregateAccount($this->user, ['type' => AccountType::Savings]),
        aggregateAccount($this->user, ['archived_at' => '2026-04-15 10:00:00']),
        $trashedAccount = aggregateAccount($this->user, ['currency_code' => 'USD', 'ownership_percentage' => 40]),
    ];
    $categories = [
        null,
        aggregateCategory($this->user, CategoryType::Income),
        aggregateCategory($this->user, CategoryType::Expense),
        aggregateCategory($this->user, CategoryType::Expense),
        aggregateCategory($this->user, CategoryType::Savings),
        aggregateCategory($this->user, CategoryType::Investment),
        aggregateCategory($this->user, CategoryType::Transfer),
        $trashedCategory = aggregateCategory($this->user, CategoryType::Expense),
    ];
    $dates = ['2026-03-03', '2026-03-17', '2026-03-31', '2026-04-09', '2026-04-15', '2026-05-10', '2026-05-28'];
    $currencies = ['', 'EUR', 'eur', 'USD', 'JPY'];
    $amounts = [0, 1, -1, 101, -101, 333, -2001];

    mt_srand(1162);
    for ($i = 0; $i < 300; $i++) {
        $amount = [...$amounts, mt_rand(-90000, 90000)][mt_rand(0, 7)];
        $attributes = [
            'currency_code' => $currencies[mt_rand(0, 4)],
            'transaction_date' => $dates[mt_rand(0, 6)],
        ];

        // One row in five is split into two or three lines in categories of
        // any type, whose amounts need not add up to it. A category in use by
        // a split line cannot be deleted, so the trashed one gets none.
        if (mt_rand(0, 4) === 0) {
            $lines = array_map(
                fn (): array => [$categories[mt_rand(1, 6)], $amounts[mt_rand(1, 6)]],
                range(1, mt_rand(2, 3)),
            );
            aggregateSplitRow($accounts[mt_rand(0, 6)], $amount, $lines, $attributes);

            continue;
        }

        aggregateRow($accounts[mt_rand(0, 6)], $categories[mt_rand(0, 7)], $amount, $attributes);
    }
    $trashedAccount->delete();
    $trashedCategory->delete();

    $reference = new class(app(ExchangeRateService::class))
    {
        use ConvertsTransactionCurrency;

        public function __construct(private ExchangeRateService $exchangeRateService) {}

        /** @return array<string, array<string, int>> */
        public function months(string $userId): array
        {
            $months = [];
            $rows = Transaction::query()->where('transactions.user_id', $userId)->countingTowardsTotals()->with(['account', 'category', 'splits.category'])->get();

            foreach ($this->effectiveTransactions($rows, 'EUR') as $posting) {
                $month = $posting->transaction_date->format('Y-m');
                $months[$month] ??= ['income' => 0, 'expense' => 0, 'savings' => 0, 'investments' => 0];
                $amount = $this->convertTransactionAmount($posting, 'EUR');
                $setAside = $posting->isSetAsideAccountLeg() ? null : $posting->categoryType();

                $months[$month]['income'] += $posting->isIncomeSide() ? $amount : 0;
                $months[$month]['expense'] -= $posting->isExpenseSide() ? $amount : 0;
                $months[$month]['savings'] -= $setAside === CategoryType::Savings ? $amount : 0;
                $months[$month]['investments'] -= $setAside === CategoryType::Investment ? $amount : 0;
            }

            return $months;
        }
    };

    $expected = $reference->months($this->user->id);
    $months = app(CashflowSummaryService::class)->forMonths($this->user->id, 'EUR', now()->setDate(2026, 3, 1), now()->setDate(2026, 5, 31));
    $trend = $this->getJson('/api/cashflow/trend?'.http_build_query(['months' => 3, 'to' => '2026-05-31']))->assertOk()->json('data');
    $summary = $this->getJson('/api/cashflow/summary?'.http_build_query($this->period))->assertOk()->json();

    expect(TransactionSplit::query()->count())->toBeGreaterThan(50);

    foreach (['2026-03', '2026-04', '2026-05'] as $index => $month) {
        expect($months[$month])->toMatchArray($expected[$month])
            ->and($trend[$index])->toMatchArray([
                'month' => $month,
                'income' => $expected[$month]['income'],
                'expense' => $expected[$month]['expense'],
            ]);
    }

    expect($summary['current'])->toMatchArray($expected['2026-05'])
        ->and($summary['previous'])->toMatchArray($expected['2026-04']);
});
