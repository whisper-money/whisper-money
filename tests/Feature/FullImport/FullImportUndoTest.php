<?php

use App\Enums\AccountType;
use App\Enums\ImportStatus;
use App\Jobs\UndoImportJob;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\Imports\ImportHistoryPresenter;
use App\Services\Imports\ImportUndoer;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FullImportFixtures as Fixtures;

// The master switch is off by default on this instance (nobody can start an
// import); these tests run with it on and the onboarding window open.
beforeEach(function () {
    config(['full_import.enabled' => true]);
});

it('takes everything the import wrote back out, and nothing the user had', function () {
    $user = Fixtures::user();
    $own = Account::factory()->create(['user_id' => $user->id, 'name' => 'BBVA', 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $ownRow = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $own->id, 'description' => 'Mine']);
    $ownBalance = AccountBalance::factory()->create(['account_id' => $own->id, 'balance_date' => '2026-08-01']);

    $import = Fixtures::run($this, $user, Fixtures::plan(
        accounts: [
            Fixtures::newAccount('a0', 'Wise'),
            ['key' => 'a1', 'action' => 'map', 'target_account_id' => $own->id],
        ],
        categories: [
            ['key' => 'c0', 'action' => 'create', 'name' => 'Empresa', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c1', 'action' => 'create', 'name' => 'Gastos Empresa', 'parent_key' => 'c0', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c2', 'action' => 'create', 'name' => 'Organic', 'parent_key' => 'c3', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c3', 'action' => 'match', 'category_id' => Fixtures::seeded($user, 'Groceries')->id],
        ],
    ), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee', ['category_key' => 'c1']),
        Fixtures::row('a1', '2026-09-02', -200, 'Imported into BBVA', ['category_key' => 'c2']),
    ], [
        ['account_key' => 'a0', 'date' => '2026-09-01', 'balance' => 1000],
        ['account_key' => 'a1', 'date' => '2026-09-02', 'balance' => 2000],
    ]);

    $wise = Account::query()->where('import_id', $import->id)->sole();

    // After the import the user keeps using the new account and the new categories.
    $laterRow = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $wise->id, 'description' => 'Added by hand']);
    $ownRow->update(['category_id' => Category::query()->where('name', 'Gastos Empresa')->value('id')]);

    $this->actingAs($user)
        ->delete(route('full-import.destroy', $import))
        ->assertRedirect(route('full-import.index'))
        ->assertSessionHas('success');

    expect(Account::withTrashed()->whereKey($wise->id)->exists())->toBeFalse()
        ->and(Transaction::withTrashed()->whereKey($laterRow->id)->exists())->toBeFalse()
        ->and(Transaction::withTrashed()->where('import_id', $import->id)->exists())->toBeFalse()
        ->and(AccountBalance::query()->where('import_id', $import->id)->exists())->toBeFalse()
        ->and($own->fresh())->not->toBeNull()
        ->and($ownBalance->fresh())->not->toBeNull()
        // The user's own movement stays, uncategorized now its category is gone.
        ->and($ownRow->fresh()->category_id)->toBeNull()
        ->and(Category::query()->whereIn('name', ['Empresa', 'Gastos Empresa', 'Organic'])->exists())->toBeFalse()
        ->and(Category::query()->whereKey(Fixtures::seeded($user, 'Groceries')->id)->exists())->toBeTrue()
        ->and($import->fresh()->undone_at)->not->toBeNull();
});

it('keeps the imported categories a split line still files money into, with the ones above them', function () {
    $user = Fixtures::user();
    $own = Account::factory()->create(['user_id' => $user->id, 'name' => 'BBVA', 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $ownRow = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $own->id, 'amount' => -1000, 'description' => 'Mine']);

    $import = Fixtures::run($this, $user, Fixtures::plan(
        accounts: [Fixtures::newAccount('a0', 'Wise')],
        categories: [
            ['key' => 'c0', 'action' => 'create', 'name' => 'Empresa', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c1', 'action' => 'create', 'name' => 'Gastos Empresa', 'parent_key' => 'c0', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c2', 'action' => 'create', 'name' => 'Viajes Empresa', 'parent_key' => 'c0', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c3', 'action' => 'create', 'name' => 'Ocio', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
        ],
    ), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee', ['category_key' => 'c1']),
        Fixtures::row('a0', '2026-09-02', -200, 'Cinema', ['category_key' => 'c3']),
    ]);

    // After the import the user splits a movement of their own, one line
    // filed into a category the import created.
    $gastos = Category::query()->where('name', 'Gastos Empresa')->sole();
    $ownRow->splits()->createMany([
        ['category_id' => $gastos->id, 'amount' => -600, 'position' => 0],
        ['category_id' => Fixtures::seeded($user, 'Groceries')->id, 'amount' => -400, 'position' => 1],
    ]);

    $this->actingAs($user)
        ->delete(route('full-import.destroy', $import))
        ->assertRedirect(route('full-import.index'))
        ->assertSessionHas('success');

    $empresa = Category::query()->where('name', 'Empresa')->first();

    expect($import->fresh()->undone_at)->not->toBeNull()
        ->and(Transaction::withTrashed()->where('import_id', $import->id)->exists())->toBeFalse()
        // The line's category and its parent stay, no longer the import's.
        ->and($gastos->fresh())->not->toBeNull()
        ->and($gastos->fresh()->import_id)->toBeNull()
        ->and($empresa)->not->toBeNull()
        ->and($empresa->import_id)->toBeNull()
        // Everything else the import created goes.
        ->and(Category::query()->whereIn('name', ['Viajes Empresa', 'Ocio'])->exists())->toBeFalse()
        ->and($ownRow->splits()->pluck('category_id')->all())->toContain($gastos->id);
});

it('tells the history how many imported categories undo keeps for split lines', function () {
    $user = Fixtures::user();
    $own = Account::factory()->create(['user_id' => $user->id, 'name' => 'BBVA', 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $ownRow = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $own->id, 'amount' => -1000, 'description' => 'Mine']);

    $import = Fixtures::run($this, $user, Fixtures::plan(
        accounts: [Fixtures::newAccount('a0', 'Wise')],
        categories: [
            ['key' => 'c0', 'action' => 'create', 'name' => 'Empresa', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c1', 'action' => 'create', 'name' => 'Gastos Empresa', 'parent_key' => 'c0', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c2', 'action' => 'create', 'name' => 'Ocio', 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
        ],
    ), [
        Fixtures::row('a0', '2026-09-02', -200, 'Cinema', ['category_key' => 'c2']),
    ]);

    // A line of the user's own movement keeps "Gastos Empresa" and its
    // parent. A line of an imported movement goes with it, so "Ocio" does not stay.
    $ownRow->splits()->createMany([
        ['category_id' => Category::query()->where('name', 'Gastos Empresa')->value('id'), 'amount' => -600, 'position' => 0],
        ['category_id' => Fixtures::seeded($user, 'Groceries')->id, 'amount' => -400, 'position' => 1],
    ]);
    Transaction::query()->where('import_id', $import->id)->sole()->splits()->create([
        'category_id' => Category::query()->where('name', 'Ocio')->value('id'), 'amount' => -200, 'position' => 0,
    ]);

    $this->actingAs($user)->get(route('full-import.index'))
        ->assertInertia(fn ($page) => $page
            ->where('imports.0.summary.categories', 3)
            ->where('imports.0.summary.categories_kept', 2));

    $this->delete(route('full-import.destroy', $import))->assertSessionHas('success');

    expect(Category::query()->whereIn('name', ['Empresa', 'Gastos Empresa'])->count())->toBe(2)
        ->and(Category::query()->where('name', 'Ocio')->exists())->toBeFalse();
});

it('reads the category tree once for the undo summary, however many categories the import created', function () {
    $importWithRootCategories = function (int $roots): array {
        $user = Fixtures::user();
        $categories = [];

        foreach (range(0, $roots - 1) as $index) {
            $categories[] = ['key' => "r{$index}", 'action' => 'create', 'name' => "Root {$index}", 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'];
            $categories[] = ['key' => "s{$index}", 'action' => 'create', 'name' => "Sub {$index}", 'parent_key' => "r{$index}", 'icon' => 'Wallet', 'color' => 'blue'];
        }

        $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')], $categories), [
            Fixtures::row('a0', '2026-09-01', -100, 'Coffee', ['category_key' => 's0']),
        ]);

        return [$user, $import];
    };
    [$oneUser, $one] = $importWithRootCategories(1);
    [$manyUser, $many] = $importWithRootCategories(6);
    $undoer = app(ImportUndoer::class);
    $presenter = app(ImportHistoryPresenter::class);

    expect($many->categories()->count())->toBe(12)
        ->and(countQueries(fn () => $undoer->categoriesKeptForSplits($many))['count'])
        ->toBe(countQueries(fn () => $undoer->categoriesKeptForSplits($one))['count'])
        ->and(countQueries(fn () => $presenter->history($manyUser))['count'])
        ->toBe(countQueries(fn () => $presenter->history($oneUser))['count']);
});

it('lists what undoing would remove', function () {
    $user = Fixtures::user();
    $own = Account::factory()->create(['user_id' => $user->id, 'name' => 'BBVA', 'type' => AccountType::Checking, 'currency_code' => 'EUR']);

    Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise'),
        ['key' => 'a1', 'action' => 'map', 'target_account_id' => $own->id],
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
        Fixtures::row('a0', '2026-09-02', -100, 'Tea'),
        Fixtures::row('a1', '2026-09-02', -200, 'Into BBVA'),
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => Account::query()->where('name', 'Wise')->value('id'),
        'description' => 'Added by hand',
    ]);

    $this->get(route('full-import.index'))
        ->assertInertia(fn ($page) => $page
            ->where('imports.0.summary.accounts', [['name' => 'Wise', 'transactions' => 3]])
            ->where('imports.0.summary.later_transactions', 1)
            ->where('imports.0.summary.transactions', 3)
            ->where('imports.0.summary.into_own_accounts', [['name' => 'BBVA', 'transactions' => 1]]));
});

it('cannot undo the same import twice', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    $this->delete(route('full-import.destroy', $import))->assertSessionHas('success');
    $this->delete(route('full-import.destroy', $import))->assertSessionHasErrors('import');
});

it('undoes on the queue, showing the import as being undone meanwhile', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    Queue::fake([UndoImportJob::class]);

    $this->delete(route('full-import.destroy', $import))->assertSessionHas('success');

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Undoing)
        ->and($import->isUndoable())->toBeFalse()
        ->and(Account::query()->where('import_id', $import->id)->exists())->toBeTrue();

    Queue::assertPushed(UndoImportJob::class, 1);

    // A second click does not queue it again.
    $this->delete(route('full-import.destroy', $import))->assertSessionHasErrors('import');
    Queue::assertPushed(UndoImportJob::class, 1);

    app()->call([new UndoImportJob($import), 'handle']);

    $import->refresh();

    expect($import->undone_at)->not->toBeNull()
        ->and($import->status)->toBe(ImportStatus::Completed)
        ->and(Account::query()->where('import_id', $import->id)->exists())->toBeFalse();
});

it('puts the import back as it was when the undo fails, so it can be tried again', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    Queue::fake([UndoImportJob::class]);
    $this->delete(route('full-import.destroy', $import));

    (new UndoImportJob($import->fresh()))->failed(new RuntimeException('boom'));

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->isUndoable())->toBeTrue()
        ->and($import->stats['undo']['failed'])->toBeTrue();
});

it('keeps an imported account that has since been connected, removing only what the import wrote', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Revolut')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Imported'),
    ]);

    $account = Account::query()->where('import_id', $import->id)->sole();
    $account->update(['banking_connection_id' => BankingConnection::factory()->create(['user_id' => $user->id])->id]);
    $synced = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'description' => 'From the bank']);

    $this->get(route('full-import.index'))
        ->assertInertia(fn ($page) => $page
            ->where('imports.0.summary.connected_accounts', [['name' => 'Revolut']])
            ->where('imports.0.summary.accounts', []));

    $this->delete(route('full-import.destroy', $import));

    expect($account->fresh())->not->toBeNull()
        ->and($account->fresh()->import_id)->toBeNull()
        ->and($synced->fresh())->not->toBeNull()
        ->and(Transaction::query()->where('import_id', $import->id)->exists())->toBeFalse();
});

it('refuses a new import while an earlier one is being undone', function () {
    $user = Fixtures::user();
    $undoing = Import::factory()->for($user)->create(['status' => ImportStatus::Undoing]);
    $draft = Import::factory()->for($user)->draft()->create(['plan' => ['expected' => ['transactions' => 0, 'balances' => 0]]]);

    $this->actingAs($user)->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]),
        'expected_transactions' => 1,
        'expected_balances' => 0,
    ])->assertConflict()->assertJsonPath('message', 'An earlier import is still being undone. Try again in a moment.');

    $this->postJson(route('api.full-imports.start', $draft))->assertConflict()
        ->assertJsonPath('message', 'An earlier import is still being undone. Try again in a moment.');

    expect($draft->fresh()->status)->toBe(ImportStatus::Draft)
        ->and($undoing->fresh()->status)->toBe(ImportStatus::Undoing);
});

it('tells the history an undo broke off, and lets it be tried again', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    Queue::fake([UndoImportJob::class]);
    $this->delete(route('full-import.destroy', $import));
    (new UndoImportJob($import->fresh()))->failed(new RuntimeException('boom'));
    // The worker releases the job's unique lock when it fails; the fake never ran it.
    (new UniqueLock(app(Repository::class)))->release(new UndoImportJob($import));

    $this->get(route('full-import.index'))
        ->assertInertia(fn ($page) => $page
            ->where('imports.0.undoable', true)
            ->where('imports.0.undo_failed', true));

    $this->delete(route('full-import.destroy', $import))->assertSessionHas('success');
    Queue::assertPushed(UndoImportJob::class, 2);
});

it('puts the import back when the undo cannot be queued', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
    ]);

    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down'));

    $this->delete(route('full-import.destroy', $import))->assertSessionHasErrors('import');

    expect($import->fresh()->status)->toBe(ImportStatus::Completed)
        ->and($import->fresh()->isUndoable())->toBeTrue()
        // The unique lock the failed dispatch took is free again.
        ->and((new UniqueLock(app(Repository::class)))->acquire(new UndoImportJob($import)))->toBeTrue();
});

it('takes out a new account the import archived', function () {
    $user = Fixtures::user();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Old savings', ['archive' => true]),
    ]), [Fixtures::row('a0', '2024-03-01', -100, 'Last one')]);

    $old = Account::query()->where('import_id', $import->id)->sole();

    expect($old->isArchived())->toBeTrue();

    $this->actingAs($user)->delete(route('full-import.destroy', $import))->assertRedirect(route('full-import.index'));

    expect(Account::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Transaction::withTrashed()->where('import_id', $import->id)->exists())->toBeFalse();
});
