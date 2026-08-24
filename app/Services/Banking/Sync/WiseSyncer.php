<?php

namespace App\Services\Banking\Sync;

use App\Exceptions\Banking\TransientBankingProviderException;
use App\Models\BankingConnection;
use App\Services\Banking\WiseBalanceSyncService;
use App\Services\Banking\WiseClient;
use App\Services\Banking\WiseTransactionSyncService;
use Illuminate\Support\Facades\Log;

class WiseSyncer extends AbstractBankingConnectionSyncer
{
    /**
     * Wall-clock budget for the whole connection, sized against
     * SyncBankingConnectionJob's 120s timeout.
     *
     * A safety net rather than a policy: with the cursor fixed a normal wallet
     * finishes in a couple of requests. It exists so that a genuinely long history
     * - or a provider answering slowly - stops the run short instead of getting the
     * job killed, which is what used to leave last_synced_at unset and every cycle
     * restarting the same first sync.
     *
     * Per connection, not per wallet: Wise creates one account per currency per
     * profile, so a budget each would multiply straight past the job's timeout.
     * Worst case is this budget plus one in-flight request (WiseClient caps those),
     * which leaves headroom inside 120s.
     */
    private const int SYNC_BUDGET_SECONDS = 90;

    public function __construct(
        private WiseTransactionSyncService $transactionSync,
        private WiseBalanceSyncService $balanceSync,
    ) {}

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $dateFrom = $isFirstSync
            ? now()->subYear()->toDateString()
            : ($connection->last_synced_at?->toDateString() ?? now()->subMonth()->toDateString());
        $dateTo = now()->toDateString();

        $client = new WiseClient($connection->api_token);
        $deadline = now()->addSeconds(self::SYNC_BUDGET_SECONDS);

        $connection->load('accounts');

        $transactionsPerAccount = [];
        $balancesFailed = 0;
        $walletsSkipped = 0;

        foreach ($connection->accounts as $account) {
            if (now()->gte($deadline)) {
                // Better to leave a wallet for the next cycle than to have the job
                // killed, which would discard the sync timestamp for all of them.
                $walletsSkipped++;

                continue;
            }

            // Balance first, and its failure must not cost the transactions: it is
            // one request against a walk that can span many, so ordering it second
            // meant a wallet whose history could not be walked was missing from net
            // worth entirely.
            try {
                $this->balanceSync->sync($account, $client);
            } catch (TransientBankingProviderException $e) {
                $balancesFailed++;

                Log::warning('Wise balance sync failed, continuing', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $transactionsPerAccount[$account->name] = $this->transactionSync->sync(
                $account,
                $client,
                $dateFrom,
                $dateTo,
                $deadline,
            );
        }

        return [
            'transactions_synced' => array_sum($transactionsPerAccount),
            'transactions_per_account' => $transactionsPerAccount,
            'balances_failed' => $balancesFailed,
            'wallets_skipped_on_budget' => $walletsSkipped,
        ];
    }
}
