<?php

namespace App\Services\Banking;

use App\Models\Account;
use Illuminate\Support\Facades\Log;

class InteractiveBrokersBalanceSyncService
{
    /**
     * Sync NAV balances for one IB account from an already-fetched statement.
     *
     * On first sync every daily NAV row is stored (backfill); afterwards only
     * rows newer than the last recorded balance. invested_amount (and therefore
     * profit) is only set on the latest date, where cost basis is available.
     *
     * @param  array<string, array{account_id: string, currency: string, navByDate: array<string, float>, investedAmount: float|null}>  $accounts
     */
    public function sync(Account $account, array $accounts, bool $isFirstSync = true): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $data = $accounts[$account->external_account_id] ?? null;

        if ($data === null || empty($data['navByDate'])) {
            Log::warning('No Interactive Brokers data for account', [
                'account_id' => $account->id,
                'external_account_id' => $account->external_account_id,
            ]);

            return;
        }

        $navByDate = $data['navByDate'];
        ksort($navByDate);
        $latestDate = array_key_last($navByDate);

        $sinceDate = null;

        if (! $isFirstSync) {
            $lastBalanceDate = $account->balances()->max('balance_date');

            if ($lastBalanceDate) {
                $sinceDate = $lastBalanceDate;
            }
        }

        $count = 0;

        foreach ($navByDate as $date => $nav) {
            if ($sinceDate !== null && $date < $sinceDate) {
                continue;
            }

            $attributes = ['balance' => (int) round($nav * 100)];

            if ($date === $latestDate && $data['investedAmount'] !== null) {
                $attributes['invested_amount'] = (int) round($data['investedAmount'] * 100);
            }

            $account->balances()->updateOrCreate(['balance_date' => $date], $attributes);

            $count++;
        }

        Log::info('Synced Interactive Brokers balances', [
            'account_id' => $account->id,
            'days_synced' => $count,
            ...($sinceDate ? ['since_date' => $sinceDate] : []),
        ]);
    }
}
