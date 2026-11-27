<?php

namespace App\Services\Forecast;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the strong warning offers: "Planificar traspaso de 500 € desde
 * Ahorro". The smallest round amount (whole hundreds) that lifts the lowest
 * balance back above the threshold, moved at the start of the first month
 * that falls short, from the other account with the most room to spare
 * after it. Only an account in the same currency that is not a card or a
 * loan can lend, and only if the amount leaves it above its own threshold.
 * Nothing is stored: it prefills the transfer the user then confirms.
 */
class ForecastTransferSuggester
{
    private const ROUND_TO_MAJOR_UNITS = 100;

    /**
     * @param  list<array<string, int|string|null>>  $months  The selected account's visible months.
     * @param  Collection<int, Account>  $accounts  Every account the forecast includes.
     * @param  array<string, array{months: list<array<string, int|string|null>>}>  $projected  Projections by account id.
     * @param  array<string, int|null>  $thresholds  By account id.
     * @return array{fromAccountId: string, amount: int, date: string, month: string}|null
     */
    public function suggest(Account $account, array $months, Collection $accounts, array $projected, array $thresholds, string $today): ?array
    {
        $threshold = $thresholds[$account->id];
        $short = array_values(array_filter($months, fn (array $month): bool => $threshold !== null && $month['min'] < $threshold));

        if ($short === []) {
            return null;
        }

        $first = (string) $short[0]['month'];
        $lowest = min(array_column($short, 'min'));
        $amount = $this->roundedUp($threshold - $lowest, $account->currency_code);
        $lender = $this->lender($account, $accounts, $projected, $thresholds, [$first, (string) end($months)['month']], $amount);

        return $lender === null ? null : [
            'fromAccountId' => $lender->id,
            'amount' => $amount,
            'date' => max($today, $first.'-01'),
            'month' => $first,
        ];
    }

    private function roundedUp(int $deficit, string $currency): int
    {
        $unit = Money::toMinor(self::ROUND_TO_MAJOR_UNITS, $currency);

        return (int) (ceil($deficit / $unit) * $unit);
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<string, array{months: list<array<string, int|string|null>>}>  $projected
     * @param  array<string, int|null>  $thresholds
     * @param  array{0: string, 1: string}  $range  The `Y-m` months the money is missing from, and the last one shown.
     */
    private function lender(Account $account, Collection $accounts, array $projected, array $thresholds, array $range, int $amount): ?Account
    {
        return $accounts
            ->filter(fn (Account $other): bool => $other->id !== $account->id
                && $other->currency_code === $account->currency_code
                && ! in_array($other->type, [AccountType::CreditCard, AccountType::Loan], true))
            ->map(fn (Account $other): array => [$other, $this->headroom($projected[$other->id]['months'], $range, $thresholds[$other->id] ?? 0)])
            ->filter(fn (array $pair): bool => $pair[1] >= $amount)
            ->sortByDesc(fn (array $pair): int => $pair[1])
            ->first()[0] ?? null;
    }

    /**
     * How much an account can give over the months of the range and stay
     * above its threshold.
     *
     * @param  list<array<string, int|string|null>>  $months
     * @param  array{0: string, 1: string}  $range
     */
    private function headroom(array $months, array $range, int $threshold): int
    {
        $mins = array_column(array_filter($months, fn (array $month): bool => $month['month'] >= $range[0] && $month['month'] <= $range[1]), 'min');

        return $mins === [] ? 0 : min($mins) - $threshold;
    }
}
