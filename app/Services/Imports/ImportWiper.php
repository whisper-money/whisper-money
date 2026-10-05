<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * "Start from scratch": deletes the user's manual accounts in the import's
 * space, with every movement and balance on them, before the import writes
 * anything. Connected accounts, categories, labels and rules stay.
 *
 * It is a hard delete, like `user:delete-manual-account-data`, and goes one
 * step further by taking the accounts too. The user confirmed it could not be
 * undone, and undoing the import afterwards does not bring any of it back.
 */
class ImportWiper
{
    /**
     * @return array{accounts: int, transactions: int}
     */
    public function wipe(Import $import): array
    {
        $accountIds = Account::withTrashed()
            ->where('user_id', $import->user_id)
            ->forSpace((string) $import->space_id)
            ->whereNull('banking_connection_id')
            ->pluck('id');

        if ($accountIds->isEmpty()) {
            return ['accounts' => 0, 'transactions' => 0];
        }

        return DB::transaction(function () use ($accountIds): array {
            $transactions = Transaction::withTrashed()->whereIn('account_id', $accountIds)->count();

            Transaction::withTrashed()->whereIn('account_id', $accountIds)->forceDelete();
            AccountBalance::query()->whereIn('account_id', $accountIds)->delete();
            Account::withTrashed()->whereIn('id', $accountIds)->forceDelete();

            return ['accounts' => $accountIds->count(), 'transactions' => $transactions];
        });
    }
}
