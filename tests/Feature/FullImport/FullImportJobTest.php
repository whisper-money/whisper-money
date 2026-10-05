<?php

use App\Enums\AccountType;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\ImportStatus;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Tests\Support\FullImportFixtures as Fixtures;

/**
 * A user with what the end-to-end import lands on: a manual "BBVA Conjunta"
 * already holding two movements and a balance, and a connected "Revolut".
 *
 * @return array{user: User, bbva: Account, revolut: Account}
 */
function fullImportLandscape(): array
{
    $user = Fixtures::user();

    $bbva = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'BBVA Conjunta',
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $bbva->id,
        'transaction_date' => '2026-09-10',
        'amount' => -4250,
        'description' => 'Mercadona  Centro',
        'currency_code' => 'EUR',
        'category_id' => null,
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $bbva->id,
        'transaction_date' => '2026-09-11',
        'amount' => -999,
        'description' => 'Something else',
        'currency_code' => 'EUR',
        'external_transaction_id' => 'bt-already-here',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $bbva->id,
        'balance_date' => '2026-09-12',
        'balance' => 100000,
    ]);

    $revolut = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'name' => 'Revolut',
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
    ]);

    return ['user' => $user, 'bbva' => $bbva, 'revolut' => $revolut];
}

/**
 * The plan the wizard would build for the landscape above.
 *
 * @return array<string, mixed>
 */
function fullImportLandscapePlan(User $user, Account $bbva, string $mode = 'add'): array
{
    return Fixtures::plan(
        accounts: [
            Fixtures::newAccount('a0', 'Wise Personal', ['iban' => 'ES91 2100 0418 4502 0005 1332']),
            ['key' => 'a1', 'action' => 'map', 'target_account_id' => $bbva->id],
            Fixtures::newAccount('a2', 'Efectivo', ['type' => 'others']),
            ['key' => 'a3', 'action' => 'merge', 'merge_into_key' => 'a2'],
            ['key' => 'a4', 'action' => 'skip'],
            Fixtures::newAccount('a5', 'Revolut (Banktrack)'),
        ],
        categories: [
            ['key' => 'c0', 'action' => 'create', 'name' => 'Empresa', 'parent_key' => null, 'type' => 'expense', 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c1', 'action' => 'create', 'name' => 'Gastos Empresa', 'parent_key' => 'c0', 'type' => null, 'icon' => 'Wallet', 'color' => 'blue'],
            ['key' => 'c2', 'action' => 'match', 'category_id' => Fixtures::seeded($user, 'Groceries')->id],
            ['key' => 'own', 'action' => 'match', 'category_id' => Fixtures::seeded($user, 'Own account')->id],
            ['key' => 'ignored', 'action' => 'match', 'category_id' => Fixtures::seeded($user, 'Other transfers')->id],
        ],
        mode: $mode,
    );
}

/**
 * @return list<array<string, mixed>>
 */
function fullImportLandscapeRows(): array
{
    return [
        Fixtures::row('a0', '2026-09-24', -1499, 'Netflix.com', ['external_id' => 'bt-1']),
        Fixtures::row('a0', '2026-09-22', -2310, 'Material de oficina', ['external_id' => 'bt-2', 'category_key' => 'c1', 'notes' => 'Compra con tarjeta']),
        Fixtures::row('a0', '2026-09-21', 1004, 'Recarga de cuenta', ['external_id' => 'bt-3', 'category_key' => 'own']),
        Fixtures::row('a0', '2026-09-20', -5000, 'Ignored in Banktrack', ['external_id' => 'bt-4', 'category_key' => 'ignored']),
        // Same day, amount and description as BBVA's own row: a duplicate.
        Fixtures::row('a1', '2026-09-10', -4250, 'mercadona centro', ['external_id' => 'bt-5', 'category_key' => 'c2']),
        // Its external id is already on BBVA: a duplicate too.
        Fixtures::row('a1', '2026-09-11', -1200, 'Different text', ['external_id' => 'bt-already-here']),
        Fixtures::row('a1', '2026-09-13', -5876, 'Mercadona', ['external_id' => 'bt-6', 'category_key' => 'c2']),
        Fixtures::row('a2', '2026-09-18', -2000, 'Cafe', ['external_id' => 'bt-7']),
        Fixtures::row('a3', '2026-09-19', -500, 'Kiosko', ['external_id' => 'bt-8']),
        Fixtures::row('a5', '2026-09-17', -1500, 'Revolut card payment', ['external_id' => 'bt-9']),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function fullImportLandscapeBalances(): array
{
    return [
        ['account_key' => 'a0', 'date' => '2026-09-24', 'balance' => 284493],
        ['account_key' => 'a0', 'date' => '2026-09-22', 'balance' => 285992],
        // BBVA already has a balance that day: the user's figure stays.
        ['account_key' => 'a1', 'date' => '2026-09-12', 'balance' => 5],
        ['account_key' => 'a1', 'date' => '2026-09-13', 'balance' => 94124],
        // A merged account's running balance is not its target's.
        ['account_key' => 'a3', 'date' => '2026-09-19', 'balance' => 777],
    ];
}

it('imports accounts, the category tree, transfers, ignored rows and balances', function () {
    ['user' => $user, 'bbva' => $bbva, 'revolut' => $revolut] = fullImportLandscape();

    $import = Fixtures::run($this, $user, fullImportLandscapePlan($user, $bbva), fullImportLandscapeRows(), fullImportLandscapeBalances());

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->plan)->toBeNull()
        ->and($import->chunks()->count())->toBe(0);

    $wise = Account::query()->where('user_id', $user->id)->where('name', 'Wise Personal')->firstOrFail();
    $efectivo = Account::query()->where('user_id', $user->id)->where('name', 'Efectivo')->firstOrFail();
    $revolutCopy = Account::query()->where('user_id', $user->id)->where('name', 'Revolut (Banktrack)')->firstOrFail();

    expect($wise->import_id)->toBe($import->id)
        ->and($wise->iban)->toBe('ES9121000418450200051332')
        ->and($wise->space_id)->toBe($user->current_space_id)
        ->and($efectivo->type)->toBe(AccountType::Others)
        ->and($revolutCopy->isConnected())->toBeFalse()
        ->and($bbva->fresh()->import_id)->toBeNull();

    // The category tree: Empresa › Gastos Empresa, the child inheriting the type.
    $empresa = Category::query()->where('user_id', $user->id)->where('name', 'Empresa')->firstOrFail();
    $gastos = Category::query()->where('user_id', $user->id)->where('name', 'Gastos Empresa')->firstOrFail();

    expect($empresa->parent_id)->toBeNull()
        ->and($empresa->import_id)->toBe($import->id)
        ->and($gastos->parent_id)->toBe($empresa->id)
        ->and($gastos->type)->toBe(CategoryType::Expense);

    $byExternalId = Transaction::query()->where('import_id', $import->id)->get()->keyBy('external_transaction_id');

    expect($byExternalId->keys()->sort()->values()->all())->toBe(['bt-1', 'bt-2', 'bt-3', 'bt-4', 'bt-6', 'bt-7', 'bt-8', 'bt-9'])
        ->and($byExternalId['bt-2']->category_id)->toBe($gastos->id)
        ->and($byExternalId['bt-2']->category_source)->toBe(CategorySource::Manual)
        ->and($byExternalId['bt-2']->notes)->toBe('Compra con tarjeta')
        ->and($byExternalId['bt-2']->source)->toBe(TransactionSource::Imported)
        ->and($byExternalId['bt-3']->category_id)->toBe(Fixtures::seeded($user, 'Own account')->id)
        ->and($byExternalId['bt-4']->category_id)->toBe(Fixtures::seeded($user, 'Other transfers')->id)
        ->and($byExternalId['bt-1']->category_id)->toBeNull()
        ->and($byExternalId['bt-1']->category_source)->toBeNull()
        ->and($byExternalId['bt-6']->account_id)->toBe($bbva->id)
        ->and($byExternalId['bt-8']->account_id)->toBe($efectivo->id)
        ->and($byExternalId['bt-9']->account_id)->toBe($revolutCopy->id);

    // The connected account is never written to.
    expect($revolut->transactions()->count())->toBe(0);

    expect($import->stats['transactions'])->toMatchArray(['total' => 10, 'processed' => 10, 'imported' => 8, 'duplicates' => 2, 'skipped' => 0])
        ->and($import->stats['accounts'])->toEqual(['created' => 3, 'mapped' => 1, 'banks_created' => 0])
        ->and($import->stats['categories'])->toEqual(['created' => 2, 'matched' => 3])
        ->and($import->stats['balances'])->toEqual(['total' => 5, 'imported' => 3])
        ->and(collect($import->stats['per_account'])->firstWhere('account_id', $bbva->id))
        ->toMatchArray(['created' => false, 'imported' => 1, 'duplicates' => 2]);

    expect($wise->balances()->orderBy('balance_date')->pluck('balance', 'balance_date')->all())
        ->toBe(['2026-09-22' => 285992, '2026-09-24' => 284493])
        ->and($wise->balances()->where('derived', true)->exists())->toBeFalse()
        ->and($bbva->balances()->orderBy('balance_date')->pluck('balance', 'balance_date')->all())
        ->toBe(['2026-09-12' => 100000, '2026-09-13' => 94124])
        ->and($efectivo->balances()->count())->toBe(0);
});

it('skips the rows of an account the user chose not to import', function () {
    $user = Fixtures::user();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise'),
        ['key' => 'a1', 'action' => 'skip'],
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Kept'),
        Fixtures::row('a1', '2026-09-01', -100, 'Left out'),
    ]);

    expect($import->stats['transactions'])->toMatchArray(['imported' => 1, 'skipped' => 1])
        ->and(Transaction::query()->where('description', 'Left out')->exists())->toBeFalse();
});

it('is idempotent when the same file is imported again onto the accounts it created', function () {
    ['user' => $user, 'bbva' => $bbva] = fullImportLandscape();

    $first = Fixtures::run($this, $user, fullImportLandscapePlan($user, $bbva), fullImportLandscapeRows(), fullImportLandscapeBalances());
    $created = Account::query()->where('import_id', $first->id)->pluck('id', 'name');
    $transactionsBefore = Transaction::query()->count();

    // The second time round the wizard maps each file account onto the
    // account the first import created, which is now one of the user's own.
    $second = Fixtures::run($this, $user, Fixtures::plan([
        ['key' => 'a0', 'action' => 'map', 'target_account_id' => $created['Wise Personal']],
        ['key' => 'a1', 'action' => 'map', 'target_account_id' => $bbva->id],
        ['key' => 'a2', 'action' => 'map', 'target_account_id' => $created['Efectivo']],
        ['key' => 'a3', 'action' => 'merge', 'merge_into_key' => 'a2'],
        ['key' => 'a4', 'action' => 'skip'],
        ['key' => 'a5', 'action' => 'map', 'target_account_id' => $created['Revolut (Banktrack)']],
    ], fullImportLandscapePlan($user, $bbva)['categories']), fullImportLandscapeRows(), fullImportLandscapeBalances());

    expect(Transaction::query()->count())->toBe($transactionsBefore)
        ->and($second->stats['transactions'])->toMatchArray(['imported' => 0, 'duplicates' => 10])
        // The categories are found under the same parent rather than created twice.
        ->and($second->stats['categories']['created'])->toBe(0)
        ->and(Category::query()->where('user_id', $user->id)->where('name', 'Empresa')->count())->toBe(1);
});

it('wipes the manual accounts of the space before importing, keeping connected ones and categories', function () {
    ['user' => $user, 'bbva' => $bbva, 'revolut' => $revolut] = fullImportLandscape();

    $connectedRow = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $revolut->id,
        'currency_code' => 'EUR',
    ]);
    $trashedRow = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $bbva->id]);
    $trashedRow->delete();
    $categoriesBefore = Category::query()->where('user_id', $user->id)->count();

    $import = Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'BBVA Conjunta'),
    ], mode: 'wipe'), [
        Fixtures::row('a0', '2026-09-10', -4250, 'Mercadona Centro'),
    ]);

    expect(Account::withTrashed()->whereKey($bbva->id)->exists())->toBeFalse()
        ->and(Transaction::withTrashed()->where('account_id', $bbva->id)->exists())->toBeFalse()
        ->and(AccountBalance::query()->where('account_id', $bbva->id)->exists())->toBeFalse()
        ->and($revolut->fresh())->not->toBeNull()
        ->and($connectedRow->fresh())->not->toBeNull()
        ->and(Category::query()->where('user_id', $user->id)->count())->toBe($categoriesBefore)
        ->and($import->stats['wiped'])->toEqual(['accounts' => 1, 'transactions' => 3]);

    // Recreated from the file, so nothing in it counts as a duplicate any more.
    $recreated = Account::query()->where('import_id', $import->id)->sole();

    expect($recreated->name)->toBe('BBVA Conjunta')
        ->and($recreated->transactions()->count())->toBe(1);
});

it('sets the main currency from the first account of a user who had none', function () {
    $user = Fixtures::user(['currency_code' => 'USD']);

    Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Bitcoin', ['currency_code' => 'BTC']),
        Fixtures::newAccount('a1', 'Wise', ['currency_code' => 'EUR']),
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Fee'),
        Fixtures::row('a1', '2026-09-01', -100, 'Coffee'),
    ]);

    // BTC can hold an account but not be a main currency, so EUR went first.
    expect($user->fresh()->currency_code)->toBe('EUR');
});

it('links each new account to the bank the wizard matched', function () {
    $user = Fixtures::user();
    $bank = Bank::factory()->create(['name' => 'Wise', 'user_id' => null]);

    Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise Personal', ['bank_id' => $bank->id]),
    ]), [Fixtures::row('a0', '2026-09-01', -100, 'Coffee')]);

    expect(Account::query()->where('name', 'Wise Personal')->value('bank_id'))->toBe($bank->id);
});
