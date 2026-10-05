<?php

namespace App\Services\Imports;

use App\Models\Account;

/**
 * Where each account of the file ended up once the import has resolved them:
 * an account it created, one of the user's own it was mapped onto, the account
 * another file account was merged into, or nowhere at all.
 */
class ImportAccountMap
{
    /**
     * @param  array<string, Account>  $targets  file account key => account its rows go to
     * @param  array<string, true>  $balanceKeys  file account keys whose balances describe their target
     * @param  array<string, true>  $preexistingIds  ids of the targets that existed before the import
     */
    public function __construct(
        private array $targets = [],
        private array $balanceKeys = [],
        private array $preexistingIds = [],
    ) {}

    public function assign(string $key, Account $account, bool $ownsBalances, bool $preexisting): void
    {
        $this->targets[$key] = $account;

        if ($ownsBalances) {
            $this->balanceKeys[$key] = true;
        }

        if ($preexisting) {
            $this->preexistingIds[$account->id] = true;
        }
    }

    /** The account a file account's movements go to, or null when it is not imported. */
    public function targetFor(string $key): ?Account
    {
        return $this->targets[$key] ?? null;
    }

    /**
     * The account a file account's balances go to. A merged account's running
     * balance is its own, not the one it was merged into, so its balances
     * stay out, as do a skipped account's.
     */
    public function balanceTargetFor(string $key): ?Account
    {
        return isset($this->balanceKeys[$key]) ? $this->targetFor($key) : null;
    }

    /** Whether the account held data before the import, so its rows are checked for duplicates. */
    public function isPreexisting(Account $account): bool
    {
        return isset($this->preexistingIds[$account->id]);
    }
}
