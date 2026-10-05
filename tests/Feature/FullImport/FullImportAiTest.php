<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategorySource;
use App\Jobs\CategorizeImportedTransactionsJob;
use App\Listeners\CategorizeTransactionWithAi;
use App\Models\Account;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FullImportFixtures as Fixtures;
use Tests\TestCase;

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

    Transaction::factory()->create(['user_id' => $user->id, 'category_id' => null]);

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
