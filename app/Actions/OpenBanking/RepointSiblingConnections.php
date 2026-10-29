<?php

namespace App\Actions\OpenBanking;

use App\Enums\BankingProvider;
use App\Enums\BankingSyncTrigger;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Point the user's other connections to the same bank at a session that also
 * covers their accounts.
 *
 * Some banks keep a single valid session per user and TPP: authorizing one
 * connection revokes the session every other connection to that bank holds,
 * and the new session lists all of the user's accounts there. With each
 * connection storing its own `session_id`, two connections to such a bank
 * revoked each other on every reconnect and never both worked.
 *
 * A sibling is only touched when IBAN evidence says the session covers it:
 * every one of its accounts has to be matched by IBAN, never by position, so
 * a connection to a bank whose sessions do not collide never loses an account
 * that was still syncing.
 */
class RepointSiblingConnections
{
    /**
     * @param  array<string, mixed>  $sessionData
     * @return list<string> The session account uids now held by a sibling.
     */
    public function handle(BankingConnection $connection, array $sessionData): array
    {
        $uidsByIban = $this->uidsByUniqueIban($sessionData['accounts'] ?? []);

        if ($uidsByIban === []) {
            return [];
        }

        $claimedUids = [];

        foreach ($this->siblings($connection) as $sibling) {
            if ($this->coveredBySession($sibling, $uidsByIban)) {
                $claimedUids = [...$claimedUids, ...$this->repoint($sibling, $sessionData, $uidsByIban)];
            }
        }

        return $claimedUids;
    }

    /**
     * An IBAN the session lists twice (one per currency, say) cannot tell its
     * accounts apart, so it is no evidence for either of them.
     *
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<string, string>
     */
    private function uidsByUniqueIban(array $accounts): array
    {
        return collect($accounts)
            ->filter(fn (array $account): bool => ! empty($account['uid']) && ! empty($account['account_id']['iban']))
            ->groupBy(fn (array $account): string => $account['account_id']['iban'])
            ->filter(fn ($group): bool => $group->count() === 1)
            ->map(fn ($group): string => $group->first()['uid'])
            ->all();
    }

    /**
     * @return Collection<int, BankingConnection>
     */
    private function siblings(BankingConnection $connection): Collection
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
     * @param  array<string, string>  $uidsByIban
     */
    private function coveredBySession(BankingConnection $sibling, array $uidsByIban): bool
    {
        return $sibling->accounts->isNotEmpty() && $sibling->accounts->every(
            fn (Account $account): bool => $account->iban !== null && isset($uidsByIban[$account->iban]),
        );
    }

    /**
     * @param  array<string, mixed>  $sessionData
     * @param  array<string, string>  $uidsByIban
     * @return list<string>
     */
    private function repoint(BankingConnection $sibling, array $sessionData, array $uidsByIban): array
    {
        $uids = DB::transaction(function () use ($sibling, $sessionData, $uidsByIban): array {
            foreach ($sibling->accounts as $account) {
                $account->update(['external_account_id' => $uidsByIban[$account->iban]]);
            }

            $sibling->activateWithSession($sessionData);

            return $sibling->accounts->pluck('external_account_id')->all();
        });

        SyncBankingConnectionJob::dispatch($sibling, trigger: BankingSyncTrigger::Reconnect);

        return $uids;
    }
}
