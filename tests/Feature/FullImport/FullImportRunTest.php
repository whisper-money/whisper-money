<?php

use App\Enums\AccountType;
use App\Enums\CategorySource;
use App\Enums\ImportStatus;
use App\Jobs\ProcessFullImportJob;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Listeners\AssignTransactionToBudget;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Import;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Imports\FullImporter;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FullImportFixtures as Fixtures;
use Tests\TestCase;

/**
 * Stage a plan and its rows without starting the job, so a test can run the
 * importer itself.
 *
 * @param  array<string, mixed>  $plan
 * @param  list<array<string, mixed>>  $rows
 */
function stageFullImport(TestCase $test, User $user, array $plan, array $rows): Import
{
    Queue::fake([ProcessFullImportJob::class]);

    $import = Fixtures::run($test, $user, $plan, $rows);

    Queue::assertPushed(ProcessFullImportJob::class, 1);

    return $import->refresh();
}

it('carries a large import over several runs, picking up where the last one stopped', function () {
    $user = Fixtures::user();
    $rows = array_map(
        fn (int $i): array => Fixtures::row('a0', '2026-09-01', -100 - $i, "Row {$i}", ['external_id' => "bt-{$i}"]),
        range(1, 1200),
    );

    $import = stageFullImport($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), $rows);

    // No time to write anything: the accounts are created, the movements wait.
    expect(app(FullImporter::class)->run($import, transactionSeconds: 0))->toBeFalse();

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Processing)
        ->and(Account::query()->where('import_id', $import->id)->count())->toBe(1)
        ->and($import->chunks()->count())->toBe(3)
        ->and($import->plan['resolved']['accounts']['targets'])->toHaveKey('a0');

    expect(app(FullImporter::class)->run($import->refresh()))->toBeTrue();

    $import->refresh();

    // The second run reused the account the first one created.
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(Account::query()->where('import_id', $import->id)->count())->toBe(1)
        ->and(Transaction::query()->where('import_id', $import->id)->count())->toBe(1200)
        ->and($import->stats['transactions'])->toMatchArray(['total' => 1200, 'processed' => 1200, 'imported' => 1200])
        ->and($import->chunks()->count())->toBe(0);
});

it('queues the next run when one stops at its deadline', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Processing, 'plan' => ['resolved' => ['accounts' => [], 'categories' => []]]]);

    $this->mock(FullImporter::class)->shouldReceive('run')->once()->andReturnFalse();
    Queue::fake();

    app()->call([new ProcessFullImportJob($import), 'handle']);

    Queue::assertPushed(ProcessFullImportJob::class, fn (ProcessFullImportJob $job): bool => $job->import->is($import));
});

it('keeps repeated ids within the file, and only skips the ids an account already held', function () {
    $user = Fixtures::user();
    $own = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $own->id, 'external_transaction_id' => 'REF-OLD']);

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise'),
        ['key' => 'a1', 'action' => 'map', 'target_account_id' => $own->id],
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Transfer one', ['external_id' => 'TRANSFER']),
        Fixtures::row('a0', '2026-09-02', -200, 'Transfer two', ['external_id' => 'TRANSFER']),
        Fixtures::row('a1', '2026-09-03', -300, 'Already there', ['external_id' => 'REF-OLD']),
        Fixtures::row('a1', '2026-09-04', -400, 'New one', ['external_id' => 'REF-NEW']),
    ]);

    expect($import->stats['transactions'])->toMatchArray(['imported' => 3, 'duplicates' => 1])
        ->and(Transaction::query()->where('external_transaction_id', 'TRANSFER')->count())->toBe(2);
});

it('assigns imported rows to budgets in one batch per chunk, without budget emails', function () {
    $user = Fixtures::user();

    Queue::fake([ReassignTransactionsToBudgets::class, CallQueuedListener::class]);

    Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), array_map(
        fn (int $i): array => Fixtures::row('a0', '2026-09-01', -100, "Row {$i}"),
        range(1, 600),
    ));

    Queue::assertPushed(ReassignTransactionsToBudgets::class, 2);
    Queue::assertPushed(ReassignTransactionsToBudgets::class, fn (ReassignTransactionsToBudgets $job): bool => $job->notify === false);
    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === AssignTransactionToBudget::class);

    // A later edit of an imported row goes through the per-row listener again.
    Transaction::query()->where('user_id', $user->id)->firstOrFail()->update(['description' => 'Edited']);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === AssignTransactionToBudget::class);
});

it('puts imported rows into the budget that tracks their category', function () {
    $user = Fixtures::user();
    $groceries = Fixtures::seeded($user, 'Groceries');
    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    $budget->categories()->attach($groceries->id);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
    ]);

    Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'c0', 'action' => 'match', 'category_id' => $groceries->id],
    ]), [
        Fixtures::row('a0', '2026-09-10', -4250, 'Mercadona', ['category_key' => 'c0']),
    ]);

    expect(BudgetTransaction::query()->where('budget_period_id', $period->id)->count())->toBe(1);
});

it('merges stats written from a stale copy of the import instead of overwriting them', function () {
    $import = Import::factory()->create(['stats' => ['stage' => 'ai']]);
    $stale = Import::query()->findOrFail($import->id);

    $import->recordStats(['ai' => ['status' => 'running', 'processed' => 5]]);
    $stale->recordStats(['stage' => 'done']);

    expect($import->fresh()->stats)->toMatchArray([
        'stage' => 'done',
        'ai' => ['status' => 'running', 'processed' => 5],
    ]);
});

it('leaves a row the user filed into an imported category free to be categorized again after undo', function () {
    $user = Fixtures::user();
    $own = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $ownRow = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $own->id]);

    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'c0', 'action' => 'create', 'name' => 'Empresa', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee', ['category_key' => 'c0'])]);

    $ownRow->update(['category_id' => $import->categories()->value('id'), 'category_source' => CategorySource::Manual]);

    $this->delete(route('full-import.destroy', $import))->assertSessionHas('success');

    expect($ownRow->fresh()->category_id)->toBeNull()
        ->and($ownRow->fresh()->category_source)->toBeNull();
});

it('wipes only the manual accounts the wizard listed, not ones already deleted', function () {
    $user = Fixtures::user();
    $archived = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking, 'archived_at' => now()]);
    $deleted = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);
    $deleted->delete();

    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], mode: 'wipe'), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    expect(Account::withTrashed()->whereKey($archived->id)->exists())->toBeFalse()
        ->and(Account::withTrashed()->whereKey($deleted->id)->exists())->toBeTrue()
        ->and($import->stats['wiped']['accounts'])->toBe(1);
});

it('never reuses a category from another space of the same user', function () {
    $user = Fixtures::user();
    $otherSpace = Space::factory()->create(['owner_id' => $user->id]);
    $elsewhere = Category::factory()->create(['user_id' => $user->id, 'space_id' => $otherSpace->id, 'name' => 'Empresa', 'parent_id' => null]);

    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], [
        ['key' => 'c0', 'action' => 'create', 'name' => 'Empresa', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
        ['key' => 'c1', 'action' => 'create', 'name' => 'Gastos', 'parent_key' => 'c0', 'icon' => 'Wallet', 'color' => 'blue'],
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee', ['category_key' => 'c1'])]);

    $created = Category::query()->where('import_id', $import->id)->get()->keyBy('name');

    // The name is unique per user across spaces, so the new root gets the source added.
    expect($created->keys()->sort()->values()->all())->toBe(['Empresa (Banktrack)', 'Gastos'])
        ->and($created->every(fn (Category $category): bool => $category->space_id === $user->current_space_id))->toBeTrue()
        ->and($created['Gastos']->parent_id)->toBe($created['Empresa (Banktrack)']->id)
        ->and($elsewhere->fresh()->transactions()->exists())->toBeFalse();
});

it('does nothing for an import that is no longer queued or processing', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Completed, 'plan' => ['accounts' => [Fixtures::newAccount('a0', 'Wise')]]]);

    expect(app(FullImporter::class)->run($import))->toBeTrue()
        ->and(Account::query()->where('import_id', $import->id)->exists())->toBeFalse();
});

it('fails a retry that finds the import halfway through its preparation', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Processing, 'plan' => ['accounts' => []]]);

    $this->mock(FullImporter::class)->shouldNotReceive('run');

    $job = new ProcessFullImportJob($import);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertFailed();
});

it('never wipes twice', function () {
    $user = Fixtures::user();
    $import = stageFullImport($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], mode: 'wipe'), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);
    $import->recordStats(['wiped' => ['accounts' => 1, 'transactions' => 3]]);
    $manual = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);

    app(FullImporter::class)->run($import->refresh());

    expect($manual->fresh())->not->toBeNull();
});

it('refuses at run time a mapped account archived while the import waited', function () {
    $user = Fixtures::user();
    $own = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);
    $import = stageFullImport($this, $user, Fixtures::plan([
        ['key' => 'a0', 'action' => 'map', 'target_account_id' => $own->id],
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')]);

    $own->update(['archived_at' => now()]);

    expect(fn () => app(FullImporter::class)->run($import))->toThrow(RuntimeException::class);
});

it('stores only real IBANs', function (string $given, ?string $stored) {
    $user = Fixtures::user();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise', ['iban' => $given]),
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')]);

    expect(Account::query()->where('import_id', $import->id)->value('iban'))->toBe($stored);
})->with([
    'valid, spaced' => ['ES91 2100 0418 4502 0005 1332', 'ES9121000418450200051332'],
    'masked with X' => ['ES12 3456 7890 1234 5678 XXXX', null],
    'masked with stars' => ['ES91 2100 **** **** 0005 1332', null],
    'wrong check digits' => ['ES00 2100 0418 4502 0005 1332', null],
    'not an IBAN' => ['Cuenta Corriente', null],
]);

it('clears the links of the rows an import wrote when its history row is deleted', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ], [['account_key' => 'a0', 'date' => '2026-09-01', 'balance' => 100]]);

    $import->delete();

    expect(Transaction::query()->where('description', 'Coffee')->value('import_id'))->toBeNull()
        ->and(AccountBalance::query()->whereNotNull('import_id')->exists())->toBeFalse()
        ->and(AccountBalance::query()->first()->toArray())->not->toHaveKey('import_id');
});
