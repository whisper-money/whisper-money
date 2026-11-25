<?php

use App\Enums\AccountType;
use App\Enums\CashflowBlock;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\CashflowBlockQuery;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/*
 * The fork's split lines in the cashflow block filter: a transaction is listed
 * under a block when it, or any of its split lines, falls in it, while each
 * block's figure counts only the postings in it.
 */

beforeEach(function () {
    Http::fake();

    $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
    $this->savingsAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'type' => AccountType::Savings]);
    $this->actingAs($this->user);
});

function splitBlockCategory(CategoryType $type): Category
{
    return Category::factory()->create(['user_id' => test()->user->id, 'type' => $type]);
}

function splitBlockBudget(Budget $budget): void
{
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 50000]);
}

/**
 * An October transaction. With $lines it is split the old way, into lines with
 * no category of its own on the parent, one line per [category, amount].
 *
 * @param  list<array{0: Category, 1: int}>  $lines
 */
function splitBlockTransaction(?Category $category, int $amount, array $lines = [], ?Label $label = null, ?Account $account = null): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => ($account ?? test()->checking)->id,
        'category_id' => $category?->id,
        'amount' => $lines === [] ? $amount : array_sum(array_column($lines, 1)),
        'currency_code' => 'EUR',
        'transaction_date' => '2026-10-10',
    ]);

    foreach (array_values($lines) as $position => [$lineCategory, $lineAmount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $lineCategory->id,
            'amount' => $lineAmount,
            'position' => $position,
        ]);
    }

    if ($label) {
        $transaction->labels()->attach($label);
    }

    return $transaction;
}

/**
 * A month of split and unsplit transactions over every block, with fixed and
 * variable budgets tracking categories and labels.
 *
 * @return array<string, Transaction>
 */
function seedSplitCashflowMonth(): array
{
    $rent = splitBlockCategory(CategoryType::Expense);
    $food = splitBlockCategory(CategoryType::Expense);
    $shopping = splitBlockCategory(CategoryType::Expense);
    $salary = splitBlockCategory(CategoryType::Income);
    $savings = splitBlockCategory(CategoryType::Savings);
    $investments = splitBlockCategory(CategoryType::Investment);
    $subscriptions = Label::factory()->create(['user_id' => test()->user->id]);
    $holidays = Label::factory()->create(['user_id' => test()->user->id]);
    $userId = test()->user->id;

    splitBlockBudget(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $userId]));
    splitBlockBudget(Budget::factory()->monthly()->fixed()->forLabels($subscriptions)->create(['user_id' => $userId]));
    splitBlockBudget(Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $userId]));
    splitBlockBudget(Budget::factory()->monthly()->forLabels($holidays)->create(['user_id' => $userId]));
    splitBlockBudget(Budget::factory()->monthly()->catchAll()->create(['user_id' => $userId]));

    return [
        'rent_and_shopping' => splitBlockTransaction(null, 0, [[$rent, -60000], [$shopping, -4000]]),
        'savings_and_food' => splitBlockTransaction(null, 0, [[$savings, -10000], [$food, -2000]]),
        'holiday_salary_and_shopping' => splitBlockTransaction(null, 0, [[$salary, 20000], [$shopping, -3000]], $holidays),
        'subscription_food_and_shopping' => splitBlockTransaction(null, 0, [[$food, -1500], [$shopping, -500]], $subscriptions),
        'rent_and_food' => splitBlockTransaction(null, 0, [[$rent, -1000], [$food, -1000]]),
        'investments_and_shopping' => splitBlockTransaction(null, 0, [[$investments, -2000], [$shopping, -1000]]),
        'savings_account_legs' => splitBlockTransaction(null, 0, [[$savings, 5000], [$investments, 3000]], account: test()->savingsAccount),
        'rent' => splitBlockTransaction($rent, -80000),
        'food' => splitBlockTransaction($food, -5000),
        'uncategorized_out' => splitBlockTransaction(null, -600),
        'shopping_refund' => splitBlockTransaction($shopping, 1000),
        'savings_leg' => splitBlockTransaction($savings, 10000, account: test()->savingsAccount),
        'to_investments' => splitBlockTransaction($investments, -7000),
    ];
}

/**
 * @return list<string>
 */
function idsListedInBlock(CashflowBlock $block): array
{
    return collect(test()->get(route('transactions.index', ['cashflow_block' => $block->value, 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'per_page' => 100]))
        ->assertOk()
        ->original->getData()['page']['props']['transactions']['data'])
        ->pluck('id')->sort()->values()->all();
}

/**
 * @param  array<string, Transaction>  $seeded
 * @return list<string>
 */
function splitSeededIds(array $seeded, string ...$names): array
{
    return collect($names)->map(fn (string $name): string => $seeded[$name]->id)->sort()->values()->all();
}

/**
 * The postings the cashflow summary reads for the given transactions (a split
 * transaction as its lines), each with the amount it adds to a figure.
 *
 * @param  list<string>  $ids
 * @return Collection<int, array{posting: Transaction, amount: int}>
 */
function cashflowPostingsOf(array $ids): Collection
{
    $reader = new class(app(ExchangeRateService::class))
    {
        use ConvertsTransactionCurrency;

        public function __construct(private ExchangeRateService $exchangeRateService) {}

        /**
         * @param  Collection<int, Transaction>  $transactions
         * @return Collection<int, array{posting: Transaction, amount: int}>
         */
        public function read(Collection $transactions): Collection
        {
            return $this->effectiveTransactions($transactions, 'EUR')
                ->map(fn (Transaction $posting): array => ['posting' => $posting, 'amount' => $this->convertTransactionAmount($posting, 'EUR')]);
        }
    };

    return $reader->read(Transaction::query()
        ->whereIn('transactions.id', $ids)
        ->countingTowardsTotals()
        ->with(['account', 'category', 'splits.category', 'labels:id'])
        ->get());
}

/**
 * @return Collection<int, array{posting: Transaction, amount: int}>
 */
function postingsInBlock(Collection $postings, CashflowBlock $block): Collection
{
    $blocks = app(CashflowBlockQuery::class);
    $tracked = $blocks->trackedIds(test()->user->id, PeriodComparator::fromRequest(['from' => '2026-10-01', 'to' => '2026-10-31']));

    return $postings->filter(fn (array $row): bool => $blocks->blockOf($row['posting'], $tracked) === $block)->values();
}

test('a split transaction with a fixed line and a general line is listed under fixed and general, not variable', function () {
    $seeded = seedSplitCashflowMonth();
    $id = $seeded['rent_and_shopping']->id;

    expect(idsListedInBlock(CashflowBlock::Fixed))->toContain($id)
        ->and(idsListedInBlock(CashflowBlock::General))->toContain($id)
        ->and(idsListedInBlock(CashflowBlock::Variable))->not->toContain($id);
});

test('a split transaction with a savings line is listed under saved', function () {
    $seeded = seedSplitCashflowMonth();

    expect(idsListedInBlock(CashflowBlock::Saved))->toBe(splitSeededIds($seeded, 'savings_and_food'))
        ->and(idsListedInBlock(CashflowBlock::Variable))->toContain($seeded['savings_and_food']->id);
});

test('each block lists every transaction one of whose postings falls in it', function () {
    $seeded = seedSplitCashflowMonth();

    expect(idsListedInBlock(CashflowBlock::Fixed))->toBe(splitSeededIds($seeded, 'rent_and_shopping', 'subscription_food_and_shopping', 'rent_and_food', 'rent'))
        ->and(idsListedInBlock(CashflowBlock::Variable))->toBe(splitSeededIds($seeded, 'savings_and_food', 'holiday_salary_and_shopping', 'rent_and_food', 'food'))
        ->and(idsListedInBlock(CashflowBlock::General))->toBe(splitSeededIds($seeded, 'rent_and_shopping', 'investments_and_shopping', 'uncategorized_out', 'shopping_refund'))
        ->and(idsListedInBlock(CashflowBlock::Saved))->toBe(splitSeededIds($seeded, 'savings_and_food'))
        ->and(idsListedInBlock(CashflowBlock::Invested))->toBe(splitSeededIds($seeded, 'investments_and_shopping', 'to_investments'));
});

test('the SQL filter and the classifier agree on which transactions have a posting in each block', function () {
    $seeded = seedSplitCashflowMonth();
    $postings = cashflowPostingsOf(collect($seeded)->pluck('id')->all());

    foreach (CashflowBlock::cases() as $block) {
        $classified = postingsInBlock($postings, $block)->pluck('posting.id')->unique()->sort()->values()->all();

        expect(idsListedInBlock($block))->toBe($classified, "block {$block->value}");
    }
});

test('the postings in a block of its listed transactions add up to the block figure in the cashflow summary', function () {
    seedSplitCashflowMonth();

    $summary = $this->getJson('/api/cashflow/summary?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->json('current');

    $figure = fn (CashflowBlock $block): int => -postingsInBlock(cashflowPostingsOf(idsListedInBlock($block)), $block)->sum('amount');

    expect($figure(CashflowBlock::Fixed))->toBe($summary['fixed_expense'])
        ->and($figure(CashflowBlock::Variable))->toBe($summary['variable_expense'])
        ->and($figure(CashflowBlock::General))->toBe($summary['general_expense'])
        ->and($figure(CashflowBlock::Saved))->toBe($summary['savings'])
        ->and($figure(CashflowBlock::Invested))->toBe($summary['investments'])
        ->and($summary['fixed_expense'])->toBe(60000 + 1500 + 500 + 1000 + 80000)
        ->and($summary['variable_expense'])->toBe(2000 + 3000 + 1000 + 5000)
        ->and($summary['general_expense'])->toBe(4000 + 1000 + 600 - 1000)
        ->and($summary['savings'])->toBe(10000)
        ->and($summary['investments'])->toBe(2000 + 7000);
});

test('the block query and the classifier agree on a single split transaction', function () {
    $rent = splitBlockCategory(CategoryType::Expense);
    $food = splitBlockCategory(CategoryType::Expense);
    $shopping = splitBlockCategory(CategoryType::Expense);
    $savings = splitBlockCategory(CategoryType::Savings);
    splitBlockBudget(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]));
    splitBlockBudget(Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $this->user->id]));

    $split = splitBlockTransaction(null, 0, [[$rent, -30000], [$shopping, -2000], [$savings, -5000]]);

    $blocks = app(CashflowBlockQuery::class);
    $period = PeriodComparator::fromRequest(['from' => '2026-10-01', 'to' => '2026-10-31']);
    $tracked = $blocks->trackedIds($this->user->id, $period);
    $postings = cashflowPostingsOf([$split->id]);

    foreach (CashflowBlock::cases() as $block) {
        $listed = $blocks->apply(Transaction::query(), $block, $this->user->id, $period)->pluck('id')->all();
        $classified = $postings->contains(fn (array $row): bool => $blocks->blockOf($row['posting'], $tracked) === $block);

        expect($listed === [$split->id])->toBe($classified, "block {$block->value}");
    }

    expect(postingsInBlock($postings, CashflowBlock::Fixed)->sum('amount'))->toBe(-30000)
        ->and(postingsInBlock($postings, CashflowBlock::General)->sum('amount'))->toBe(-2000)
        ->and(postingsInBlock($postings, CashflowBlock::Saved)->sum('amount'))->toBe(-5000)
        ->and(postingsInBlock($postings, CashflowBlock::Variable))->toBeEmpty();
});

test('the analysis of a block counts only the split lines in it', function () {
    seedSplitCashflowMonth();

    $analysis = $this->getJson('/api/transactions/analysis?'.http_build_query([
        'cashflow_block' => 'fixed',
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-31',
    ]))->assertOk();

    expect($analysis->json('summary.expense'))->toBe(60000 + 1500 + 500 + 1000 + 80000)
        ->and($analysis->json('summary.count'))->toBe(4);
});

test('a select-all bulk edit over a block reaches the split transactions listed under it and nothing else', function () {
    $seeded = seedSplitCashflowMonth();
    $note = 'Reviewed fixed costs';
    $fixed = splitSeededIds($seeded, 'rent_and_shopping', 'subscription_food_and_shopping', 'rent_and_food', 'rent');

    $this->patchJson(route('transactions.bulk-update'), [
        'filters' => ['cashflow_block' => 'fixed', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'],
        'notes' => $note,
    ])->assertOk()->assertJsonPath('count', 4);

    expect(Transaction::query()->where('notes', $note)->pluck('id')->sort()->values()->all())
        ->toBe($fixed)
        ->toBe(idsListedInBlock(CashflowBlock::Fixed));
});

test('a select-all category change over a block leaves the general transactions alone', function () {
    $seeded = seedSplitCashflowMonth();
    $newCategory = splitBlockCategory(CategoryType::Expense);
    $food = $seeded['food']->category_id;

    $response = $this->patchJson(route('transactions.bulk-update'), [
        'filters' => ['cashflow_block' => 'fixed', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'],
        'category_id' => $newCategory->id,
    ])->assertOk();

    expect($response->json('updated_ids'))->toBe([$seeded['rent']->id])
        ->and(collect($response->json('skipped_split_ids'))->sort()->values()->all())
        ->toBe(splitSeededIds($seeded, 'rent_and_shopping', 'subscription_food_and_shopping', 'rent_and_food'))
        ->and($seeded['uncategorized_out']->fresh()->category_id)->toBeNull()
        ->and($seeded['food']->fresh()->category_id)->toBe($food)
        ->and(Transaction::query()->where('category_id', $newCategory->id)->pluck('id')->all())->toBe([$seeded['rent']->id]);
});

/**
 * October's summary figures, as the cashflow page reads them.
 *
 * @return array<string, int>
 */
function octoberCashflowSummary(): array
{
    return test()->getJson('/api/cashflow/summary?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->json('current');
}

test('an uncategorized outflow carrying a fixed budget label is fixed on both faces, not general', function () {
    $streaming = Label::factory()->create(['user_id' => $this->user->id]);
    splitBlockBudget(Budget::factory()->monthly()->fixed()->forLabels($streaming)->create(['user_id' => $this->user->id]));
    $subscription = splitBlockTransaction(null, -1299, label: $streaming);

    $summary = octoberCashflowSummary();
    $postings = cashflowPostingsOf([$subscription->id]);

    expect(idsListedInBlock(CashflowBlock::Fixed))->toBe([$subscription->id])
        ->and(idsListedInBlock(CashflowBlock::General))->toBe([])
        ->and(postingsInBlock($postings, CashflowBlock::Fixed)->sum('amount'))->toBe(-1299)
        ->and(postingsInBlock($postings, CashflowBlock::General))->toBeEmpty()
        ->and($summary['fixed_expense'])->toBe(1299)
        ->and($summary['general_expense'])->toBe(0);
});

test('a split transaction on an archived account after its archive date is in no block', function () {
    $rent = splitBlockCategory(CategoryType::Expense);
    $shopping = splitBlockCategory(CategoryType::Expense);
    splitBlockBudget(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]));
    $archived = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'archived_at' => '2026-10-05']);

    $split = splitBlockTransaction(null, 0, [[$rent, -30000], [$shopping, -2000]], account: $archived);

    $summary = octoberCashflowSummary();

    expect(cashflowPostingsOf([$split->id]))->toBeEmpty()
        ->and($summary['expense'])->toBe(0)
        ->and($summary['fixed_expense'])->toBe(0)
        ->and($summary['general_expense'])->toBe(0);

    foreach (CashflowBlock::cases() as $block) {
        expect(idsListedInBlock($block))->not->toContain($split->id, "block {$block->value}");
    }
});

test('a split transaction is listed under the blocks of its lines only when it is dated in the period', function () {
    $rent = splitBlockCategory(CategoryType::Expense);
    $shopping = splitBlockCategory(CategoryType::Expense);
    splitBlockBudget(Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $this->user->id]));

    $lines = [[$rent, -30000], [$shopping, -2000]];
    $inPeriod = splitBlockTransaction(null, 0, $lines);
    $lastDay = splitBlockTransaction(null, 0, $lines);
    $lastDay->update(['transaction_date' => '2026-10-31']);
    $before = splitBlockTransaction(null, 0, $lines);
    $before->update(['transaction_date' => '2026-09-30']);
    $after = splitBlockTransaction(null, 0, $lines);
    $after->update(['transaction_date' => '2026-11-01']);

    $inside = collect([$inPeriod->id, $lastDay->id])->sort()->values()->all();
    $blocks = app(CashflowBlockQuery::class);
    $period = PeriodComparator::fromRequest(['from' => '2026-10-01', 'to' => '2026-10-31']);
    $applied = fn (CashflowBlock $block): array => $blocks->apply(Transaction::query(), $block, $this->user->id, $period)
        ->pluck('id')->sort()->values()->all();

    expect(idsListedInBlock(CashflowBlock::Fixed))->toBe($inside)
        ->and(idsListedInBlock(CashflowBlock::General))->toBe($inside)
        ->and(idsListedInBlock(CashflowBlock::Variable))->toBe([])
        ->and($applied(CashflowBlock::Fixed))->toBe($inside)
        ->and($applied(CashflowBlock::General))->toBe($inside)
        ->and($applied(CashflowBlock::Variable))->toBe([]);
});
