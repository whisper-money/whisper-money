<?php

use App\Jobs\ReEvaluateTransactionRulesJob;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->bank = Bank::factory()->create(['name' => 'Test Bank', 'user_id' => $this->user->id]);
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'name' => 'Checking Account',
        'encrypted' => false,
    ]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
});

// ──────────────────────────────────────────────
// Single re-evaluate endpoint
// ──────────────────────────────────────────────

test('single endpoint applies matching rule and returns updated transaction', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store Purchase',
        'amount' => -5000,
        'category_id' => null,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.single', $transaction));

    $response->assertOk()
        ->assertJsonPath('data.category_id', $this->category->id);

    expect($transaction->fresh()->category_id)->toBe($this->category->id);
});

test('single endpoint assigns labels from matching rule', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    AutomationRule::factory()
        ->hasAttached($label, [], 'labels')
        ->create([
            'user_id' => $this->user->id,
            'priority' => 1,
            'rules_json' => ['in' => ['netflix', ['var' => 'description']]],
            'action_category_id' => null,
        ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Netflix subscription',
        'amount' => -1500,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.single', $transaction))
        ->assertOk();

    expect($transaction->fresh()->labels->pluck('id'))->toContain($label->id);
});

test('single endpoint applies plain action_note from matching rule', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['spotify', ['var' => 'description']]],
        'action_category_id' => null,
        'action_note' => 'Streaming service',
        'action_note_iv' => null,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Spotify Premium',
        'amount' => -999,
        'notes' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.single', $transaction))
        ->assertOk();

    expect($transaction->fresh()->notes)->toBe('Streaming service');
});

test('single endpoint does not duplicate a note already present', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['spotify', ['var' => 'description']]],
        'action_category_id' => null,
        'action_note' => 'Streaming service',
        'action_note_iv' => null,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Spotify Premium',
        'amount' => -999,
        'notes' => 'Streaming service',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.single', $transaction))
        ->assertOk();

    expect($transaction->fresh()->notes)->toBe('Streaming service');
});

test('single endpoint skips encrypted transactions silently', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'encrypted-blob',
        'description_iv' => 'a1b2c3d4e5f60001',
        'amount' => -5000,
        'category_id' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.single', $transaction))
        ->assertOk();

    // Category should remain null because the backend skips encrypted transactions
    expect($transaction->fresh()->category_id)->toBeNull();
});

test('single endpoint returns 403 for another user\'s transaction', function () {
    $otherUser = User::factory()->onboarded()->create();
    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $otherUser->id,
        'account_id' => Account::factory()->create(['user_id' => $otherUser->id])->id,
        'description' => 'Some purchase',
        'amount' => -1000,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.single', $transaction))
        ->assertForbidden();
});

// ──────────────────────────────────────────────
// Bulk re-evaluate endpoint
// ──────────────────────────────────────────────

test('bulk endpoint dispatches job and returns job_id', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.bulk'));

    $response->assertStatus(202)
        ->assertJsonStructure(['job_id']);

    Queue::assertPushed(ReEvaluateTransactionRulesJob::class);
});

test('bulk endpoint dispatches job with provided transaction_ids', function () {
    Queue::fake();

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Purchase',
        'amount' => -1000,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.bulk'), [
            'transaction_ids' => [$transaction->id],
        ]);

    $response->assertStatus(202);

    Queue::assertPushed(ReEvaluateTransactionRulesJob::class, function ($job) use ($transaction) {
        return $job->transactionIds === [$transaction->id];
    });
});

test('bulk endpoint sets initial pending status in cache', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.bulk'));

    $jobId = $response->json('job_id');
    $cacheKey = ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId);

    expect(Cache::get($cacheKey))->toMatchArray(['status' => 'pending']);
});

// ──────────────────────────────────────────────
// Status endpoint
// ──────────────────────────────────────────────

test('status endpoint returns progress from cache', function () {
    $jobId = 'test-job-id';
    $cacheKey = ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId);

    Cache::put($cacheKey, [
        'status' => 'processing',
        'processed' => 10,
        'total' => 50,
        'updated' => 3,
    ], now()->addHour());

    $this->actingAs($this->user)
        ->getJson(route('transactions.re-evaluate-rules.status', $jobId))
        ->assertOk()
        ->assertJson([
            'status' => 'processing',
            'processed' => 10,
            'total' => 50,
            'updated' => 3,
        ]);
});

test('status endpoint returns 404 for unknown job', function () {
    $this->actingAs($this->user)
        ->getJson(route('transactions.re-evaluate-rules.status', 'non-existent-job-id'))
        ->assertNotFound();
});

test('status endpoint does not leak another user\'s job progress', function () {
    $jobId = 'owned-by-someone-else';
    $cacheKey = ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId);

    Cache::put($cacheKey, ['status' => 'processing', 'processed' => 5, 'total' => 50, 'updated' => 1], now()->addHour());

    $otherUser = User::factory()->onboarded()->create();

    $this->actingAs($otherUser)
        ->getJson(route('transactions.re-evaluate-rules.status', $jobId))
        ->assertNotFound();
});

// ──────────────────────────────────────────────
// Job execution
// ──────────────────────────────────────────────

test('job applies rules to non-encrypted transactions and tracks progress', function () {
    // Create transactions BEFORE the rule so the creation listener has no rules to apply
    $matchingTransaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
        'category_id' => null,
    ]);

    $nonMatchingTransaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Coffee Shop',
        'amount' => -500,
        'category_id' => null,
    ]);

    // Add the rule after creation so re-evaluation is meaningful
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $jobId = 'test-job-'.uniqid();
    $job = new ReEvaluateTransactionRulesJob($this->user, $jobId);
    $job->handle(app(AutomationRuleService::class));

    expect($matchingTransaction->fresh()->category_id)->toBe($this->category->id);
    expect($nonMatchingTransaction->fresh()->category_id)->toBeNull();

    $progress = Cache::get(ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId));
    expect($progress['status'])->toBe('done');
    expect($progress['processed'])->toBe(2);
    expect($progress['updated'])->toBe(1);
});

test('job queries automation rules once regardless of transaction count', function () {
    // Create transactions BEFORE the rule so the creation listener has no rules to apply
    Transaction::factory()->enableBanking()->count(5)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
        'category_id' => null,
    ]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $ruleQueries = 0;
    DB::listen(function ($query) use (&$ruleQueries): void {
        if (str_contains($query->sql, 'automation_rules')) {
            $ruleQueries++;
        }
    });

    $jobId = 'test-job-'.uniqid();
    (new ReEvaluateTransactionRulesJob($this->user, $jobId))->handle(app(AutomationRuleService::class));

    expect($ruleQueries)->toBe(1);
});

test('job marks cache as failed and preserves counts', function () {
    $jobId = 'reeval-failed';
    Cache::put(
        ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId),
        ['status' => 'processing', 'processed' => 4, 'total' => 20, 'updated' => 2],
        now()->addHour(),
    );

    (new ReEvaluateTransactionRulesJob($this->user, $jobId))
        ->failed(new RuntimeException('boom'));

    $progress = Cache::get(ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId));
    expect($progress['status'])->toBe('failed')
        ->and($progress['processed'])->toBe(4)
        ->and($progress['total'])->toBe(20)
        ->and($progress['updated'])->toBe(2);
});

test('job skips encrypted transactions', function () {
    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'encrypted-blob',
        'description_iv' => 'a1b2c3d4e5f60001',
        'amount' => -5000,
        'category_id' => null,
    ]);

    $jobId = 'test-job-'.uniqid();
    $job = new ReEvaluateTransactionRulesJob($this->user, $jobId);
    $job->handle(app(AutomationRuleService::class));

    $progress = Cache::get(ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId));
    // 0 processed because the encrypted transaction is excluded from the query
    expect($progress['processed'])->toBe(0);
    expect($progress['updated'])->toBe(0);
});

test('job only processes provided transaction_ids', function () {
    // Create transactions BEFORE the rule so the creation listener has no rules to apply
    $t1 = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
        'category_id' => null,
    ]);

    $t2 = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Supermarket',
        'amount' => -3000,
        'category_id' => null,
    ]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $jobId = 'test-job-'.uniqid();
    $job = new ReEvaluateTransactionRulesJob($this->user, $jobId, [$t1->id]);
    $job->handle(app(AutomationRuleService::class));

    expect($t1->fresh()->category_id)->toBe($this->category->id);
    expect($t2->fresh()->category_id)->toBeNull();

    $progress = Cache::get(ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId));
    expect($progress['processed'])->toBe(1);
});

test('bulk endpoint dispatches job with provided filters', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->postJson(route('transactions.re-evaluate-rules.bulk'), [
            'filters' => [
                'date_from' => '2024-01-01',
                'date_to' => '2024-12-31',
            ],
        ]);

    $response->assertStatus(202)
        ->assertJsonStructure(['job_id']);

    Queue::assertPushed(ReEvaluateTransactionRulesJob::class, function ($job) {
        return $job->transactionIds === null
            && $job->filters === ['date_from' => '2024-01-01', 'date_to' => '2024-12-31'];
    });
});

test('job applies rules to transactions matching filters', function () {
    $matchingTransaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store',
        'amount' => -5000,
        'category_id' => null,
        'transaction_date' => '2024-06-15',
    ]);

    $outsideRangeTransaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Supermarket',
        'amount' => -3000,
        'category_id' => null,
        'transaction_date' => '2023-01-01',
    ]);

    AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);

    $jobId = 'test-job-'.uniqid();
    $job = new ReEvaluateTransactionRulesJob($this->user, $jobId, null, [
        'date_from' => '2024-01-01',
        'date_to' => '2024-12-31',
    ]);
    $job->handle(app(AutomationRuleService::class));

    expect($matchingTransaction->fresh()->category_id)->toBe($this->category->id);
    expect($outsideRangeTransaction->fresh()->category_id)->toBeNull();

    $progress = Cache::get(ReEvaluateTransactionRulesJob::cacheKeyForJobId($this->user->id, $jobId));
    expect($progress['status'])->toBe('done');
    expect($progress['processed'])->toBe(1);
    expect($progress['updated'])->toBe(1);
});
