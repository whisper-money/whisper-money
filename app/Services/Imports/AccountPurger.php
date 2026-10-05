<?php

namespace App\Services\Imports;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Hard-deletes accounts with every movement (trashed ones included) and
 * every balance on them: what "start from scratch" does to the user's manual
 * accounts, and what undoing an import does to the accounts it created.
 */
class AccountPurger
{
    /**
     * @param  Collection<int, string>  $accountIds
     * @return int the movements deleted with them
     */
    public function purge(Collection $accountIds): int
    {
        if ($accountIds->isEmpty()) {
            return 0;
        }

        $transactions = Transaction::withTrashed()->whereIn('account_id', $accountIds)->count();

        Transaction::withTrashed()->whereIn('account_id', $accountIds)->forceDelete();
        AccountBalance::query()->whereIn('account_id', $accountIds)->delete();
        Account::withTrashed()->whereIn('id', $accountIds)->forceDelete();

        return $transactions;
    }
}
