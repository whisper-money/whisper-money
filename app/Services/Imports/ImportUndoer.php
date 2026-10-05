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
 * with everything on them (rows added since by hand or by another import
 * included, which the undo dialog says), the movements and balances it put
 * into the user's own accounts, and the categories it created. The user's own
 * accounts stay.
 *
 * What a "start from scratch" import wiped before it ran is gone for good;
 * undo cannot bring it back.
 */
class ImportUndoer
{
    public function __construct(
        private CategoryTree $tree,
        private AccountPurger $purger,
    ) {}

    public function undo(Import $import): void
    {
        DB::transaction(function () use ($import): void {
            Transaction::withTrashed()->where('import_id', $import->id)->forceDelete();
            AccountBalance::query()->where('import_id', $import->id)->delete();

            $this->purger->purge(Account::withTrashed()->where('import_id', $import->id)->pluck('id'));

            $this->deleteCreatedCategories($import);

            $import->forceFill(['undone_at' => now()])->save();
        });
    }

    /**
     * The categories go the way Settings deletes a category with its
     * subcategories: whatever still points at them is left uncategorized
     * rather than deleted with them, since by now it can be a movement the
     * user filed there by hand. Uncategorized for real, too: a row filed by
     * hand into a category that is gone is no longer the user's choice, so
     * the rules and the AI may file it again.
     */
    private function deleteCreatedCategories(Import $import): void
    {
        $created = Category::query()->where('import_id', $import->id)->get();
        $createdIds = $created->pluck('id')->flip();
        $roots = $created->reject(fn (Category $category): bool => $category->parent_id !== null && $createdIds->has($category->parent_id));

        foreach ($roots as $root) {
            Transaction::query()
                ->where('user_id', $root->user_id)
                ->whereIn('category_id', [$root->id, ...$this->tree->descendantIds($root)])
                ->update(['category_source' => null]);

            $this->tree->deleteSubtree($root);
        }
    }
}
