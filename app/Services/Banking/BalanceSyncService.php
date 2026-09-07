<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Support\Money;
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

        $amount = Money::toMinor(floatval($balance['balance_amount']['amount']), $account->currency_code);
        $date = $balance['reference_date'] ?? now()->toDateString();

        $account->balances()->updateOrCreate(
            ['balance_date' => $date],
            ['balance' => $amount, 'derived' => false],
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

        // Only the rows this walk wrote itself are its to correct. Everything
        // else is somebody's word on what the balance was that day - the bank's,
        // or the user's through the balance editor - and stays put. Rows written
        // before the `derived` column existed carry its false default, so they
        // count as somebody's too: their author is unknowable and overwriting
        // one on a guess is worse than leaving a stale figure alone.
        $protectedDates = $account->balances()
            ->where('derived', false)
            ->pluck('balance_date')
            ->map(fn (mixed $date) => $date instanceof Carbon ? $date->toDateString() : (string) $date)
            ->flip()
            ->all();

        // The reference balance comes from the bank, so the walk may only subtract
        // movements that balance already counted. Rows the bank sent are in, and so
        // are rows the user imported: when the bank's transaction list has a hole
        // but its balance does not, the imported rows are the only thing that
        // explains how the balance moved across those days, and subtracting them
        // makes the walk more correct rather than less. Hand-entered rows stay out
        // - nothing says the bank ever counted one. A row the user moved to another
        // day counts on the day the bank gave it, for the same reason: the bank's
        // balance was reached on the bank's timeline.
        $bankDate = 'COALESCE(source_date, transaction_date)';

        $dailyTotals = $account->transactions()
            ->whereIn('source', [TransactionSource::EnableBanking, TransactionSource::Imported])
            ->whereRaw("{$bankDate} <= ?", [$referenceBalance->balance_date->toDateString()])
            ->selectRaw("{$bankDate} as bank_date, SUM(amount) as daily_total")
            ->groupByRaw($bankDate)
            ->orderByRaw("{$bankDate} desc")
            ->pluck('daily_total', 'bank_date');

        if ($dailyTotals->isEmpty()) {
            return;
        }

        $runningBalance = $referenceBalance->balance;
        $referenceDate = $referenceBalance->balance_date->toDateString();
        $now = now();
        $rows = [];

        foreach ($dailyTotals as $date => $sum) {
            if ($date < $referenceDate && ! isset($protectedDates[$date])) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'account_id' => $account->id,
                    'balance_date' => $date,
                    'balance' => $runningBalance,
                    'derived' => true,
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
