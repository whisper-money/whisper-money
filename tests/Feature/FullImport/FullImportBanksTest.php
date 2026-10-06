<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use Tests\Support\FullImportFixtures as Fixtures;

it('creates a bank of the user\'s own for an account whose bank is not in the catalog', function () {
    $user = Fixtures::user();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'MyInvestor', ['new_bank_name' => 'MyInvestor']),
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')]);

    $bank = Bank::query()->where('user_id', $user->id)->sole();

    expect($bank->name)->toBe('MyInvestor')
        ->and($bank->logo)->toBeNull()
        ->and($bank->import_id)->toBe($import->id)
        ->and(Account::query()->where('import_id', $import->id)->value('bank_id'))->toBe($bank->id)
        ->and($import->stats['accounts']['banks_created'])->toBe(1)
        ->and($bank->toArray())->not->toHaveKey('import_id');
});

it('gives two accounts of the same unknown bank one bank between them', function () {
    $user = Fixtures::user();

    Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'LARES Corriente', ['new_bank_name' => 'LARES']),
        Fixtures::newAccount('a1', 'Lares Ahorro', ['new_bank_name' => 'Lares', 'type' => 'savings']),
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
        Fixtures::row('a1', '2026-09-01', -100, 'Tea'),
    ]);

    $bank = Bank::query()->where('user_id', $user->id)->sole();

    expect(Account::query()->where('user_id', $user->id)->pluck('bank_id')->unique()->all())->toBe([$bank->id]);
});

it('reuses a bank of the user\'s own with that name instead of creating it again', function () {
    $user = Fixtures::user();
    $own = Bank::factory()->create(['name' => 'Lares', 'user_id' => $user->id, 'logo' => null]);

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Lares', ['new_bank_name' => 'LÁRES']),
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')]);

    expect(Bank::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Account::query()->where('import_id', $import->id)->value('bank_id'))->toBe($own->id)
        ->and($import->stats['accounts']['banks_created'])->toBe(0);
});

it('creates no bank on a second import of the same file', function () {
    $user = Fixtures::user();
    $plan = Fixtures::plan([Fixtures::newAccount('a0', 'MyInvestor', ['new_bank_name' => 'MyInvestor'])]);
    $rows = [Fixtures::row('a0', '2026-09-01', -100, 'Coffee', ['external_id' => 'bt-1'])];

    $first = Fixtures::run($this, $user, $plan, $rows);
    $created = Account::query()->where('import_id', $first->id)->sole();

    Fixtures::run($this, $user, Fixtures::plan([
        ['key' => 'a0', 'action' => 'map', 'target_account_id' => $created->id],
    ]), $rows);
    Fixtures::run($this, $user, $plan, $rows);

    expect(Bank::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('gives a cash account no bank unless one is picked', function () {
    $user = Fixtures::user();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Cash', ['type' => 'others']),
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')]);

    expect(Account::query()->where('import_id', $import->id)->value('bank_id'))->toBeNull()
        ->and(Bank::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('picks the bank given by id over a new bank name', function () {
    $user = Fixtures::user();
    $catalog = Bank::factory()->create(['name' => 'Wise', 'user_id' => null]);

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise', ['bank_id' => $catalog->id, 'new_bank_name' => 'Wise']),
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')]);

    expect(Account::query()->where('import_id', $import->id)->value('bank_id'))->toBe($catalog->id)
        ->and(Bank::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('refuses a bank the user cannot see, and a new bank name that is too long', function () {
    $user = Fixtures::user();
    $someoneElses = Bank::factory()->create(['user_id' => Fixtures::user()->id]);

    $this->actingAs($user)->postJson(route('api.full-imports.store'), [
        ...Fixtures::plan([
            Fixtures::newAccount('a0', 'One', ['bank_id' => $someoneElses->id]),
            Fixtures::newAccount('a1', 'Two', ['new_bank_name' => str_repeat('x', 256)]),
        ]),
        'expected_transactions' => 1,
        'expected_balances' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors(['accounts.0.bank_id', 'accounts.1.new_bank_name']);
});

it('removes the banks it created on undo, keeping one another account still uses', function () {
    $user = Fixtures::user();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'MyInvestor', ['new_bank_name' => 'MyInvestor']),
        Fixtures::newAccount('a1', 'Lares', ['new_bank_name' => 'Lares']),
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
        Fixtures::row('a1', '2026-09-01', -100, 'Tea'),
    ]);

    $lares = Bank::query()->where('name', 'Lares')->sole();
    $byHand = Account::factory()->create(['user_id' => $user->id, 'bank_id' => $lares->id, 'type' => AccountType::Savings]);

    $this->delete(route('full-import.destroy', $import))->assertSessionHas('success');

    expect(Bank::query()->where('name', 'MyInvestor')->exists())->toBeFalse()
        ->and($lares->fresh())->not->toBeNull()
        ->and($lares->fresh()->import_id)->toBeNull()
        ->and($byHand->fresh())->not->toBeNull();
});

it('creates no bank for a cash account, nor for a name with nothing to match by', function () {
    $user = Fixtures::user();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Cash', ['type' => 'others', 'new_bank_name' => 'Cash']),
        Fixtures::newAccount('a1', 'Piggy', ['new_bank_name' => '🐷 ✨']),
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
        Fixtures::row('a1', '2026-09-01', -100, 'Tea'),
    ]);

    expect(Account::query()->where('import_id', $import->id)->whereNotNull('bank_id')->exists())->toBeFalse()
        ->and(Bank::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $this->postJson(route('api.full-imports.bank-matches'), ['names' => ['🐷 ✨']])
        ->assertJsonPath('matches', ['🐷 ✨' => null]);
});
