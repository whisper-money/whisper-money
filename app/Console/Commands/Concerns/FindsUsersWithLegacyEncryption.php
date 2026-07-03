<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait FindsUsersWithLegacyEncryption
{
    /**
     * Users who still have at least one client-side encrypted transaction or account
     * (a non-null `*_iv` column is the source of truth, not the stale `accounts.encrypted` flag).
     *
     * @return Builder<User>
     */
    protected function usersWithLegacyEncryption(): Builder
    {
        return User::query()->where(function (Builder $query): void {
            $query->whereHas('transactions', function (Builder $transactions): void {
                $transactions->whereNotNull('description_iv')
                    ->orWhereNotNull('notes_iv');
            })->orWhereHas('accounts', function (Builder $accounts): void {
                $accounts->whereNotNull('name_iv');
            });
        });
    }
}
