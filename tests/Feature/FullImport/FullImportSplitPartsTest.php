<?php

use App\Enums\AccountType;
use App\Enums\CategorySource;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Import;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionSplitter;
use Illuminate\Support\Facades\DB;
use Tests\Support\FullImportFixtures as Fixtures;
use Tests\TestCase;

/*
 * A row a full import wrote that the user split since is still one movement
 * of the import: a re-run of the file matches its original and creates
 * nothing, undo takes the original and its parts, and the history counts it
 * once.
 */

beforeEach(function () {
    config(['full_import.enabled' => true]);
});

/**
 * Import $rows onto $account, an account the user already had.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function importOnto(TestCase $test, User $user, Account $account, array $rows): Import
{
    return Fixtures::run($test, $user, Fixtures::plan([['key' => 'a0', 'action' => 'map', 'target_account_id' => $account->id]]), $rows);
}

/**
 * @return array{0: User, 1: Account}
 */
function userWithOwnAccount(): array
{
    $user = Fixtures::user();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'BBVA', 'type' => AccountType::Checking, 'currency_code' => 'EUR']);

    return [$user, $account];
}

it('matches a split original on a second run of the same file, and never brings the row or its parts back', function () {
    [$user, $account] = userWithOwnAccount();
    $rows = [
        Fixtures::row('a0', '2026-09-01', -4250, 'Mercadona'),
        Fixtures::row('a0', '2026-09-02', -1000, 'Uber', ['external_id' => 'ext-uber']),
    ];
    $first = importOnto($this, $user, $account, $rows);
    $imported = Transaction::query()->where('import_id', $first->id)->orderBy('transaction_date')->get();

    foreach ($imported as $row) {
        app(TransactionSplitter::class)->split($row, [['amount' => (int) round($row->amount / 2)], ['amount' => $row->amount - (int) round($row->amount / 2)]]);
    }

    $second = importOnto($this, $user, $account, $rows);

    expect($second->fresh()->stats['transactions'])->toMatchArray(['imported' => 0, 'duplicates' => 2])
        ->and(Transaction::withTrashed()->where('import_id', $second->id)->exists())->toBeFalse()
        ->and($account->transactions()->count())->toBe(4)
        ->and($account->transactions()->whereNull('split_parent_id')->exists())->toBeFalse();
});

it('takes an imported row and its parts out on undo, with their budget rows and labels', function () {
    [$user, $account] = userWithOwnAccount();
    $groceries = Fixtures::seeded($user, 'Groceries');
    $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $account->space_id]);
    $budget = Budget::factory()->forCategories($groceries)->create(['user_id' => $user->id]);
    $period = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    $import = importOnto($this, $user, $account, [Fixtures::row('a0', '2026-09-01', -4250, 'Mercadona')]);
    $original = Transaction::query()->where('import_id', $import->id)->sole();

    $parts = app(TransactionSplitter::class)->split($original, [
        ['amount' => -3000, 'category_id' => $groceries->id, 'label_ids' => [$label->id]],
        ['amount' => -1250],
    ]);
    $ids = [$original->id, ...$parts->pluck('id')->all()];

    expect($parts->pluck('import_id')->unique()->all())->toBe([$import->id])
        ->and((int) $period->budgetTransactions()->sole()->amount)->toBe(3000)
        ->and(DB::table('label_transaction')->where('label_id', $label->id)->count())->toBe(1);

    $this->actingAs($user)->delete(route('full-import.destroy', $import))->assertRedirect(route('full-import.index'));

    expect(Transaction::withTrashed()->whereKey($ids)->exists())->toBeFalse()
        ->and(BudgetTransaction::query()->whereIn('transaction_id', $ids)->exists())->toBeFalse()
        ->and(DB::table('label_transaction')->whereIn('transaction_id', $ids)->exists())->toBeFalse()
        ->and($label->fresh())->not->toBeNull()
        ->and($account->fresh())->not->toBeNull();
});

it('takes out the parts of a row on an account the import created, wherever the chunks fall', function () {
    $user = Fixtures::user();
    $import = Fixtures::run($this, $user, Fixtures::plan([Fixtures::newAccount('a0', 'Wise')]), [
        Fixtures::row('a0', '2026-09-01', -4250, 'Mercadona'),
    ]);
    $original = Transaction::query()->where('import_id', $import->id)->sole();
    $parts = app(TransactionSplitter::class)->split($original, [['amount' => -3000], ['amount' => -1250]]);
    // A part the import did not write, as if the user had split it before
    // the import reached it: the account purge still takes it.
    Transaction::withTrashed()->whereKey($parts->first()->id)->update(['import_id' => null]);

    $this->actingAs($user)->delete(route('full-import.destroy', $import))->assertRedirect(route('full-import.index'));

    expect(Transaction::withTrashed()->whereKey([$original->id, ...$parts->pluck('id')->all()])->exists())->toBeFalse()
        ->and(Account::withTrashed()->where('name', 'Wise')->exists())->toBeFalse();
});

it('counts a row the user split since once in the import history', function () {
    [$user, $account] = userWithOwnAccount();
    Fixtures::run($this, $user, Fixtures::plan([
        Fixtures::newAccount('a0', 'Wise'),
        ['key' => 'a1', 'action' => 'map', 'target_account_id' => $account->id],
    ]), [
        Fixtures::row('a0', '2026-09-01', -100, 'Coffee'),
        Fixtures::row('a0', '2026-09-02', -900, 'Tea'),
        Fixtures::row('a1', '2026-09-02', -200, 'Into BBVA'),
    ]);

    foreach (Transaction::query()->whereIn('description', ['Tea', 'Into BBVA'])->get() as $row) {
        app(TransactionSplitter::class)->split($row, [['amount' => $row->amount + 50], ['amount' => -50]]);
    }

    $this->get(route('full-import.index'))
        ->assertInertia(fn ($page) => $page
            ->where('imports.0.summary.accounts', [['name' => 'Wise', 'transactions' => 2]])
            ->where('imports.0.summary.later_transactions', 0)
            ->where('imports.0.summary.transactions', 3)
            ->where('imports.0.summary.into_own_accounts', [['name' => 'BBVA', 'transactions' => 1]]));
});

it('splits a row Money Pro had split, keeping the categories given to its parts from the rules and the AI', function () {
    $user = Fixtures::user();
    $groceries = Fixtures::seeded($user, 'Groceries');
    $restaurants = Fixtures::seeded($user, 'Cafes, restaurants, bars');
    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
        'action_category_id' => $groceries->id,
    ]);
    $import = Fixtures::run($this, $user, [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Cuenta Ficticia')]),
        'source' => 'money_pro',
        'file_name' => 'money-pro.csv',
    ], [
        Fixtures::row('a0', '2026-09-01', -4250, 'Mercadona', ['notes' => 'Split in Money Pro: Supermercado Restaurantes', 'source_split' => true]),
    ]);
    $original = Transaction::query()->where('import_id', $import->id)->with('labels')->sole();

    $parts = app(TransactionSplitter::class)->split($original, [
        ['amount' => -3000, 'category_id' => $restaurants->id],
        ['amount' => -1250, 'category_id' => $groceries->id],
    ])->map->fresh();

    expect($parts->pluck('category_id')->all())->toBe([$restaurants->id, $groceries->id])
        ->and($parts->pluck('category_source')->unique()->all())->toBe([CategorySource::Manual])
        ->and($parts->pluck('categorized_by_rule_id')->unique()->all())->toBe([null])
        ->and($parts->pluck('notes')->unique()->all())->toBe(['Split in Money Pro: Supermercado Restaurantes'])
        ->and($parts->pluck('import_id')->unique()->all())->toBe([$import->id])
        ->and($original->labels)->toHaveCount(1)
        ->and(Transaction::query()->where('import_id', $import->id)->scopes('openToImportAi')->exists())->toBeFalse();
});
