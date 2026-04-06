<?php

namespace App\Services\Banking;

use App\Models\Account;
use App\Services\CurrencyConversionService;
use Illuminate\Support\Facades\Log;

class IndexaCapitalBalanceSyncService
{
    public function __construct(private CurrencyConversionService $currencyConverter) {}

    /**
     * Sync portfolio balances for an Indexa Capital account.
     * On first sync, stores all available daily historical balances.
     * On subsequent syncs, only processes entries since the last recorded balance.
     */
    public function sync(Account $account, IndexaCapitalClient $client, bool $isFirstSync = true): void
    {
        if (! $account->external_account_id) {
            return;
        }

        $performance = $client->getPerformance($account->external_account_id);
        $portfolios = $performance['portfolios'] ?? [];
        $netAmounts = $performance['net_amounts'] ?? [];

        if (empty($portfolios)) {
            Log::warning('No portfolio data from Indexa Capital', [
                'account_id' => $account->id,
                'external_account_id' => $account->external_account_id,
            ]);

            return;
        }

        $sinceDate = null;

        if (! $isFirstSync) {
            $lastBalanceDate = $account->balances()->max('balance_date');

            if ($lastBalanceDate) {
                $sinceDate = $lastBalanceDate;
            }
        }

        $accountCurrency = strtoupper($account->currency_code);
        $userCurrency = strtoupper($account->user->currency_code);

        $count = 0;

        foreach ($portfolios as $entry) {
            $date = $entry['date'] ?? null;
            $value = $entry['total_amount'] ?? null;

            if ($date === null || $value === null) {
                continue;
            }

            if ($sinceDate !== null && $date < $sinceDate) {
                continue;
            }

            $balanceCents = (int) round(floatval($value) * 100);
            $investedAmountCents = $this->calculateInvestedAmount($entry, $netAmounts, $accountCurrency, $userCurrency, $date);

            $account->balances()->updateOrCreate(
                ['balance_date' => $date],
                [
                    'balance' => $balanceCents,
                    ...($investedAmountCents !== null ? ['invested_amount' => $investedAmountCents] : []),
                ],
            );

            $count++;
        }

        Log::info('Synced Indexa Capital balances', [
            'account_id' => $account->id,
            'days_synced' => $count,
            ...($sinceDate ? ['since_date' => $sinceDate] : []),
        ]);
    }

    /**
     * Calculate invested amount from the net_amounts data, converted to the user's currency.
     *
     * Uses net_amounts (cumulative net inflows keyed by YYYYMMDD) which represents
     * the actual money invested (inflows - outflows - tax_outflows), matching
     * what Indexa Capital shows as "investment" on their dashboard.
     *
     * Falls back to total_amount - return if net_amounts is unavailable.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, float>  $netAmounts
     */
    private function calculateInvestedAmount(array $entry, array $netAmounts, string $accountCurrency, string $userCurrency, string $date): ?int
    {
        $entryDate = $entry['date'] ?? null;
        $amount = null;

        if ($entryDate !== null && ! empty($netAmounts)) {
            $dateKey = str_replace('-', '', $entryDate);

            if (isset($netAmounts[$dateKey])) {
                $amount = floatval($netAmounts[$dateKey]);
            }
        }

        if ($amount === null) {
            $totalAmount = $entry['total_amount'] ?? null;
            $returnValue = $entry['return'] ?? null;

            if ($totalAmount !== null && $returnValue !== null) {
                $amount = floatval($totalAmount) - floatval($returnValue);
            }
        }

        if ($amount === null) {
            return null;
        }

        if (strcasecmp($accountCurrency, $userCurrency) !== 0) {
            $amount = $this->currencyConverter->convert($accountCurrency, $userCurrency, $amount, $date);
        }

        return (int) round($amount * 100);
    }
}
