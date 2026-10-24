<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashflowSummaryService;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\Http;

/*
 * The cashflow figures are added up in SQL, but must come out exactly as the
 * row-by-row path did: per-row rounding (currency conversion, ownership share)
 * included. Each case below would drift by a cent or more if the rows were
 * summed first and converted or shared once.
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

function aggregateCategory(User $user, CategoryType $type): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'type' => $type]);
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

test('the monthly summary puts each row in its own month', function () {
    $account = aggregateAccount($this->user, ['ownership_percentage' => 50]);
    $groceries = aggregateCategory($this->user, CategoryType::Expense);
    $salary = aggregateCategory($this->user, CategoryType::Income);

    aggregateRow($account, $groceries, -101, ['transaction_date' => '2026-04-30']);
    aggregateRow($account, $groceries, -101, ['transaction_date' => '2026-05-01']);
    aggregateRow($account, $salary, 10001, ['transaction_date' => '2026-05-31']);

    $months = app(CashflowSummaryService::class)->forMonths(
        $this->user->id,
        'EUR',
        now()->setDate(2026, 3, 15),
        now()->setDate(2026, 5, 15),
    );

    expect(array_keys($months))->toBe(['2026-03', '2026-04', '2026-05'])
        ->and($months['2026-03'])->toMatchArray(['income' => 0, 'expense' => 0])
        ->and($months['2026-04'])->toMatchArray(['income' => 0, 'expense' => 51])
        ->and($months['2026-05'])->toMatchArray(['income' => 5001, 'expense' => 51, 'net' => 4950]);
});

test('the monthly figures match adding every row up one at a time', function () {
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

    mt_srand(1160);
    for ($i = 0; $i < 300; $i++) {
        aggregateRow($accounts[mt_rand(0, 6)], $categories[mt_rand(0, 7)], [0, 1, -1, 101, -101, 333, -2001, mt_rand(-90000, 90000)][mt_rand(0, 7)], [
            'currency_code' => $currencies[mt_rand(0, 4)],
            'transaction_date' => $dates[mt_rand(0, 6)],
        ]);
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
            $rows = Transaction::query()->where('transactions.user_id', $userId)->countingTowardsTotals()->with(['account', 'category'])->get();

            foreach ($rows as $row) {
                $month = $row->transaction_date->format('Y-m');
                $months[$month] ??= ['income' => 0, 'expense' => 0, 'savings' => 0, 'investments' => 0];
                $amount = $this->convertTransactionAmount($row, 'EUR');
                $setAside = $row->isSetAsideAccountLeg() ? null : $row->categoryType();

                $months[$month]['income'] += $row->isIncomeSide() ? $amount : 0;
                $months[$month]['expense'] -= $row->isExpenseSide() ? $amount : 0;
                $months[$month]['savings'] -= $setAside === CategoryType::Savings ? $amount : 0;
                $months[$month]['investments'] -= $setAside === CategoryType::Investment ? $amount : 0;
            }

            return $months;
        }
    };

    $expected = $reference->months($this->user->id);
    $months = app(CashflowSummaryService::class)->forMonths($this->user->id, 'EUR', now()->setDate(2026, 3, 1), now()->setDate(2026, 5, 31));
    $trend = $this->getJson('/api/cashflow/trend?'.http_build_query(['months' => 3, 'to' => '2026-05-31']))->assertOk()->json('data');

    foreach (['2026-03', '2026-04', '2026-05'] as $index => $month) {
        expect($months[$month])->toMatchArray($expected[$month])
            ->and($trend[$index])->toMatchArray([
                'month' => $month,
                'income' => $expected[$month]['income'],
                'expense' => $expected[$month]['expense'],
            ]);
    }
});
