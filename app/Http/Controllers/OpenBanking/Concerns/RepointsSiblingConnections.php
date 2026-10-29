<?php

namespace App\Http\Controllers\OpenBanking\Concerns;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Enums\BankingSyncTrigger;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use Illuminate\Database\Eloquent\Collection;

trait RepointsSiblingConnections
{
    /**
     * Point the user's other connections to the same bank at a session that also
     * covers their accounts.
     *
     * Some banks keep a single valid session per user and TPP: authorizing one
     * connection revokes the session every other connection to that bank holds,
     * and the next new session lists all of the user's accounts there. With each
     * connection storing its own `session_id`, two connections to such a bank
     * revoke each other on every reconnect and never both work.
     *
     * A sibling is only touched when IBAN evidence says the session covers it:
     * every one of its accounts has to be matched by IBAN, never by position, so
     * a connection to a bank whose sessions do not collide never loses an account
     * that was still syncing.
     *
     * @param  array<string, mixed>  $sessionData
     */
    private function repointSiblingConnections(BankingConnection $connection, array $sessionData): void
    {
        $uidsByIban = $this->sessionUidsByIban($sessionData['accounts'] ?? []);

        if ($uidsByIban === []) {
            return;
        }

        foreach ($this->siblingConnections($connection) as $sibling) {
            $this->repointSibling($sibling, $sessionData, $uidsByIban);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<string, string>
     */
    private function sessionUidsByIban(array $accounts): array
    {
        return collect($accounts)
            ->filter(fn (array $account): bool => ! empty($account['uid']) && ! empty($account['account_id']['iban']))
            ->mapWithKeys(fn (array $account): array => [$account['account_id']['iban'] => $account['uid']])
            ->all();
    }

    /**
     * @return Collection<int, BankingConnection>
     */
    private function siblingConnections(BankingConnection $connection): Collection
    {
        return BankingConnection::query()
            ->where('user_id', $connection->user_id)
            ->where('provider', BankingProvider::EnableBanking)
            ->where('aspsp_name', $connection->aspsp_name)
            ->where('aspsp_country', $connection->aspsp_country)
            ->whereKeyNot($connection->getKey())
            ->with('accounts')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $sessionData
     * @param  array<string, string>  $uidsByIban
     */
    private function repointSibling(BankingConnection $sibling, array $sessionData, array $uidsByIban): void
    {
        $accounts = $sibling->accounts;

        $coveredBySession = $accounts->isNotEmpty() && $accounts->every(
            fn (Account $account): bool => $account->iban !== null && isset($uidsByIban[$account->iban]),
        );

        if (! $coveredBySession) {
            return;
        }

        foreach ($accounts as $account) {
            $account->update(['external_account_id' => $uidsByIban[$account->iban]]);
        }

        $sibling->update([
            'session_id' => $sessionData['session_id'],
            'status' => BankingConnectionStatus::Active,
            'valid_until' => $sessionData['access']['valid_until'] ?? null,
            'error_message' => null,
            'consecutive_sync_failures' => 0,
        ]);

        SyncBankingConnectionJob::dispatch($sibling, trigger: BankingSyncTrigger::Reconnect);
    }
}
