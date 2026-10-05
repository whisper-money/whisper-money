<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Transaction;
use Tests\Support\FullImportFixtures as Fixtures;

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
