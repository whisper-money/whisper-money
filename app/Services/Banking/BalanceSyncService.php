<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Models\Account;
use App\Models\AccountBalance;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $balances = $result['balances'];

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
     * Calculate historical daily balances by working backwards from the latest known balance.
     * Uses transaction amounts to derive end-of-day balances for dates without direct balance data.
     */
    public function calculateHistoricalBalances(Account $account): void
    {
        $referenceBalance = $account->balances()
            ->orderByDesc('balance_date')
            ->first();

        if (! $referenceBalance) {
            return;
        }

        $existingDates = $account->balances()
            ->pluck('balance_date')
            ->map(fn (mixed $date) => $date instanceof Carbon ? $date->toDateString() : (string) $date)
            ->flip()
            ->all();

        $dailyTotals = $account->transactions()
            ->where('transaction_date', '<=', $referenceBalance->balance_date)
            ->selectRaw('transaction_date, SUM(amount) as daily_total')
            ->groupBy('transaction_date')
            ->orderByDesc('transaction_date')
            ->pluck('daily_total', 'transaction_date');

        if ($dailyTotals->isEmpty()) {
            return;
        }

        $runningBalance = $referenceBalance->balance;
        $referenceDate = $referenceBalance->balance_date->toDateString();
        $now = now();
        $rows = [];

        foreach ($dailyTotals as $date => $sum) {
            if ($date < $referenceDate && ! isset($existingDates[$date])) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'account_id' => $account->id,
                    'balance_date' => $date,
                    'balance' => $runningBalance,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $runningBalance -= (int) $sum;
        }

        if ($rows !== []) {
            AccountBalance::upsert($rows, ['account_id', 'balance_date'], ['balance', 'updated_at']);
        }

        Log::info('Calculated historical balances', [
            'account_id' => $account->id,
            'reference_date' => $referenceDate,
            'reference_balance' => $referenceBalance->balance,
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
