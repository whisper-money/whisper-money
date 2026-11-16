<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategorySource;
use App\Jobs\CategorizeImportedTransactionsJob;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Listeners\AssignTransactionToBudget;
use App\Listeners\CategorizeTransactionWithAi;
use App\Mail\BudgetNotificationEmail;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategorizeTransactions;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FullImportFixtures as Fixtures;
use Tests\TestCase;

// The master switch is off by default on this instance (nobody can start an
// import); these tests run with it on and the onboarding window open.
beforeEach(function () {
    config(['full_import.enabled' => true]);
});

/**
 * An import of one categorized and two uncategorized movements.
 */
function runFullImportForAi(TestCase $test, User $user): Import
{
    $groceries = Fixtures::seeded($user, 'Groceries');

    return Fixtures::run($test, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'c0', 'action' => 'match', 'category_id' => $groceries->id],
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Mercadona', ['category_key' => 'c0']),
        Fixtures::row('a0', '2026-09-02', -200, 'Uber trip'),
        Fixtures::row('a0', '2026-09-03', -300, 'Netflix'),
    ]);
}

function perRowAiJobsQueued(): int
{
    return Queue::pushed(CallQueuedListener::class)
        ->filter(fn (CallQueuedListener $job): bool => $job->class === CategorizeTransactionWithAi::class)
        ->count();
}

it('never queues the per-transaction AI call for imported rows, and queues one batch instead', function () {
    $user = Fixtures::user();
    $user->recordAiConsent();

    Queue::fake([CategorizeImportedTransactionsJob::class, CallQueuedListener::class]);

    $import = runFullImportForAi($this, $user);

    expect(perRowAiJobsQueued())->toBe(0)
        ->and($import->stats['uncategorized'])->toBe(2)
        ->and($import->stats['ai']['status'])->toBe('queued');

    Queue::assertPushed(CategorizeImportedTransactionsJob::class, 1);
    Queue::assertPushed(CategorizeImportedTransactionsJob::class, fn (CategorizeImportedTransactionsJob $job): bool => $job->import->is($import));
});

it('still queues the per-transaction AI call for a row typed by hand', function () {
    $user = Fixtures::user();
    $user->recordAiConsent();

    Queue::fake([CallQueuedListener::class]);

    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'category_id' => null]);

    expect(perRowAiJobsQueued())->toBe(1);
});

it('queues no batch when the AI gate is closed', function () {
    $user = Fixtures::user(); // no AI consent

    Queue::fake([CategorizeImportedTransactionsJob::class]);

    $import = runFullImportForAi($this, $user);

    Queue::assertNotPushed(CategorizeImportedTransactionsJob::class);

    expect($import->stats['ai']['status'])->toBe('unavailable');
});

it('leaves a user still onboarding to the onboarding AI step', function () {
    $user = Fixtures::user(['onboarded_at' => null]);
    $user->recordAiConsent();

    Queue::fake([CategorizeImportedTransactionsJob::class]);

    $import = runFullImportForAi($this, $user);

    Queue::assertNotPushed(CategorizeImportedTransactionsJob::class);

    expect($import->stats['ai']['status'])->toBe('onboarding');
});

it('categorizes only the rows of its import that are still uncategorized', function () {
    $user = Fixtures::user();
    $user->recordAiConsent();

    Queue::fake([CategorizeImportedTransactionsJob::class]);

    $import = runFullImportForAi($this, $user);
    $elsewhere = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id])->id,
        'category_id' => null,
        'description' => 'Not part of the import',
    ]);

    $catalog = CategoryCatalog::forUser($user);
    $targetId = Fixtures::seeded($user, 'Online services')->id;
    $index = 0;

    while ($catalog->categoryIdForIndex($index) !== $targetId) {
        $index++;
    }

    $prompts = [];

    TransactionCategorizationAgent::fake(function (string $prompt) use ($index, &$prompts): array {
        $prompts[] = $prompt;
        preg_match_all('/"ref":"([0-9a-f-]+)"/', $prompt, $matches);

        return ['results' => array_map(fn (string $ref): array => [
            'ref' => $ref,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => false,
        ], $matches[1])];
    });

    app()->call([new CategorizeImportedTransactionsJob($import), 'handle']);

    $categorized = Transaction::query()->where('import_id', $import->id)->where('category_source', CategorySource::Ai)->pluck('description')->sort()->values()->all();

    expect($categorized)->toBe(['Netflix', 'Uber trip'])
        ->and($elsewhere->fresh()->category_id)->toBeNull()
        ->and(implode("\n", $prompts))->not->toContain('Not part of the import')
        ->and($import->fresh()->stats['ai'])->toMatchArray(['status' => 'done', 'total' => 2, 'applied' => 2])
        ->and($import->fresh()->stats['uncategorized'])->toBe(0);
});

it('does nothing when the gate closed while the batch waited', function () {
    $user = Fixtures::user();
    $user->recordAiConsent();

    Queue::fake([CategorizeImportedTransactionsJob::class]);

    $import = runFullImportForAi($this, $user);
    $user->revokeAiConsent();

    TransactionCategorizationAgent::fake(fn () => throw new RuntimeException('The model must not be called.'));

    app()->call([new CategorizeImportedTransactionsJob($import), 'handle']);

    expect($import->fresh()->stats['ai']['status'])->toBe('unavailable')
        ->and(Transaction::query()->where('import_id', $import->id)->whereNull('category_id')->count())->toBe(2);
});

/**
 * The model files every transaction it is shown under $target, confidently.
 */
function fakeAiFilingEverythingUnder(User $user, Category $target): void
{
    $catalog = CategoryCatalog::forUser($user);
    $index = 0;

    while ($catalog->categoryIdForIndex($index) !== $target->id) {
        $index++;
    }

    TransactionCategorizationAgent::fake(function (string $prompt) use ($index): array {
        preg_match_all('/"ref":"([0-9a-f-]+)"/', $prompt, $matches);

        return ['results' => array_map(fn (string $ref): array => [
            'ref' => $ref,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => false,
        ], $matches[1])];
    });
}

/**
 * A budget on $category, every email switched on, with a period in progress.
 */
function currentBudgetPeriodWithEmails(User $user, Category $category): BudgetPeriod
{
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'notify_on_new_transaction' => true,
        'notify_on_over_limit' => true,
        'notify_on_close_to_limit' => true,
    ]);

    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(10)->toDateString(),
        'end_date' => now()->addDays(20)->toDateString(),
        'allocated_amount' => 1000,
    ]);
}

it('files imported rows into budgets without budget emails, in one batch', function () {
    Mail::fake();
    $setUp = function (): array {
        $user = Fixtures::user();
        $user->recordAiConsent();
        $online = Fixtures::seeded($user, 'Online services');

        return [$user, $online, currentBudgetPeriodWithEmails($user, $online)];
    };
    $importFor = fn (User $user): Import => Fixtures::run(
        $this,
        $user,
        Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        [Fixtures::row('a0', now()->subDays(2)->toDateString(), -4250, 'Netflix')],
    );

    [$user, $online, $period] = $setUp();
    Queue::fake([CategorizeImportedTransactionsJob::class]);
    $import = $importFor($user);
    fakeAiFilingEverythingUnder($user, $online);

    app()->call([new CategorizeImportedTransactionsJob($import), 'handle']);

    $imported = Transaction::query()->where('import_id', $import->id)->sole();
    expect($imported->category_id)->toBe($online->id)
        ->and($imported->category_source)->toBe(CategorySource::Ai)
        ->and(BudgetTransaction::query()->where('budget_period_id', $period->id)->sum('amount'))->toEqual(4250);

    Mail::assertNotQueued(BudgetNotificationEmail::class);
    Mail::assertNotSent(BudgetNotificationEmail::class);

    // No per-row assignment with emails: one batch job, notify off.
    [$other, $otherOnline] = $setUp();
    $otherImport = $importFor($other);
    fakeAiFilingEverythingUnder($other, $otherOnline);
    Queue::fake([ReassignTransactionsToBudgets::class, CallQueuedListener::class]);

    app()->call([new CategorizeImportedTransactionsJob($otherImport), 'handle']);

    Queue::assertPushed(ReassignTransactionsToBudgets::class, 1);
    Queue::assertPushed(ReassignTransactionsToBudgets::class, fn (ReassignTransactionsToBudgets $job): bool => $job->notify === false
        && $job->transactionIds === [Transaction::query()->where('import_id', $otherImport->id)->sole()->id]);
    expect(Queue::pushed(CallQueuedListener::class)
        ->filter(fn (CallQueuedListener $job): bool => $job->class === AssignTransactionToBudget::class)
        ->count())->toBe(0);
});

it('still emails about a row typed by hand that the model files into a budget', function () {
    Mail::fake();
    $user = Fixtures::user();
    $user->recordAiConsent();
    $online = Fixtures::seeded($user, 'Online services');
    $period = currentBudgetPeriodWithEmails($user, $online);
    $account = Account::factory()->create(['user_id' => $user->id]);
    // Quietly, so no AI or budget listener runs before the pass under test.
    $typed = Transaction::factory()->plaintext()->createQuietly([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'space_id' => $account->space_id,
        'category_id' => null,
        'description' => 'Netflix',
        'amount' => -4250,
        'transaction_date' => now()->subDays(2)->toDateString(),
    ]);
    fakeAiFilingEverythingUnder($user, $online);

    app(CategorizeTransactions::class)->forTransactions($user, collect([$typed]));

    expect($typed->fresh()->category_id)->toBe($online->id)
        ->and(BudgetTransaction::query()->where('budget_period_id', $period->id)->sum('amount'))->toEqual(4250);

    Mail::assertQueued(BudgetNotificationEmail::class);
});
