<?php

use App\Events\TransactionCreated;
use App\Events\TransactionUpdated;
use App\Jobs\ApplySingleAutomationRuleJob;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake([TransactionCreated::class, TransactionUpdated::class]);
    $this->user = User::factory()->onboarded()->create();
    $this->bank = Bank::factory()->create(['name' => 'Test Bank', 'user_id' => $this->user->id]);
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $this->bank->id,
        'name' => 'Checking Account',
        'encrypted' => false,
    ]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
    $this->rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $this->category->id,
    ]);
});

test('matches endpoint returns transactions matching the rule', function () {
    Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'amount' => -1000,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Coffee Shop',
        'amount' => -500,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('automation-rules.matches', $this->rule));

    $response->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonCount(1, 'data');
});

test('matches endpoint skips already categorized when only_uncategorized is true', function () {
    Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'description' => 'Grocery Store A',
        'amount' => -1000,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Grocery Store B',
        'amount' => -1500,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('automation-rules.matches', $this->rule).'?only_uncategorized=1');

    $response->assertOk()->assertJsonPath('total', 1);

    $allResponse = $this->actingAs($this->user)
        ->getJson(route('automation-rules.matches', $this->rule).'?only_uncategorized=0');

    $allResponse->assertOk()->assertJsonPath('total', 2);
});

test('matches endpoint avoids repeated relationship queries for description-only rules', function () {
    Transaction::factory()->enableBanking()->create([
        'id' => '00000000-0000-0000-0000-000000000001',
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'transaction_date' => '2024-01-01',
        'amount' => -1000,
    ]);

    Transaction::factory()->enableBanking()->count(500)->sequence(
        fn (Sequence $sequence): array => [
            'id' => sprintf('ffffffff-ffff-ffff-ffff-%012d', $sequence->index),
        ],
    )->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'transaction_date' => '2024-01-02',
        'amount' => -1000,
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($this->user)
        ->getJson(route('automation-rules.matches', $this->rule))
        ->assertOk()
        ->assertJsonPath('total', 501);

    $accountEagerLoadQueries = collect($queries)
        ->filter(fn (string $query): bool => (str_contains($query, 'from "accounts"') || str_contains($query, 'from `accounts`'))
            && (str_contains($query, '"accounts"."id" in') || str_contains($query, '`accounts`.`id` in')));
    $bankEagerLoadQueries = collect($queries)
        ->filter(fn (string $query): bool => (str_contains($query, 'from "banks"') || str_contains($query, 'from `banks`'))
            && (str_contains($query, '"banks"."id" in') || str_contains($query, '`banks`.`id` in')));

    expect($accountEagerLoadQueries)->toHaveCount(1)
        ->and($bankEagerLoadQueries)->toHaveCount(1);
});

test('matches endpoint deduplicates cached matching transaction ids', function () {
    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'description' => 'Grocery Store A',
        'amount' => -1000,
    ]);

    $stamp = $this->rule->updated_at?->getTimestamp() ?? 0;
    Cache::put(
        "automation_rule_matches:{$this->user->id}:{$this->rule->id}:0:{$stamp}",
        [$transaction->id, $transaction->id],
        now()->addMinutes(15),
    );

    $response = $this->actingAs($this->user)
        ->getJson(route('automation-rules.matches', $this->rule).'?only_uncategorized=0');

    $response->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $transaction->id);
});

test('apply endpoint runs synchronously when matches are below threshold', function () {
    Queue::fake();

    Transaction::factory()->enableBanking()->count(3)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'amount' => -1000,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('automation-rules.apply', $this->rule), [
            'only_uncategorized' => true,
        ]);

    $response->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('applied', 3)
        ->assertJsonPath('updated', 3)
        ->assertJsonPath('total', 3);

    Queue::assertNothingPushed();

    expect(
        Transaction::where('user_id', $this->user->id)
            ->where('category_id', $this->category->id)
            ->count()
    )->toBe(3);
});

test('apply endpoint batches category and label writes', function () {
    Queue::fake();

    $label = Label::factory()->create(['user_id' => $this->user->id]);
    $this->rule->labels()->attach($label);

    Transaction::factory()->enableBanking()->count(5)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'amount' => -1000,
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->actingAs($this->user)
        ->postJson(route('automation-rules.apply', $this->rule), [
            'only_uncategorized' => true,
        ]);

    $transactionUpdateQueries = collect($queries)
        ->filter(fn (string $query): bool => str_contains($query, 'update "transactions" set')
            || str_contains($query, 'update `transactions` set'));
    $perTransactionPivotLookupQueries = collect($queries)
        ->filter(fn (string $query): bool => (str_contains($query, 'from "label_transaction"')
            || str_contains($query, 'from `label_transaction`'))
            && (str_contains($query, '"label_transaction"."transaction_id" =')
                || str_contains($query, '`label_transaction`.`transaction_id` =')));

    $response->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('applied', 5)
        ->assertJsonPath('updated', 5)
        ->assertJsonPath('total', 5);

    expect($transactionUpdateQueries)->toHaveCount(1)
        ->and($perTransactionPivotLookupQueries)->toHaveCount(0);

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('label_transaction', 5);
});

test('apply endpoint queues a job when matches exceed threshold', function () {
    Queue::fake();

    Transaction::factory()->enableBanking()->count(101)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'amount' => -1000,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('automation-rules.apply', $this->rule), [
            'only_uncategorized' => true,
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('total', 101)
        ->assertJsonStructure(['job_id']);

    Queue::assertPushed(ApplySingleAutomationRuleJob::class);
});

test('apply endpoint returns done with zero matches when no transactions match', function () {
    Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Coffee Shop',
        'amount' => -500,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('automation-rules.apply', $this->rule));

    $response->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('total', 0)
        ->assertJsonPath('updated', 0);
});

test('cannot apply rule belonging to another user', function () {
    $otherUser = User::factory()->onboarded()->create();

    $this->actingAs($otherUser)
        ->postJson(route('automation-rules.apply', $this->rule))
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->getJson(route('automation-rules.matches', $this->rule))
        ->assertForbidden();
});

test('label-only rule applies when only_uncategorized is true', function () {
    $labelOnlyRule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 2,
        'rules_json' => ['in' => ['coffee', ['var' => 'description']]],
        'action_category_id' => null,
    ]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);
    $labelOnlyRule->labels()->attach($label);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'description' => 'Coffee Shop',
        'amount' => -500,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('automation-rules.apply', $labelOnlyRule), [
            'only_uncategorized' => true,
        ]);

    $response->assertOk()->assertJsonPath('applied', 1);
});

test('applying after changing the rule category reports all matches as applied', function () {
    $newCategory = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->enableBanking()->count(5)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'description' => 'Grocery Store',
        'amount' => -1000,
    ]);

    $this->rule->update(['action_category_id' => $newCategory->id]);

    $response = $this->actingAs($this->user)
        ->postJson(route('automation-rules.apply', $this->rule), [
            'only_uncategorized' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('applied', 5)
        ->assertJsonPath('updated', 5)
        ->assertJsonPath('total', 5);

    expect(
        Transaction::where('user_id', $this->user->id)
            ->where('category_id', $newCategory->id)
            ->count()
    )->toBe(5);
});

test('re-applying same rule reports applied count even when nothing changes', function () {
    Transaction::factory()->enableBanking()->count(4)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->category->id,
        'description' => 'Grocery Store',
        'amount' => -1000,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson(route('automation-rules.apply', $this->rule), [
            'only_uncategorized' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('applied', 4)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('total', 4);
});

test('store flashes saved automation rule id and token', function () {
    $payload = [
        'title' => 'Test Rule',
        'priority' => 0,
        'rules_json' => json_encode(['in' => ['amazon', ['var' => 'description']]]),
        'action_category_id' => $this->category->id,
        'action_note' => null,
        'action_note_iv' => null,
        'action_label_ids' => [],
    ];

    $response = $this->actingAs($this->user)
        ->post(route('automation-rules.store'), $payload);

    $response->assertRedirect();
    $response->assertSessionHas('saved_automation_rule_id');
    $response->assertSessionHas('saved_automation_rule_token');
});

test('updating labels flashes a new saved automation rule token', function () {
    $firstLabel = Label::factory()->create(['user_id' => $this->user->id]);
    $secondLabel = Label::factory()->create(['user_id' => $this->user->id]);
    $this->rule->labels()->attach($firstLabel);

    $payload = [
        'title' => $this->rule->title,
        'priority' => $this->rule->priority,
        'rules_json' => json_encode($this->rule->rules_json),
        'action_category_id' => $this->rule->action_category_id,
        'action_note' => null,
        'action_note_iv' => null,
        'action_label_ids' => [$secondLabel->id],
    ];

    $response = $this->actingAs($this->user)
        ->patch(route('automation-rules.update', $this->rule), $payload);

    $response->assertRedirect();
    $response->assertSessionHas('saved_automation_rule_id', $this->rule->id);
    $response->assertSessionHas('saved_automation_rule_token');

    expect($this->rule->labels()->pluck('labels.id')->all())->toBe([
        $secondLabel->id,
    ]);
});
