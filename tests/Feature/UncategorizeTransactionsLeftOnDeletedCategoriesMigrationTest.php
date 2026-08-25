<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

function runUncategorizeTransactionsLeftOnDeletedCategoriesMigration(): void
{
    $migration = require database_path('migrations/2026_08_25_090000_uncategorize_transactions_left_on_deleted_categories.php');
    $migration->up();
}

function transactionUnderRepair(Category $category): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $category->user_id,
        'account_id' => Account::factory()->create(['user_id' => $category->user_id])->id,
        'category_id' => $category->id,
    ]);
}

it('cuts loose the transactions pointing at a deleted category', function () {
    $user = User::factory()->create();
    $dead = Category::factory()->create(['user_id' => $user->id]);
    $alive = Category::factory()->create(['user_id' => $user->id]);

    $orphan = transactionUnderRepair($dead);
    $kept = transactionUnderRepair($alive);

    $dead->delete();

    runUncategorizeTransactionsLeftOnDeletedCategoriesMigration();

    expect($orphan->fresh()->category_id)->toBeNull()
        ->and($kept->fresh()->category_id)->toBe($alive->id);
});

it('reaches the deleted transactions too, so an undelete does not bring the dead id back', function () {
    $user = User::factory()->create();
    $dead = Category::factory()->create(['user_id' => $user->id]);

    $transaction = transactionUnderRepair($dead);
    $transaction->delete();
    $dead->delete();

    runUncategorizeTransactionsLeftOnDeletedCategoriesMigration();

    expect(Transaction::withTrashed()->find($transaction->id)->category_id)->toBeNull();
});
