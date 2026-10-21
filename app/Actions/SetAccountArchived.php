<?php

namespace App\Actions;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Models\Account;
use App\Models\BankingConnection;
use Illuminate\Support\Facades\DB;

/**
 * Archiving records the day it happened so the account stops counting from
 * then on without touching the history it already has; unarchiving clears
 * the date and the account goes back to counting.
 *
 * A connected account also stops syncing: sync runs off the connection's
 * accounts, so detaching it is what ends the new transactions and balances
 * the dialog warns about. Reconnecting means going through the bank again,
 * which is why unarchiving cannot undo this half.
 */
class SetAccountArchived
{
    public function __construct(private DisconnectBankingConnection $disconnectBankingConnection) {}

    public function handle(Account $account, bool $archived): void
    {
        $orphanedConnection = DB::transaction(function () use ($account, $archived): ?BankingConnection {
            $account->update([
                'archived_at' => $archived ? now() : null,
            ]);

            return $archived ? $this->detachFromBank($account) : null;
        });

        // Revoking talks to the provider, so it stays outside the transaction:
        // a slow bank would otherwise hold the connection row locked.
        if ($orphanedConnection) {
            $this->disconnectBankingConnection->handle($orphanedConnection);
        }
    }

    /**
     * Returns the connection only when this was the last account hanging off it,
     * so it can be revoked — the other accounts of the same bank must keep
     * syncing. The row is locked because two accounts archived at once would
     * otherwise both see it as empty and revoke it twice.
     */
    private function detachFromBank(Account $account): ?BankingConnection
    {
        if (! $account->isConnected()) {
            return null;
        }

        $connection = $account->bankingConnection()->lockForUpdate()->first();

        $account->update([
            'banking_connection_id' => null,
            'external_account_id' => null,
        ]);

        return $connection && $connection->accounts()->doesntExist() ? $connection : null;
    }
}
