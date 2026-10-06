<?php

use App\Enums\AccountType;
use App\Enums\ImportStatus;
use App\Jobs\ProcessFullImportJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\ImportChunk;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FullImportFixtures as Fixtures;

/**
 * @param  array<string, mixed>  $plan
 */
function postFullImportPlan(array $plan, int $transactions = 1): TestResponse
{
    return test()->postJson(route('api.full-imports.store'), [
        ...$plan,
        'expected_transactions' => $transactions,
        'expected_balances' => 0,
    ]);
}

it('never maps a file account onto a connected account', function () {
    $user = Fixtures::user();
    $connected = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'type' => AccountType::Checking,
    ]);

    $this->actingAs($user);

    postFullImportPlan(Fixtures::plan([
        ['key' => 'a0', 'action' => 'map', 'target_account_id' => $connected->id],
    ]))->assertUnprocessable()->assertJsonValidationErrors('accounts.0.target_account_id');
});

it('only maps onto a manual account of the user that keeps a ledger', function (Closure $makeTarget) {
    $user = Fixtures::user();
    $target = $makeTarget($user);

    $this->actingAs($user);

    postFullImportPlan(Fixtures::plan([
        ['key' => 'a0', 'action' => 'map', 'target_account_id' => $target->id],
    ]))->assertUnprocessable()->assertJsonValidationErrors('accounts.0.target_account_id');
})->with([
    'someone else\'s' => [fn () => Account::factory()->create(['type' => AccountType::Checking])],
    'a loan' => [fn ($user) => Account::factory()->loan()->create(['user_id' => $user->id])],
    'archived' => [fn ($user) => Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking, 'archived_at' => now()])],
]);

it('refuses a plan that cannot work', function (array $accounts, array $categories, string $mode, string $error) {
    $user = Fixtures::user();
    $manual = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);

    $this->actingAs($user);

    $accounts = array_map(
        fn (array $entry): array => ($entry['target_account_id'] ?? null) === 'manual' ? [...$entry, 'target_account_id' => $manual->id] : $entry,
        $accounts,
    );

    postFullImportPlan(Fixtures::plan($accounts, $categories, $mode))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'nothing imported' => [[['key' => 'a0', 'action' => 'skip']], [], 'add', 'accounts'],
    'merge into a skipped account' => [[Fixtures::newAccount('a0', 'Wise'), ['key' => 'a1', 'action' => 'skip'], ['key' => 'a2', 'action' => 'merge', 'merge_into_key' => 'a1']], [], 'add', 'accounts.2.merge_into_key'],
    'mapping while wiping' => [[['key' => 'a0', 'action' => 'map', 'target_account_id' => 'manual']], [], 'wipe', 'accounts.0.action'],
    'a loan account type' => [[Fixtures::newAccount('a0', 'Mortgage', ['type' => 'loan'])], [], 'add', 'accounts.0.type'],
    'a root category without a type' => [[Fixtures::newAccount('a0', 'Wise')], [['key' => 'c0', 'action' => 'create', 'name' => 'Empresa', 'icon' => 'Wallet', 'color' => 'blue']], 'add', 'categories.0.type'],
    'a parent outside the plan' => [[Fixtures::newAccount('a0', 'Wise')], [['key' => 'c0', 'action' => 'create', 'name' => 'Child', 'parent_key' => 'nope', 'icon' => 'Wallet', 'color' => 'blue']], 'add', 'categories.0.parent_key'],
    'four levels deep' => [[Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'c0', 'action' => 'create', 'name' => 'One', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
        ['key' => 'c1', 'action' => 'create', 'name' => 'Two', 'parent_key' => 'c0', 'icon' => 'Wallet', 'color' => 'blue'],
        ['key' => 'c2', 'action' => 'create', 'name' => 'Three', 'parent_key' => 'c1', 'icon' => 'Wallet', 'color' => 'blue'],
        ['key' => 'c3', 'action' => 'create', 'name' => 'Four', 'parent_key' => 'c2', 'icon' => 'Wallet', 'color' => 'blue'],
    ], 'add', 'categories.3.parent_key'],
]);

it('counts the depth of an existing parent a new category hangs from', function () {
    $user = Fixtures::user();
    $groceries = Fixtures::seeded($user, 'Groceries'); // Food › Groceries: level 2

    $this->actingAs($user);

    postFullImportPlan(Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'c0', 'action' => 'match', 'category_id' => $groceries->id],
        ['key' => 'c1', 'action' => 'create', 'name' => 'Organic', 'parent_key' => 'c0', 'icon' => 'Wallet', 'color' => 'blue'],
        ['key' => 'c2', 'action' => 'create', 'name' => 'Too deep', 'parent_key' => 'c1', 'icon' => 'Wallet', 'color' => 'blue'],
    ]))->assertUnprocessable()->assertJsonValidationErrors('categories.2.parent_key')
        ->assertJsonMissingValidationErrors('categories.1.parent_key');
});

it('refuses to match a category of someone else', function () {
    $user = Fixtures::user();

    $this->actingAs($user);

    postFullImportPlan(Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'c0', 'action' => 'match', 'category_id' => Category::factory()->create()->id],
    ]))->assertUnprocessable()->assertJsonValidationErrors('categories.0.category_id');
});

it('needs the wipe confirmed', function () {
    $this->actingAs(Fixtures::user());

    $plan = Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], mode: 'wipe');
    unset($plan['confirm_wipe']);

    postFullImportPlan($plan)->assertUnprocessable()->assertJsonValidationErrors('confirm_wipe');
});

it('only takes rows that use the keys of the plan', function () {
    $user = Fixtures::user();

    $id = $this->actingAs($user)->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        'expected_transactions' => 1,
        'expected_balances' => 0,
    ])->assertCreated()->json('id');

    $this->postJson(route('api.full-imports.chunks.store', $id), [
        'kind' => 'transactions',
        'position' => 0,
        'rows' => [Fixtures::row('elsewhere', '2026-09-01', -100, 'Coffee', ['category_key' => 'c9'])],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.account_key', 'rows.0.category_key']);
});

it('does not start until every row it expects has arrived, and replaces a re-sent chunk', function () {
    Queue::fake([ProcessFullImportJob::class]);
    $user = Fixtures::user();

    $id = $this->actingAs($user)->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        'expected_transactions' => 2,
        'expected_balances' => 0,
    ])->assertCreated()->json('id');

    $one = Fixtures::row('a0', '2026-09-01', -100, 'Coffee');
    $chunk = ['kind' => 'transactions', 'position' => 0, 'rows' => [$one]];

    $this->postJson(route('api.full-imports.chunks.store', $id), $chunk)->assertJsonPath('received', 1);
    $this->postJson(route('api.full-imports.chunks.store', $id), $chunk)->assertJsonPath('received', 1);
    $this->postJson(route('api.full-imports.start', $id))->assertUnprocessable();

    Queue::assertNothingPushed();

    // Two rows fit one chunk: a second position is past what the plan announced.
    $this->postJson(route('api.full-imports.chunks.store', $id), [...$chunk, 'position' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('position');

    $this->postJson(route('api.full-imports.chunks.store', $id), [...$chunk, 'rows' => [$one, $one]])->assertJsonPath('received', 2);
    $this->postJson(route('api.full-imports.start', $id))->assertOk()->assertJsonPath('status', 'queued');

    Queue::assertPushed(ProcessFullImportJob::class, 1);

    // Started imports take no more rows and cannot be started twice.
    $this->postJson(route('api.full-imports.chunks.store', $id), $chunk)->assertConflict();
    $this->postJson(route('api.full-imports.start', $id))->assertConflict();
});

it('runs one import at a time and drops abandoned drafts', function () {
    $user = Fixtures::user();
    $draft = Import::factory()->for($user)->draft()->create();

    $this->actingAs($user);

    postFullImportPlan(Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]))->assertCreated();

    expect(Import::query()->find($draft->id))->toBeNull();

    Import::factory()->for($user)->create(['status' => ImportStatus::Processing]);

    postFullImportPlan(Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]))->assertConflict();
});

it('marks the import failed when the job fails, keeping what it wrote undoable', function () {
    $user = Fixtures::user();
    $import = Import::factory()->for($user)->create(['status' => ImportStatus::Processing, 'plan' => ['accounts' => []]]);
    $import->chunks()->create(['kind' => 'transactions', 'position' => 0, 'row_count' => 0, 'rows' => []]);

    (new ProcessFullImportJob($import))->failed(new RuntimeException('boom'));

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->plan)->toBeNull()
        ->and($import->chunks()->count())->toBe(0)
        ->and($import->isUndoable())->toBeTrue();
});

it('prunes drafts abandoned for a day, with their staged rows', function () {
    $stale = Import::factory()->draft()->create(['updated_at' => now()->subDays(2)]);
    $stale->chunks()->create(['kind' => 'transactions', 'position' => 0, 'row_count' => 1, 'rows' => [['description' => 'private']]]);
    $fresh = Import::factory()->draft()->create();
    $finished = Import::factory()->create(['updated_at' => now()->subDays(30)]);

    $this->artisan('model:prune', ['--model' => [Import::class]])->assertSuccessful();

    expect(Import::query()->find($stale->id))->toBeNull()
        ->and(ImportChunk::query()->count())->toBe(0)
        ->and(Import::query()->find($fresh->id))->not->toBeNull()
        ->and(Import::query()->find($finished->id))->not->toBeNull();
});

it('files ignored movements under a transfer category only', function () {
    $user = Fixtures::user();

    $this->actingAs($user);

    postFullImportPlan(Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'ignored', 'action' => 'match', 'category_id' => Fixtures::seeded($user, 'Groceries')->id],
    ]))->assertUnprocessable()->assertJsonValidationErrors('categories.0.category_id');

    postFullImportPlan(Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'ignored', 'action' => 'create', 'name' => 'Ignored', 'type' => 'expense', 'icon' => 'Split', 'color' => 'stone'],
    ]))->assertUnprocessable()->assertJsonValidationErrors('categories.0.category_id');

    postFullImportPlan(Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'ignored', 'action' => 'match', 'category_id' => Fixtures::seeded($user, 'Other transfers')->id],
    ]))->assertCreated();
});

it('refuses more rows than the plan announced, amounts past a bigint and currencies an account cannot have', function () {
    $user = Fixtures::user();

    $id = $this->actingAs($user)->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        'expected_transactions' => 2,
        'expected_balances' => 0,
    ])->assertCreated()->json('id');

    $row = Fixtures::row('a0', '2026-09-01', -100, 'Coffee');
    $post = fn (array $body) => $this->postJson(route('api.full-imports.chunks.store', $id), ['kind' => 'transactions', 'position' => 0, ...$body]);

    $post(['rows' => [$row, $row, $row]])->assertUnprocessable()->assertJsonValidationErrors('rows');
    $post(['rows' => [[...$row, 'amount' => '9223372036854775808']]])->assertUnprocessable()->assertJsonValidationErrors('rows.0.amount');
    $post(['rows' => [[...$row, 'currency_code' => 'XYZ']]])->assertUnprocessable()->assertJsonValidationErrors('rows.0.currency_code');
    $post(['rows' => [[...$row, 'currency_code' => 'usd']]])->assertOk();

    $this->postJson(route('api.full-imports.chunks.store', $id), ['kind' => 'balances', 'position' => 0, 'rows' => [
        ['account_key' => 'a0', 'date' => '2026-09-01', 'balance' => 100],
    ]])->assertUnprocessable()->assertJsonValidationErrors('position');

    expect(ImportChunk::query()->where('import_id', $id)->value('rows')[0]['currency_code'])->toBe('USD');
});

it('keeps a draft that is still receiving chunks out of the prune', function () {
    $user = Fixtures::user();

    $id = $this->actingAs($user)->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        'expected_transactions' => 1,
        'expected_balances' => 0,
    ])->assertCreated()->json('id');

    Import::query()->whereKey($id)->update(['updated_at' => now()->subDays(2)]);

    $this->postJson(route('api.full-imports.chunks.store', $id), [
        'kind' => 'transactions', 'position' => 0, 'rows' => [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')],
    ])->assertOk();

    $this->artisan('model:prune', ['--model' => [Import::class]])->assertSuccessful();

    expect(Import::query()->find($id))->not->toBeNull();
});

it('starts and creates under a lock, one import at a time', function () {
    Queue::fake([ProcessFullImportJob::class]);
    $user = Fixtures::user();
    $running = Import::factory()->for($user)->draft()->create(['plan' => ['expected' => ['transactions' => 0, 'balances' => 0]]]);
    $other = Import::factory()->for($user)->create(['status' => ImportStatus::Processing]);

    $this->actingAs($user)->postJson(route('api.full-imports.start', $running))->assertConflict();

    Queue::assertNothingPushed();

    expect($running->fresh()->status)->toBe(ImportStatus::Draft)
        ->and($other->fresh()->status)->toBe(ImportStatus::Processing);
});
