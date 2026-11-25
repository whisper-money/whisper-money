<?php

/**
 * A cross-feature sweep over the areas the upstream sync reconciled.
 *
 * Each case builds a realistic account set and then reads the same money
 * through every screen that reports it. That is where a reconciliation breaks:
 * each screen keeps passing its own test while quietly disagreeing with the one
 * beside it, so most checks here are equalities between screens rather than
 * absolute figures alone.
 */

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\TransactionSource;
use App\Features\TransactionSplitting;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\BudgetManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/** @return array{0: User, 1: mixed, 2: array<string, Account>, 3: array<string, Category>} */
function syncWorld(): array
{
    Http::fake();
    fakeCurrencyApi();

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    Feature::for($user)->activate(TransactionSplitting::class);

    $space = $user->personalSpace;

    $accounts = [
        'own' => Account::factory()->create([
            'user_id' => $user->id, 'space_id' => $space->id,
            'type' => AccountType::Checking, 'currency_code' => 'EUR',
            'ownership_percentage' => 100, 'name' => 'Own checking',
        ]),
        // Half owned: every figure off this account should count half, once.
        'shared' => Account::factory()->create([
            'user_id' => $user->id, 'space_id' => $space->id,
            'type' => AccountType::Checking, 'currency_code' => 'EUR',
            'ownership_percentage' => 50, 'name' => 'Shared checking',
        ]),
        'savings' => Account::factory()->create([
            'user_id' => $user->id, 'space_id' => $space->id,
            'type' => AccountType::Savings, 'currency_code' => 'EUR',
            'ownership_percentage' => 100, 'name' => 'Savings',
        ]),
    ];

    $categories = [
        'food' => Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => CategoryType::Expense, 'name' => 'Food']),
        'home' => Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => CategoryType::Expense, 'name' => 'Home']),
        'salary' => Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => CategoryType::Income, 'name' => 'Salary']),
    ];
    $categories['groceries'] = Category::factory()->create([
        'user_id' => $user->id, 'space_id' => $space->id,
        'type' => CategoryType::Expense, 'name' => 'Groceries',
        'parent_id' => $categories['food']->id,
    ]);

    return [$user, $space, $accounts, $categories];
}

/**
 * Posted through the real endpoint, so TransactionCreated fires and budget
 * assignment runs the way it does for a user typing one in. `splits` files it
 * under legacy split lines afterwards ({@see syncSplitIntoLines()}): the web
 * endpoints no longer write lines.
 *
 * @param  array<string, mixed>  $attributes
 */
function syncPostedTransaction(User $user, Account $account, array $attributes = []): Transaction
{
    $lines = $attributes['splits'] ?? null;
    unset($attributes['splits']);

    $payload = array_merge([
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'Sync '.Str::random(8),
        'transaction_date' => today()->toDateString(),
        'amount' => -1000,
        'currency_code' => $account->currency_code,
        'source' => 'manually_created',
    ], $attributes);

    actingAs($user)->postJson(route('transactions.store'), $payload)->assertSuccessful();

    $transaction = Transaction::query()->where('description', $payload['description'])->firstOrFail();

    if ($lines !== null) {
        syncSplitIntoLines($transaction, $lines);
    }

    return $transaction->fresh();
}

/**
 * File a transaction under legacy split lines, as the line editor did before
 * splits became parts: the lines, then a save that fires the update event the
 * budgets are reassigned on.
 *
 * @param  array<int, array{category_id: string, amount: int}>  $lines
 */
function syncSplitIntoLines(Transaction $transaction, array $lines): void
{
    foreach (array_values($lines) as $position => $line) {
        TransactionSplit::factory()->create([...$line, 'transaction_id' => $transaction->id, 'position' => $position]);
    }

    $transaction->touch();
}

/** The period that actually covers today, not whichever row comes back first. */
function syncCurrentPeriod(Budget $budget): BudgetPeriod
{
    return $budget->periods()
        ->whereDate('start_date', '<=', today())
        ->whereDate('end_date', '>=', today())
        ->firstOrFail();
}

/** @return array<string, string> */
function syncWindow(): array
{
    return [
        'from' => today()->startOfMonth()->toDateString(),
        'to' => today()->endOfMonth()->toDateString(),
    ];
}

function syncDashboardExpense(User $user): int
{
    return actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'cashflowSummary',
    ])->assertOk()->json('props.cashflowSummary.current.expense');
}

/** @return array<string, mixed> */
function syncCashflowSummary(User $user): array
{
    return actingAs($user)
        ->getJson('/api/cashflow/summary?'.http_build_query(syncWindow()))
        ->assertOk()
        ->json('current');
}

/**
 * @param  array<string, mixed>  $categories
 * @return array<int, array<string, mixed>>
 */
function syncSplitOf(array $categories, int $first = -6000, int $second = -4000): array
{
    return [
        ['category_id' => $categories['groceries']->id, 'amount' => $first],
        ['category_id' => $categories['home']->id, 'amount' => $second],
    ];
}

it('reports the same cashflow on the dashboard widget and the cashflow screen', function () {
    [$user, , $accounts, $categories] = syncWorld();

    syncPostedTransaction($user, $accounts['own'], ['amount' => -10000, 'category_id' => $categories['food']->id]);
    syncPostedTransaction($user, $accounts['own'], ['amount' => -10000, 'splits' => syncSplitOf($categories)]);
    syncPostedTransaction($user, $accounts['shared'], ['amount' => -10000, 'category_id' => $categories['food']->id]);
    syncPostedTransaction($user, $accounts['shared'], ['amount' => -10000, 'splits' => syncSplitOf($categories)]);
    syncPostedTransaction($user, $accounts['own'], ['amount' => 300000, 'category_id' => $categories['salary']->id]);

    $screen = syncCashflowSummary($user);

    // 100 own + 100 own split + 50 shared + 50 shared split.
    expect($screen['expense'])->toBe(30000)
        ->and($screen['income'])->toBe(300000)
        ->and(syncDashboardExpense($user))->toBe($screen['expense']);
})->group('sync');

it('makes the sankey add up to what the summary reports', function () {
    [$user, , $accounts, $categories] = syncWorld();

    syncPostedTransaction($user, $accounts['own'], ['amount' => -10000, 'category_id' => $categories['food']->id]);
    syncPostedTransaction($user, $accounts['shared'], ['amount' => -10000, 'category_id' => $categories['home']->id]);
    syncPostedTransaction($user, $accounts['own'], ['amount' => 200000, 'category_id' => $categories['salary']->id]);
    syncPostedTransaction($user, $accounts['shared'], ['amount' => -10000, 'splits' => syncSplitOf($categories)]);

    $summary = syncCashflowSummary($user);
    $sankey = actingAs($user)->getJson('/api/cashflow/sankey?'.http_build_query(syncWindow()))->assertOk()->json();

    expect($summary['expense'])->toBe(20000)
        ->and($sankey['total_expense'])->toBe($summary['expense'])
        ->and($sankey['total_income'])->toBe($summary['income'])
        ->and(collect($sankey['expense_categories'])->sum('amount'))->toBe($summary['expense'])
        ->and(collect($sankey['income_categories'])->sum('amount'))->toBe($summary['income']);
})->group('sync');

it('nets a refund against the category of a split line on every cashflow screen', function () {
    [$user, , $accounts, $categories] = syncWorld();

    // 60 groceries and 40 home in one split, then 80 back against home: home
    // nets to +40, a refund bigger than what it refunds.
    syncPostedTransaction($user, $accounts['own'], ['amount' => -10000, 'splits' => syncSplitOf($categories)]);
    syncPostedTransaction($user, $accounts['own'], ['amount' => 8000, 'category_id' => $categories['home']->id]);

    $summary = syncCashflowSummary($user);
    $sankey = actingAs($user)->getJson('/api/cashflow/sankey?'.http_build_query(syncWindow()))->assertOk()->json();
    $breakdown = actingAs($user)
        ->getJson('/api/cashflow/breakdown?'.http_build_query([...syncWindow(), 'type' => 'expense']))
        ->assertOk()
        ->json();

    $homeRow = collect($breakdown['data'])->firstWhere('category_id', $categories['home']->id);
    $homeNode = collect($sankey['income_categories'])->firstWhere('category_id', $categories['home']->id);

    expect($summary['expense'])->toBe(2000)
        ->and($homeRow['amount'])->toBe(-4000)
        ->and($breakdown['total'])->toBe($summary['expense'])
        ->and($homeNode['amount'])->toBe(4000)
        ->and($homeNode['category']['name'])->toBe('Home (refund)')
        ->and($sankey['total_income'] - $sankey['total_expense'])->toBe($summary['income'] - $summary['expense']);
})->group('sync');

it('weighs a foreign-currency split on a shared account exactly once', function () {
    [$user, , , $categories] = syncWorld();

    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => today()->toDateString(),
        'rates' => ['usd' => 1.25],
    ]);

    $usdShared = Account::factory()->create([
        'user_id' => $user->id, 'space_id' => $user->personalSpace->id,
        'type' => AccountType::Checking, 'currency_code' => 'USD',
        'ownership_percentage' => 50, 'name' => 'Shared USD',
    ]);

    // 100 USD at 1.25 is 80 EUR; half owned makes it 40 EUR, split or not.
    syncPostedTransaction($user, $usdShared, ['amount' => -10000, 'category_id' => $categories['food']->id]);
    syncPostedTransaction($user, $usdShared, ['amount' => -10000, 'splits' => syncSplitOf($categories)]);

    expect(syncCashflowSummary($user)['expense'])->toBe(8000);
})->group('sync');

it('keeps both screens agreeing while a split is made, redone and merged', function () {
    [$user, , $accounts, $categories] = syncWorld();

    $transaction = syncPostedTransaction($user, $accounts['own'], [
        'amount' => -10000,
        'category_id' => $categories['food']->id,
    ]);

    $read = fn (): array => [syncDashboardExpense($user), syncCashflowSummary($user)['expense']];
    $split = fn (int $first, int $second): string => actingAs($user)
        ->postJson(route('transactions.split.store', $transaction), ['splits' => syncSplitOf($categories, $first, $second)])
        ->assertCreated()
        ->json('data.0.id');
    $merge = fn (string $part) => actingAs($user)
        ->deleteJson(route('transactions.split.destroy', $part))
        ->assertSuccessful();

    expect($read())->toBe([10000, 10000]);

    $part = $split(-6000, -4000);

    expect($read())->toBe([10000, 10000]);

    // Re-split with different proportions (merge, then split again): the
    // total must not move.
    $merge($part);
    $part = $split(-2500, -7500);

    expect($read())->toBe([10000, 10000]);

    // Merge for good: back to the one categorised row, same total.
    $merge($part);

    expect($read())->toBe([10000, 10000])
        ->and(Transaction::query()->where('split_parent_id', $transaction->id)->count())->toBe(0)
        ->and($transaction->fresh()->category_id)->toBe($categories['food']->id);
})->group('sync');

it('assigns split postings to the budget tracking their category, at the owner share', function () {
    [$user, , $accounts, $categories] = syncWorld();

    // The budget tracks the parent; the split posting lands on its child.
    actingAs($user)->post(route('budgets.store'), [
        'name' => 'Food budget', 'period_type' => 'monthly', 'period_start_day' => 1,
        'rollover_type' => 'reset', 'allocated_amount' => 50000,
        'category_ids' => [$categories['food']->id],
    ])->assertRedirect();

    syncPostedTransaction($user, $accounts['shared'], ['amount' => -10000, 'splits' => syncSplitOf($categories)]);

    $budget = Budget::query()->where('name', 'Food budget')->firstOrFail();

    // Only the Groceries half counts (60.00), and the account is half owned.
    expect((int) syncCurrentPeriod($budget)->budgetTransactions()->sum('amount'))->toBe(3000);
})->group('sync');

it('releases a budget category back to the catch-all once the budget is archived', function () {
    [$user, , $accounts, $categories] = syncWorld();

    actingAs($user)->post(route('budgets.store'), [
        'name' => 'Food budget', 'period_type' => 'monthly', 'period_start_day' => 1,
        'rollover_type' => 'reset', 'allocated_amount' => 50000,
        'category_ids' => [$categories['food']->id],
    ])->assertRedirect();
    actingAs($user)->post(route('budgets.store'), [
        'name' => 'Everything else', 'period_type' => 'monthly', 'period_start_day' => 1,
        'rollover_type' => 'reset', 'allocated_amount' => 100000, 'is_catch_all' => true,
    ])->assertRedirect();

    $tracked = Budget::query()->where('name', 'Food budget')->firstOrFail();
    $catchAll = Budget::query()->where('is_catch_all', true)->firstOrFail();

    actingAs($user)->post(route('budgets.archive', $tracked))->assertRedirect();

    syncPostedTransaction($user, $accounts['own'], ['amount' => -7000, 'category_id' => $categories['food']->id]);

    expect((int) syncCurrentPeriod($tracked)->budgetTransactions()->sum('amount'))->toBe(0)
        ->and((int) syncCurrentPeriod($catchAll)->budgetTransactions()->sum('amount'))->toBe(7000);

    // Read-only from here on: refused by the policy before the request reaches
    // the service, and by the service for any caller that skips the policy.
    actingAs($user)->patch(route('budgets.update', $tracked), ['name' => 'Renamed'])->assertForbidden();
    expect($tracked->fresh()->name)->toBe('Food budget')
        ->and(fn () => app(BudgetManagementService::class)->update(
            $user, $tracked->space, $tracked->id, ['name' => 'Renamed'], CarbonImmutable::today(),
        ))->toThrow(ValidationException::class);
})->group('sync');

it('sends the Planning list the spend its severity calculation reads', function () {
    [$user, , $accounts, $categories] = syncWorld();

    actingAs($user)->post(route('budgets.store'), [
        'name' => 'Food budget', 'period_type' => 'monthly', 'period_start_day' => 1,
        'rollover_type' => 'reset', 'allocated_amount' => 10000,
        'category_ids' => [$categories['food']->id],
    ])->assertRedirect();

    // 95%: over this fork's close-to-limit threshold, under the limit itself.
    syncPostedTransaction($user, $accounts['own'], ['amount' => -9500, 'category_id' => $categories['food']->id]);

    $period = actingAs($user)->get(route('budgets.index'), ['X-Inertia' => 'true'])
        ->assertOk()->json('props.budgets.0.periods.0');

    // The index serialises spent_amount and status and does not ship the
    // transaction rows, so a severity that only knew how to sum those rows
    // would read 0% for every budget and flatten the list's colour and order.
    expect($period['allocated_amount'])->toBe(10000)
        ->and($period['spent_amount'])->toBe(9500)
        ->and($period['status'])->toBe('close_to_limit')
        ->and($period)->toHaveKey('carried_over_amount');
})->group('sync');

it('persists a Planning reorder across a reload', function () {
    [$user] = syncWorld();

    foreach (['Small' => 10000, 'Large' => 90000, 'Middle' => 50000] as $name => $amount) {
        actingAs($user)->post(route('budgets.store'), [
            'name' => $name, 'period_type' => 'monthly', 'period_start_day' => 1,
            'rollover_type' => 'reset', 'allocated_amount' => $amount,
            'label_ids' => [Label::factory()->create([
                'user_id' => $user->id, 'space_id' => $user->personalSpace->id,
            ])->id],
        ])->assertRedirect();
    }

    $ids = collect(actingAs($user)->get(route('budgets.index'), ['X-Inertia' => 'true'])
        ->assertOk()->json('props.budgets'))->pluck('id')->all();

    $reordered = [$ids[2], $ids[0], $ids[1]];
    actingAs($user)->patch(route('planning.reorder'), [
        'items' => collect($reordered)->map(fn (string $id): array => ['id' => $id, 'type' => 'budget'])->all(),
    ])->assertRedirect();

    $after = actingAs($user)->get(route('budgets.index'), ['X-Inertia' => 'true'])
        ->assertOk()->json('props.budgets');

    expect(collect($after)->pluck('id')->all())->toBe($reordered);
})->group('sync');

it('counts a savings-account inflow and a checking outflow as contributions alike', function () {
    [$user, , $accounts] = syncWorld();

    actingAs($user)->post(route('savings-goals.store'), [
        'name' => 'Japan trip', 'target_amount' => 100000, 'initial_amount' => 5000,
    ])->assertRedirect();

    $goal = SavingsGoal::query()->where('name', 'Japan trip')->firstOrFail();

    // Money arriving in the savings account is a contribution, and so is money
    // leaving a checking account towards it. A withdrawal takes it away again.
    foreach ([[$accounts['savings'], 20000], [$accounts['own'], -15000], [$accounts['savings'], -5000]] as [$account, $amount]) {
        syncPostedTransaction($user, $account, ['amount' => $amount])->labels()->attach($goal->label_id);
    }

    expect($goal->fresh()->savedAmountInCents())->toBe(35000);
})->group('sync');

it('drills the analysis into sub-categories and keeps the descendant split posting', function () {
    [$user, , $accounts, $categories] = syncWorld();

    syncPostedTransaction($user, $accounts['own'], [
        'amount' => -10000,
        'transaction_date' => today()->subDay()->toDateString(),
        'splits' => syncSplitOf($categories),
    ]);

    $response = actingAs($user)->getJson('/api/transactions/analysis?'.http_build_query([
        'category_ids' => [$categories['food']->id],
    ]))->assertOk();

    // Filtering by Food keeps the Groceries half; Home sits outside the subtree.
    expect($response->json('summary.expense'))->toBe(6000)
        ->and($response->json('by_category.0.name'))->toBe('Groceries')
        ->and($response->json('by_category.0.amount'))->toBe(6000);
})->group('sync');

it('keeps the source date when a bank transaction is moved to another month', function () {
    [$user, , $accounts, $categories] = syncWorld();

    $bankRow = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $accounts['own']->space_id,
        'account_id' => $accounts['own']->id,
        'currency_code' => 'EUR',
        'amount' => -8000,
        'category_id' => $categories['food']->id,
        'source' => TransactionSource::EnableBanking,
        'transaction_date' => today()->startOfMonth(),
    ]);

    actingAs($user)->patchJson(route('transactions.update', $bankRow), [
        'transaction_date' => today()->addMonth()->startOfMonth()->toDateString(),
    ])->assertSuccessful();

    $moved = $bankRow->fresh();

    // The user's month wins, but the bank's own day is kept so the sync
    // watermark does not advance with the move.
    expect($moved->transaction_date->toDateString())->toBe(today()->addMonth()->startOfMonth()->toDateString())
        ->and($moved->source_date?->toDateString())->toBe(today()->startOfMonth()->toDateString());
})->group('sync');

it('stops counting an archived account from its archive date but keeps the history', function () {
    $this->travelTo(now()->startOfMonth()->addDays(14));

    [$user, , $accounts, $categories] = syncWorld();

    syncPostedTransaction($user, $accounts['own'], [
        'amount' => -5000, 'category_id' => $categories['food']->id,
        'transaction_date' => today()->subDay()->toDateString(),
    ]);
    syncPostedTransaction($user, $accounts['own'], ['amount' => -3000, 'category_id' => $categories['food']->id]);

    expect(syncCashflowSummary($user)['expense'])->toBe(8000);

    actingAs($user)->patch(route('accounts.archived', $accounts['own']), ['archived' => true])->assertRedirect();

    // Yesterday survives, the archive day itself does not, and both screens agree.
    $after = syncCashflowSummary($user)['expense'];
    expect($after)->toBe(5000)
        ->and(syncDashboardExpense($user))->toBe($after);
})->group('sync');

it('uncategorizes transactions when their category is deleted by reparenting', function () {
    [$user, , $accounts, $categories] = syncWorld();

    syncPostedTransaction($user, $accounts['own'], ['amount' => -4000, 'category_id' => $categories['groceries']->id]);

    actingAs($user)->delete(route('categories.destroy', $categories['groceries']), ['strategy' => 'reparent'])
        ->assertRedirect();

    // The money is still spent and both screens still see it, with nothing left
    // pointing at a soft-deleted category — the split that made them disagree.
    $screen = syncCashflowSummary($user)['expense'];

    expect(Transaction::query()->where('user_id', $user->id)->value('category_id'))->toBeNull()
        ->and($screen)->toBe(4000)
        ->and(syncDashboardExpense($user))->toBe($screen);
})->group('sync');
