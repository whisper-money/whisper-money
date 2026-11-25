<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\CategoryType;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Features\TransactionSplitting;
use App\Listeners\AssignTransactionToBudget;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Ai\UncategorizedTransactionMatcher;
use App\Services\AutomationRuleService;
use App\Services\Banking\TransactionDescriptionFormatter;
use App\Services\Banking\TransactionSyncService;
use App\Services\BudgetTransactionService;
use App\Services\Transactions\EffectiveTransactionPostings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Account, 2: Transaction, 3: Category, 4: Category} */
function splitIntegrationFixture(array $transactionAttributes = []): array
{
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -10000,
        ...$transactionAttributes,
    ]);
    $food = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);
    $home = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);

    return [$user, $account, $transaction, $food, $home];
}

function makeIntegrationSplit(Transaction $transaction, Category $first, Category $second): void
{
    fileUnderLegacyLines($transaction, [
        ['category_id' => $first->id, 'amount' => -6000],
        ['category_id' => $second->id, 'amount' => -4000],
    ]);
}

/**
 * Replace the lines of a transaction split the old way with -2500 / -7500, as
 * the line editor did before splits became parts: the lines, then the parent
 * touched so the delta sync sees it.
 */
function replaceIntegrationSplitLines(Transaction $transaction, Category $first, Category $second): void
{
    $transaction->splits()->delete();
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $first->id, 'amount' => -2500, 'position' => 0]);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $second->id, 'amount' => -7500, 'position' => 1]);
    $transaction->forceFill(['updated_at' => now()])->saveQuietly();
}

it('uses either the parent or ordered split postings without double counting', function () {
    [, , $transaction, $food, $home] = splitIntegrationFixture();
    $service = app(EffectiveTransactionPostings::class);

    $normal = $service->forTransaction($transaction->load(['category', 'splits.category']));
    expect($normal)->toHaveCount(1)
        ->and($normal->sum('amount'))->toBe(-10000);

    makeIntegrationSplit($transaction, $food, $home);
    $postings = $service->forTransaction($transaction->refresh()->load(['category', 'splits.category']));

    expect($postings)->toHaveCount(2)
        ->and($postings->pluck('categoryId')->all())->toBe([$food->id, $home->id])
        ->and($postings->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($postings->sum('amount'))->toBe(-10000);
});

// Split lines are no longer written from the web: transaction CRUD ignores a
// `splits` payload, and a split is made of parts through its own endpoint.
it('creates, serializes, splits, and merges through transaction CRUD and the split endpoint', function () {
    [$user, $account, , $food, $home] = splitIntegrationFixture();

    $created = $this->actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'Split purchase',
        'transaction_date' => '2026-07-18',
        'amount' => -10000,
        'currency_code' => 'EUR',
        'source' => 'manually_created',
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.category_id', null)
        ->assertJsonPath('data.is_split', false)
        ->assertJsonPath('data.split_count', 0);

    $transaction = Transaction::query()->findOrFail($created->json('data.id'));

    $this->actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'description' => 'Still one row',
        'splits' => [
            ['category_id' => $food->id, 'amount' => -2500],
            ['category_id' => $home->id, 'amount' => -7500],
        ],
    ])->assertOk()->assertJsonPath('data.is_split', false);

    expect($transaction->splits()->count())->toBe(0);

    $parts = $this->actingAs($user)->postJson(route('transactions.split.store', $transaction), [
        'splits' => [
            ['category_id' => $food->id, 'amount' => -2500],
            ['category_id' => $home->id, 'amount' => -7500],
        ],
    ])->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.split_parent_id', $transaction->id)
        ->assertJsonPath('data.1.amount', -7500);

    $this->actingAs($user)->deleteJson(route('transactions.split.destroy', $parts->json('data.0.id')))
        ->assertOk()
        ->assertJsonPath('data.id', $transaction->id)
        ->assertJsonPath('data.amount', -10000);
});

it('emits one complete event per part and one for the original after a split', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    $observed = [];
    Event::listen(TransactionCreated::class, function (TransactionCreated $event) use (&$observed): void {
        $observed[] = ['created', $event->transaction->split_parent_id, $event->transaction->amount];
    });
    Event::listen(TransactionDeleted::class, function (TransactionDeleted $event) use (&$observed): void {
        $observed[] = ['deleted', $event->transaction->id, $event->transaction->amount];
    });
    Event::listen(TransactionUpdated::class, function (TransactionUpdated $event) use (&$observed): void {
        $observed[] = ['updated', $event->transaction->id, $event->transaction->amount];
    });

    $this->actingAs($user)->postJson(route('transactions.split.store', $transaction), [
        'splits' => [
            ['category_id' => $food->id, 'amount' => -7000],
            ['category_id' => $home->id, 'amount' => -3000],
        ],
    ])->assertCreated();

    expect($observed)->toBe([
        ['created', $transaction->id, -7000],
        ['created', $transaction->id, -3000],
        ['deleted', $transaction->id, -10000],
    ]);
});

it('blocks split creation while the flag is off but still allows merging', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    $lines = [
        ['category_id' => $food->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ];
    $part = $this->actingAs($user)->postJson(route('transactions.split.store', $transaction), ['splits' => $lines])
        ->assertCreated()
        ->json('data.0.id');

    Feature::for($user)->deactivate(TransactionSplitting::class);

    $other = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'account_id' => $transaction->account_id,
        'category_id' => null,
        'amount' => -10000,
    ]);

    $this->actingAs($user)->postJson(route('transactions.split.store', $other), ['splits' => $lines])
        ->assertForbidden();

    $this->actingAs($user)->deleteJson(route('transactions.split.destroy', $part))
        ->assertOk()
        ->assertJsonPath('data.id', $transaction->id);

    expect(Transaction::query()->where('split_parent_id', $other->id)->count())->toBe(0);
});

it('rejects implicit category or amount mutations on split parents', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $food->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('splits');

    $this->actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'amount' => -9000,
    ])->assertUnprocessable()->assertJsonValidationErrors('splits');

    expect($transaction->refresh()->amount)->toBe(-10000)
        ->and($transaction->category_id)->toBeNull()
        ->and((int) $transaction->splits()->sum('amount'))->toBe(-10000);
});

it('rejects malformed split payloads at the HTTP boundary', function () {
    [$user, , $transaction, $food] = splitIntegrationFixture();
    $stranger = Category::factory()->create();

    $this->actingAs($user)->postJson(route('transactions.split.store', $transaction), [
        'splits' => 'not a list',
    ])->assertUnprocessable()->assertJsonValidationErrors('splits');

    $this->actingAs($user)->postJson(route('transactions.split.store', $transaction), [
        'splits' => [
            ['category_id' => $food->id],
            ['category_id' => $stranger->id, 'amount' => -4000],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['splits.0.amount', 'splits.1.category_id']);

    expect(Transaction::query()->find($transaction->id))->not->toBeNull()
        ->and(Transaction::query()->where('split_parent_id', $transaction->id)->count())->toBe(0)
        ->and($transaction->fresh()->splits()->count())->toBe(0);
});

it('filters split parents once and excludes them from uncategorized and bulk category updates', function () {
    fakeCurrencyApi();

    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->get(route('transactions.index', ['category_ids' => $food->id]))
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.id', $transaction->id));

    $this->actingAs($user)->get(route('transactions.index', ['category_ids' => 'uncategorized']))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 0));

    $this->actingAs($user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$transaction->id],
        'category_id' => $food->id,
    ])->assertOk()
        ->assertJsonPath('updated_count', 0)
        ->assertJsonPath('skipped_split_count', 1);

    expect($transaction->refresh()->category_id)->toBeNull()
        ->and($transaction->splits()->count())->toBe(2);
});

it('reports only rows actually changed by a bulk category update', function () {
    [$user, , $transaction, $food] = splitIntegrationFixture([
        'category_id' => null,
    ]);
    $transaction->update([
        'category_id' => $food->id,
        'category_source' => 'manual',
    ]);

    $this->actingAs($user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$transaction->id],
        'category_id' => $food->id,
    ])->assertOk()
        ->assertJsonPath('updated_count', 0)
        ->assertJsonPath('skipped_split_count', 0);
});

it('builds authoritative bulk records before releasing row locks', function () {
    [$user, , $transaction, $food] = splitIntegrationFixture();
    $baselineTransactionLevel = DB::transactionLevel();
    $authoritativeQueryLevels = [];

    DB::listen(function (QueryExecuted $query) use ($transaction, &$authoritativeQueryLevels): void {
        // Scoped to the user-filtered read: the bulk update also queues a
        // budget reassignment, whose own pass over `transactions` runs after
        // the locks are released, as it should.
        if (
            str_contains($query->sql, 'from `transactions`')
            && str_contains($query->sql, '`user_id` = ?')
            && ! str_contains($query->sql, 'for update')
            && in_array($transaction->id, $query->bindings, true)
        ) {
            $authoritativeQueryLevels[] = DB::transactionLevel();
        }
    });

    $this->actingAs($user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$transaction->id],
        'category_id' => $food->id,
    ])->assertOk()->assertJsonCount(1, 'transactions');

    expect($authoritativeQueryLevels)->not->toBeEmpty()
        ->and(collect($authoritativeQueryLevels)->every(
            fn (int $level): bool => $level > $baselineTransactionLevel,
        ))->toBeTrue();
});

it('counts notes and label changes while skipping split category changes', function () {
    [$user, $account, $splitTransaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($splitTransaction, $food, $home);
    $regularTransaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'category_id' => $food->id,
    ]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$splitTransaction->id, $regularTransaction->id],
        'category_id' => $home->id,
        'notes' => 'Shared note',
        'label_ids' => [$label->id],
    ])->assertOk()
        ->assertJsonPath('updated_count', 2)
        ->assertJsonPath('skipped_split_count', 1)
        ->assertJsonPath('skipped_split_ids.0', $splitTransaction->id)
        ->assertJsonCount(2, 'updated_ids')
        ->assertJsonCount(2, 'transactions');

    $expectedIds = collect([$splitTransaction->id, $regularTransaction->id])->sort()->values()->all();
    expect(collect($response->json('updated_ids'))->sort()->values()->all())->toBe($expectedIds)
        ->and(collect($response->json('transactions'))->pluck('id')->sort()->values()->all())->toBe($expectedIds);

    expect($splitTransaction->refresh()->category_id)->toBeNull()
        ->and($splitTransaction->notes)->toBe('Shared note')
        ->and($splitTransaction->labels()->pluck('labels.id')->all())->toBe([$label->id])
        ->and($regularTransaction->refresh()->category_id)->toBe($home->id)
        ->and($regularTransaction->notes)->toBe('Shared note')
        ->and($regularTransaction->labels()->pluck('labels.id')->all())->toBe([$label->id]);
});

it('exposes split details in incremental sync and excludes split parents from AI candidates', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->getJson('/api/sync/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.id', $transaction->id)
        ->assertJsonPath('data.0.is_split', true)
        ->assertJsonPath('data.0.split_count', 2)
        ->assertJsonPath('data.0.splits.0.category.name', $food->name);

    expect(app(UncategorizedTransactionMatcher::class)->total($user))->toBe(0);
});

it('protects split classification from automation category actions', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);
    $replacement = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'action_category_id' => $replacement->id,
    ]);

    $changed = app(AutomationRuleService::class)
        ->applyRuleActionsToTransactions(new Collection([$transaction]), $rule);

    expect($changed)->toBe(0)
        ->and($transaction->refresh()->category_id)->toBeNull()
        ->and($transaction->splits()->count())->toBe(2);
});

it('does not alter an existing split parent when bank sync sees its duplicate', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'split-account',
    ]);
    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'external_transaction_id' => 'split-bank-transaction',
        'amount' => -10000,
    ]);
    $food = Category::factory()->create(['user_id' => $user->id, 'space_id' => $transaction->space_id, 'type' => CategoryType::Expense]);
    $home = Category::factory()->create(['user_id' => $user->id, 'space_id' => $transaction->space_id, 'type' => CategoryType::Expense]);
    makeIntegrationSplit($transaction, $food, $home);

    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getTransactions')->once()->andReturn([
        'transactions' => [[
            'transaction_id' => 'split-bank-transaction',
            'transaction_amount' => ['amount' => '999.00', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'booking_date' => '2026-07-18',
            'remittance_information' => ['Duplicate changed upstream'],
        ]],
        'continuation_key' => null,
    ]);

    $created = (new TransactionSyncService(
        $provider,
        new TransactionDescriptionFormatter,
    ))->sync($account, '2026-07-01', '2026-07-31');

    expect($created)->toBe(0)
        ->and($transaction->refresh()->amount)->toBe(-10000)
        ->and($transaction->splits()->pluck('amount')->all())->toBe([-6000, -4000]);
});

it('allocates only matching split portions to category budgets', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture([
        'transaction_date' => now(),
    ]);
    makeIntegrationSplit($transaction, $food, $home);

    $foodBudget = Budget::factory()->forCategories($food)->create(['user_id' => $user->id]);
    $homeBudget = Budget::factory()->forCategories($home)->create(['user_id' => $user->id]);
    $foodPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $foodBudget->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $homePeriod = BudgetPeriod::factory()->create([
        'budget_id' => $homeBudget->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    app(BudgetTransactionService::class)->assignTransaction($transaction);

    expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(6000)
        ->and((int) $homePeriod->budgetTransactions()->sole()->amount)->toBe(4000);
});

it('uses only the matching split portion in category-filtered analysis', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture([
        'transaction_date' => '2026-07-18',
        'currency_code' => 'USD',
    ]);
    $user->update(['currency_code' => 'USD']);
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->getJson('/api/transactions/analysis?'.http_build_query([
        'category_ids' => $food->id,
    ]))->assertOk()
        ->assertJsonPath('summary.expense', 6000)
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('by_category.0.amount', 6000)
        ->assertJsonPath('largest_expenses.0.amount', 10000)
        ->assertJsonPath('largest_expenses.0.category.name', 'Split');
});

it('keeps descendant split postings when analysis filters by a parent category', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture([
        'transaction_date' => '2026-07-18',
        'currency_code' => 'USD',
    ]);
    $user->update(['currency_code' => 'USD']);
    $groceries = Category::factory()->childOf($food)->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
    ]);
    fileUnderLegacyLines($transaction, [
        ['category_id' => $groceries->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);

    // Filtering by a parent drills the breakdown into its children (#875), so
    // the descendant posting is reported under Groceries rather than rolled up
    // into Food. What matters here is that it survives the filter at all: the
    // other half of the split sits outside the subtree and is dropped.
    $this->actingAs($user)->getJson('/api/transactions/analysis?'.http_build_query([
        'category_ids' => $food->id,
    ]))->assertOk()
        ->assertJsonPath('summary.expense', 6000)
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('by_category.0.category_id', $groceries->id)
        ->assertJsonPath('by_category.0.amount', 6000);
});

it('exposes split-aware MCP reads and refuses direct category writes until the lines are converted', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['category_id' => $food->id])
        ->assertOk()
        ->assertSee($transaction->id)
        ->assertSee('"is_split":true', false)
        ->assertSee('"id":"'.$transaction->splits()->orderBy('position')->firstOrFail()->id.'"', false)
        ->assertSee('"category_id":"'.$food->id.'"', false)
        ->assertSee('"amount":-6000', false)
        ->assertSee('"position":0', false)
        ->assertSee('"category_id":"'.$home->id.'"', false)
        ->assertSee('"amount":-4000', false)
        ->assertSee('"position":1', false);

    WhisperMoneyServer::actingAs($user)
        ->tool(CategorizeTransaction::class, [
            'transaction_id' => $transaction->id,
            'category_id' => $food->id,
        ])
        ->assertHasErrors(['still split into lines the old way']);

    expect($transaction->refresh()->category_id)->toBeNull()
        ->and($transaction->splits()->count())->toBe(2);
});

it('preserves archived budget snapshots when replacing split lines', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture(['transaction_date' => now()]);
    makeIntegrationSplit($transaction, $food, $home);

    $budget = Budget::factory()->forCategories($food)->create(['user_id' => $user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $service = app(BudgetTransactionService::class);
    $service->assignTransaction($transaction);

    $assignment = $period->budgetTransactions()->sole();
    $originalId = $assignment->id;
    $originalAmount = (int) $assignment->amount;

    $replacement = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);
    $budget->update(['archived_at' => now()]);
    Transaction::withoutEvents(fn () => fileUnderLegacyLines($transaction, [
        ['category_id' => $home->id, 'amount' => -6000],
        ['category_id' => $replacement->id, 'amount' => -4000],
    ]));

    $service->assignTransaction($transaction->fresh());

    $preserved = $period->budgetTransactions()->sole();
    expect($preserved->id)->toBe($originalId)
        ->and((int) $preserved->amount)->toBe($originalAmount);
});

it('returns replaced split details through delta sync', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);
    $cursor = now()->subDay();
    Transaction::withoutEvents(fn () => $transaction->forceFill(['updated_at' => $cursor])->saveQuietly());

    replaceIntegrationSplitLines($transaction, $food, $home);

    $this->actingAs($user)->getJson('/api/sync/transactions?since='.urlencode($cursor->toISOString()))
        ->assertOk()
        ->assertJsonPath('data.0.id', $transaction->id)
        ->assertJsonPath('data.0.is_split', true)
        ->assertJsonPath('data.0.split_count', 2)
        ->assertJsonPath('data.0.splits.0.amount', -2500)
        ->assertJsonPath('data.0.splits.1.amount', -7500);
});

it('reassigns budgets without stale or duplicate rows after split replacement', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture(['transaction_date' => now()]);
    makeIntegrationSplit($transaction, $food, $home);
    $foodBudget = Budget::factory()->forCategories($food)->create(['user_id' => $user->id]);
    $homeBudget = Budget::factory()->forCategories($home)->create(['user_id' => $user->id]);
    $foodPeriod = BudgetPeriod::factory()->create(['budget_id' => $foodBudget->id, 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $homePeriod = BudgetPeriod::factory()->create(['budget_id' => $homeBudget->id, 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    app(BudgetTransactionService::class)->assignTransaction($transaction);

    replaceIntegrationSplitLines($transaction, $food, $home);

    app(AssignTransactionToBudget::class)->handle(new TransactionUpdated($transaction->fresh()));

    expect($foodPeriod->budgetTransactions()->sole()->amount)->toBe(2500)
        ->and($homePeriod->budgetTransactions()->sole()->amount)->toBe(7500)
        ->and($transaction->budgetTransactions()->count())->toBe(2);
});
