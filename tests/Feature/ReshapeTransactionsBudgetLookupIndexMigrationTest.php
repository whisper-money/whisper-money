<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const BUDGET_LOOKUP_COLUMNS = ['user_id', 'deleted_at', 'transaction_date', 'id', 'category_id'];

function reshapeBudgetLookupIndexMigration(): object
{
    return require database_path('migrations/2026_10_24_183052_reshape_transactions_budget_lookup_index_for_soft_deletes.php');
}

/**
 * @return array<string, list<string>>
 */
function budgetLookupIndexes(): array
{
    return collect(Schema::getIndexes('transactions'))
        ->filter(fn (array $index) => str_starts_with($index['name'], 'idx_transactions_budget_lookup'))
        ->mapWithKeys(fn (array $index) => [$index['name'] => $index['columns']])
        ->all();
}

/**
 * @return list<string>
 */
function alterStatementsWhile(Closure $callback): array
{
    $statements = [];
    $listening = true;

    DB::listen(function ($query) use (&$statements, &$listening) {
        if ($listening && str_starts_with(strtolower($query->sql), 'alter table')) {
            $statements[] = $query->sql;
        }
    });

    $callback();
    $listening = false;

    return $statements;
}

// DDL commits implicitly in MySQL, outside RefreshDatabase's transaction, so
// put the final shape back even when a test fails halfway through a swap.
afterEach(fn () => reshapeBudgetLookupIndexMigration()->up());

it('does nothing when the index already has its final shape', function () {
    expect(budgetLookupIndexes())->toBe(['idx_transactions_budget_lookup' => BUDGET_LOOKUP_COLUMNS]);

    $statements = alterStatementsWhile(fn () => reshapeBudgetLookupIndexMigration()->up());

    expect($statements)->toBe([])
        ->and(budgetLookupIndexes())->toBe(['idx_transactions_budget_lookup' => BUDGET_LOOKUP_COLUMNS]);
});

it('swaps the old index for the new shape online and back', function () {
    $migration = reshapeBudgetLookupIndexMigration();

    $migration->down();

    expect(budgetLookupIndexes())->toBe(['idx_transactions_budget_lookup' => ['user_id', 'transaction_date', 'category_id']]);

    $statements = alterStatementsWhile(fn () => $migration->up());

    expect($statements)->toHaveCount(2)
        ->each->toContain('ALGORITHM=INPLACE, LOCK=NONE')
        ->and(budgetLookupIndexes())->toBe(['idx_transactions_budget_lookup' => BUDGET_LOOKUP_COLUMNS]);
});

it('only swaps the name when the new index was built ahead of deploy', function () {
    $migration = reshapeBudgetLookupIndexMigration();
    $migration->down();
    DB::statement('ALTER TABLE transactions ADD INDEX idx_transactions_budget_lookup_next (user_id, deleted_at, transaction_date, id, category_id)');

    $statements = alterStatementsWhile(fn () => $migration->up());

    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toContain('RENAME INDEX idx_transactions_budget_lookup_next TO idx_transactions_budget_lookup')
        ->and(budgetLookupIndexes())->toBe(['idx_transactions_budget_lookup' => BUDGET_LOOKUP_COLUMNS]);
});

it('serves the transactions list order from the index without a filesort', function () {
    $user = User::factory()->create();
    Transaction::factory()->count(3)->create(['user_id' => $user->id]);

    // A handful of rows makes any plan a full scan, so the index is forced and
    // this checks its shape serves the order; the soft-delete scope supplies
    // the `deleted_at is null` that makes it a `ref` lookup.
    $query = Transaction::query()
        ->from(DB::raw('transactions force index (idx_transactions_budget_lookup)'))
        ->where('user_id', $user->id)
        ->orderBy('transaction_date', 'desc')
        ->orderBy('id', 'desc')
        ->limit(51);

    $plan = collect(DB::select('explain '.$query->toSql(), $query->getBindings()))->sole();

    expect($plan->key)->toBe('idx_transactions_budget_lookup')
        ->and($plan->type)->toBe('ref')
        ->and((string) $plan->Extra)->not->toContain('filesort');
});
