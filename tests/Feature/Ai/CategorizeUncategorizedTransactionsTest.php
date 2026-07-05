<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Jobs\CategorizeUncategorizedTransactionsJob;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategorizeTransactions;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\actingAs;

it('dispatches a backfill and returns job progress when consent is granted', function () {
    Bus::fake();
    $user = User::factory()->create();
    Transaction::factory()->plaintext()->count(2)->create([
        'user_id' => $user->id,
        'category_id' => null,
    ]);

    actingAs($user)->postJson(route('ai.consent.store'))
        ->assertOk()
        ->assertJson(['consented' => true])
        ->assertJsonPath('categorization.total', 2)
        ->assertJsonStructure(['categorization' => ['job_id', 'total']]);

    expect($user->hasActiveAiConsent())->toBeTrue();
    Bus::assertDispatched(CategorizeUncategorizedTransactionsJob::class);
});

it('does not dispatch a backfill when nothing is uncategorized', function () {
    Bus::fake();
    $user = User::factory()->create();
    $category = Category::factory()->for($user)->create();
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
    ]);

    actingAs($user)->postJson(route('ai.consent.store'))
        ->assertOk()
        ->assertJsonPath('categorization', null);

    Bus::assertNotDispatched(CategorizeUncategorizedTransactionsJob::class);
});

it('does not dispatch a backfill for a user without a paid plan', function () {
    config(['subscriptions.enabled' => true]);
    Bus::fake();
    $user = User::factory()->create();
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
    ]);

    actingAs($user)->postJson(route('ai.consent.store'))
        ->assertOk()
        ->assertJson(['consented' => true])
        ->assertJsonPath('categorization', null);

    expect($user->hasActiveAiConsent())->toBeTrue();
    Bus::assertNotDispatched(CategorizeUncategorizedTransactionsJob::class);
});

it('does not dispatch a backfill when AI categorization is disabled', function () {
    config(['ai_categorization.enabled' => false]);
    Bus::fake();
    $user = User::factory()->create();
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
    ]);

    actingAs($user)->postJson(route('ai.consent.store'))
        ->assertOk()
        ->assertJsonPath('categorization', null);

    Bus::assertNotDispatched(CategorizeUncategorizedTransactionsJob::class);
});

it('returns categorization progress from the status endpoint', function () {
    $user = User::factory()->create();
    Cache::put(
        CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($user->id, 'job-123'),
        ['status' => 'processing', 'processed' => 1, 'total' => 4, 'applied' => 1],
        now()->addHour(),
    );

    actingAs($user)->getJson(route('ai.categorization.status', 'job-123'))
        ->assertOk()
        ->assertJson(['status' => 'processing', 'processed' => 1, 'total' => 4, 'applied' => 1]);
});

it('returns 404 from the status endpoint for an unknown job', function () {
    $user = User::factory()->create();

    actingAs($user)->getJson(route('ai.categorization.status', 'missing'))
        ->assertNotFound();
});

it('does not leak another user\'s categorization progress', function () {
    $owner = User::factory()->create();
    Cache::put(
        CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($owner->id, 'job-123'),
        ['status' => 'processing', 'processed' => 1, 'total' => 4, 'applied' => 1],
        now()->addHour(),
    );

    $otherUser = User::factory()->create();

    actingAs($otherUser)->getJson(route('ai.categorization.status', 'job-123'))
        ->assertNotFound();
});

it('records progress while categorizing the uncategorized transactions', function () {
    $user = User::factory()->create();
    $user->recordAiConsent();
    $category = Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);

    $catalog = CategoryCatalog::forUser($user);
    $index = 0;
    while (
        ($id = $catalog->categoryIdForIndex($index)) !== null
        && $id !== $category->id
    ) {
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

    Transaction::factory()->plaintext()->count(2)->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => 'mercadona',
    ]);

    $jobId = 'job-run-1';
    app()->call([new CategorizeUncategorizedTransactionsJob($user, $jobId), 'handle']);

    $progress = Cache::get(CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($user->id, $jobId));

    expect($progress['status'])->toBe('done')
        ->and($progress['total'])->toBe(2)
        ->and($progress['processed'])->toBe(2)
        ->and($progress['applied'])->toBe(2);
});

it('marks the cache as failed and preserves counts when the job fails', function () {
    $user = User::factory()->create();
    $jobId = 'failed-job';
    Cache::put(
        CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($user->id, $jobId),
        ['status' => 'processing', 'processed' => 3, 'total' => 10, 'applied' => 2],
        now()->addHour(),
    );

    (new CategorizeUncategorizedTransactionsJob($user, $jobId))
        ->failed(new RuntimeException('boom'));

    $progress = Cache::get(CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($user->id, $jobId));

    expect($progress['status'])->toBe('failed')
        ->and($progress['processed'])->toBe(3)
        ->and($progress['total'])->toBe(10)
        ->and($progress['applied'])->toBe(2);
});

it('categorizes the most recent transactions first', function () {
    config(['ai_categorization.group_batch_size' => 1]);

    $user = User::factory()->create();
    $user->recordAiConsent();

    $oldest = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'transaction_date' => '2026-01-01',
    ]);
    $newest = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'transaction_date' => '2026-06-01',
    ]);
    $middle = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'transaction_date' => '2026-03-01',
    ]);

    $order = [];
    $this->mock(CategorizeTransactions::class, function ($mock) use (&$order) {
        $mock->shouldReceive('forTransactions')->andReturnUsing(function ($user, $transactions) use (&$order) {
            foreach ($transactions as $transaction) {
                $order[] = $transaction->id;
            }

            return [];
        });
    });

    app()->call([new CategorizeUncategorizedTransactionsJob($user, 'order-job'), 'handle']);

    expect($order)->toBe([$newest->id, $middle->id, $oldest->id]);
});

it('de-duplicates the backfill per user so a concurrent dispatch cannot double-bill', function () {
    $user = User::factory()->create();
    $job = new CategorizeUncategorizedTransactionsJob($user, 'job-x');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($user->id);
});
