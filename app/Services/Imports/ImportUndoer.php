<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\CategoryTree;
use Illuminate\Support\Facades\DB;

/**
 * Takes a full import back out of the user's data: the accounts it created,
 * with everything on them (rows added by hand since included, which the undo
 * dialog says), the movements and balances it put into the user's own
 * accounts, and the categories it created. The user's own accounts stay.
 *
 * What a "start from scratch" import wiped before it ran is gone for good;
 * undo cannot bring it back.
 */
class ImportUndoer
{
    public function __construct(private CategoryTree $tree) {}

    public function undo(Import $import): void
    {
        DB::transaction(function () use ($import): void {
            $createdAccountIds = Account::withTrashed()->where('import_id', $import->id)->pluck('id');

            Transaction::withTrashed()->where('import_id', $import->id)->forceDelete();
            AccountBalance::query()->where('import_id', $import->id)->delete();

            if ($createdAccountIds->isNotEmpty()) {
                Transaction::withTrashed()->whereIn('account_id', $createdAccountIds)->forceDelete();
                AccountBalance::query()->whereIn('account_id', $createdAccountIds)->delete();
                Account::withTrashed()->whereIn('id', $createdAccountIds)->forceDelete();
            }

            $this->deleteCreatedCategories($import);

            $import->forceFill(['undone_at' => now()])->save();
        });
    }

    /**
     * The categories go the way Settings deletes a category with its
     * subcategories: whatever still points at them is left uncategorized
     * rather than deleted with them, since by now it can be a movement the
     * user filed there by hand.
     */
    private function deleteCreatedCategories(Import $import): void
    {
        $created = Category::query()->where('import_id', $import->id)->get();
        $createdIds = $created->pluck('id')->flip();

        $created
            ->reject(fn (Category $category): bool => $category->parent_id !== null && $createdIds->has($category->parent_id))
            ->each(fn (Category $category) => $this->tree->deleteSubtree($category));
    }
}
