<?php

namespace App\Services\Forecast;

use App\Models\User;
use App\Services\ExchangeRateService;

/**
 * Whether too much of a user's money has no category for the forecast to
 * be any good, which the review says before it shows the estimates: more
 * than a fifth of what moved on the accounts reviewed over the last three
 * complete months, in or out, internal transfers between the user's own
 * accounts left out (the forecast leaves them out of the categories too).
 * Amounts are the user's share of each account, converted to the user's
 * currency at today's rate; an account whose currency has no rate today is
 * left out of both sides of the share.
 */
class ForecastUncategorizedShare
{
    private const MONTHS = 3;

    /**
     * The share of uncategorized money above which the review warns, as a
     * fraction: 1 / 5 is 20 %.
     */
    private const THRESHOLD_DENOMINATOR = 5;

    public function __construct(
        private readonly ForecastHistory $history,
        private readonly TransferPairer $pairer,
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * Null when the share is 20 % or less.
     *
     * @param  list<string>  $accountIds  The accounts reviewed.
     * @return array{count: int, monthlyAmount: int, currencyCode: string, from: string, to: string}|null
     */
    public function for(User $user, string $spaceId, array $accountIds, string $today): ?array
    {
        $from = ForecastCalendar::monthStart($today, -self::MONTHS);
        $to = ForecastCalendar::previousDay(ForecastCalendar::monthStart($today));
        // Transfers pair across every account of the space, as the estimates do.
        $transactions = $this->history->transactions($user, $spaceId, $from, $to);
        $legs = $this->pairer->pair($transactions);
        $reviewed = array_flip($accountIds);
        $byCurrency = [];

        foreach ($transactions as $transaction) {
            if (! isset($reviewed[$transaction->account_id]) || ($legs[$transaction->id] ?? null)?->isInternal()) {
                continue;
            }

            $currency = (string) $transaction->account->currency_code;
            $amount = abs($transaction->account->shareOfAmount($transaction->amount));

            if ($amount === 0) {
                continue;
            }

            $byCurrency[$currency] ??= ['moved' => 0, 'uncategorized' => 0, 'count' => 0];
            $byCurrency[$currency]['moved'] += $amount;

            if ($transaction->category_id === null) {
                $byCurrency[$currency]['uncategorized'] += $amount;
                $byCurrency[$currency]['count']++;
            }
        }

        ['moved' => $moved, 'uncategorized' => $uncategorized, 'count' => $count] = $this->inUserCurrency($user, $byCurrency, $today);

        if ($uncategorized * self::THRESHOLD_DENOMINATOR <= $moved) {
            return null;
        }

        return [
            'count' => $count,
            'monthlyAmount' => intdiv($uncategorized, self::MONTHS),
            'currencyCode' => (string) $user->currency_code,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * The sums of each currency added up in the user's.
     *
     * @param  array<string, array{moved: int, uncategorized: int, count: int}>  $byCurrency
     * @return array{moved: int, uncategorized: int, count: int}
     */
    private function inUserCurrency(User $user, array $byCurrency, string $today): array
    {
        $total = ['moved' => 0, 'uncategorized' => 0, 'count' => 0];

        foreach ($byCurrency as $currency => $sums) {
            $moved = $this->rates->convertOrNull($currency, (string) $user->currency_code, $sums['moved'], $today);

            if ($moved === null) {
                continue;
            }

            $total['moved'] += $moved;
            $total['uncategorized'] += (int) $this->rates->convertOrNull($currency, (string) $user->currency_code, $sums['uncategorized'], $today);
            $total['count'] += $sums['count'];
        }

        return $total;
    }
}
