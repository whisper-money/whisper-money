<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * "Start from scratch": deletes the user's manual accounts in the import's
 * space, with every movement and balance on them, before the import writes
 * anything. Connected accounts, categories, labels and rules stay.
 *
 * Exactly the accounts the wizard listed go: the user's live manual ones,
 * archived included. One already deleted from Settings is not on that list
 * and is left as it is.
 *
 * It is a hard delete, like `user:delete-manual-account-data`, and goes one
 * step further by taking the accounts too. The user confirmed it could not be
 * undone, and undoing the import afterwards does not bring any of it back.
 */
class ImportWiper
{
    public function __construct(private AccountPurger $purger) {}

    /**
     * @return array{accounts: int, transactions: int}
     */
    public function wipe(Import $import): array
    {
        $accountIds = Account::query()
            ->where('user_id', $import->user_id)
            ->forSpace((string) $import->space_id)
            ->whereNull('banking_connection_id')
            ->pluck('id');

        $transactions = DB::transaction(fn (): int => $this->purger->purge($accountIds));

        return ['accounts' => $accountIds->count(), 'transactions' => $transactions];
    }
}
