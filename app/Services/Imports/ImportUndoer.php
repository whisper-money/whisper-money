<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\CategoryTree;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Takes a full import back out of the user's data: the accounts it created,
 * with everything on them (rows added since by hand or by another import
 * included, which the undo dialog says), the movements and balances it put
 * into the user's own accounts, and the categories and banks it created. The
 * user's own accounts stay, and so does an account the import created that
 * has since been connected to a bank: only what the import wrote leaves it.
 *
 * Runs from UndoImportJob, in short steps rather than one transaction, and
 * every step only removes what is still there, so a retry finishes the job.
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
        $transactions = $this->purger->deleteInChunks(Transaction::withTrashed()->where('import_id', $import->id));
        $balances = $this->purger->deleteInChunks(AccountBalance::query()->where('import_id', $import->id));

        $created = Account::withTrashed()->where('import_id', $import->id)->get(['id', 'banking_connection_id']);
        [$connected, $manual] = $created->partition(fn (Account $account): bool => $account->isConnected());

        $transactions += $this->purger->purge($manual->pluck('id'));

        // Kept, and no longer counted as the import's.
        Account::withTrashed()->whereIn('id', $connected->pluck('id'))->update(['import_id' => null]);

        $categories = $this->deleteCreatedCategories($import);
        $banks = $this->deleteCreatedBanks($import);

        $import->forceFill([
            'status' => ImportStatus::tryFrom((string) ($import->stats['undo']['previous_status'] ?? '')) ?? ImportStatus::Completed,
            'undone_at' => now(),
        ])->save();

        $requestedAt = $import->stats['undo']['requested_at'] ?? null;

        Log::info('Full import undone', $import->logContext([
            'transactions' => $transactions,
            'balances' => $balances,
            'accounts_deleted' => $manual->count(),
            'accounts_kept_connected' => $connected->count(),
            'categories' => $categories,
            'banks_deleted' => $banks,
            'duration_seconds' => $requestedAt !== null ? Carbon::parse($requestedAt)->diffInSeconds(now()) : null,
        ]));
    }

    /**
     * The categories go the way Settings deletes a category with its
     * subcategories: whatever still points at them is left uncategorized
     * rather than deleted with them, since by now it can be a movement the
     * user filed there by hand. Uncategorized for real, too: a row filed by
     * hand into a category that is gone is no longer the user's choice, so
     * the rules and the AI may file it again.
     */
    /**
     * @return int how many categories the import created
     */
    private function deleteCreatedCategories(Import $import): int
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

        return $created->count();
    }

    /**
     * A bank the import created goes when nothing points at it any more. One
     * the user has since put another account in stays: deleting a bank takes
     * its accounts with it (the foreign key cascades), trashed ones included.
     */
    private function deleteCreatedBanks(Import $import): int
    {
        $deleted = 0;

        foreach (Bank::query()->where('import_id', $import->id)->get() as $bank) {
            if (Account::withTrashed()->where('bank_id', $bank->id)->exists()) {
                $bank->forceFill(['import_id' => null])->save();

                continue;
            }

            $bank->forceDelete();
            $deleted++;
        }

        return $deleted;
    }
}
