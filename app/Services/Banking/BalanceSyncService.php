<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Models\Account;
use Illuminate\Support\Facades\Log;

class BalanceSyncService
{
    /** Balance types in preference order */
    private const PREFERRED_BALANCE_TYPES = ['CLBD', 'ITAV', 'ITBD', 'OPBD', 'XPCD'];

    public function __construct(
        private BankingProviderInterface $provider,
    ) {}

    /**
     * Sync balances for a connected account.
     */
    public function sync(Account $account): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $result = $this->provider->getBalances($account->external_account_id);
        $balances = $result['balances'] ?? [];

        if (empty($balances)) {
            return;
        }

        $balance = $this->selectPreferredBalance($balances);

        if (! $balance) {
            return;
        }

        $amount = (int) round(floatval($balance['balance_amount']['amount']) * 100);
        $date = $balance['reference_date'] ?? now()->toDateString();

        $account->balances()->updateOrCreate(
            ['balance_date' => $date],
            ['balance' => $amount],
        );

        Log::info('Synced balance', [
            'account_id' => $account->id,
            'balance' => $amount,
            'date' => $date,
            'type' => $balance['balance_type'],
        ]);
    }

    /**
     * Select the most useful balance from the list based on preferred types.
     */
    private function selectPreferredBalance(array $balances): ?array
    {
        foreach (self::PREFERRED_BALANCE_TYPES as $type) {
            foreach ($balances as $balance) {
                if (($balance['balance_type'] ?? null) === $type) {
                    return $balance;
                }
            }
        }

        return $balances[0] ?? null;
    }
}
