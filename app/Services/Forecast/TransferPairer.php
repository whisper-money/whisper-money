<?php

namespace App\Services\Forecast;

use App\Enums\CategoryType;
use App\Models\Transaction;
use App\Services\Forecast\Data\TransferLeg;
use Illuminate\Support\Collection;

/**
 * Finds the transfers among a user's transactions. Two legs pair when money
 * leaves one of their accounts and the same amount, in the same currency,
 * lands in another within two days; the closest in time wins. A leg whose
 * category is a transfer, a saving or an investment is a transfer too, even
 * when its other side is not in the app: its counterpart is then unknown.
 */
class TransferPairer
{
    private const MAX_DAYS_APART = 2;

    private const TRANSFER_TYPES = [CategoryType::Transfer, CategoryType::Savings, CategoryType::Investment];

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, TransferLeg> Keyed by transaction id.
     */
    public function pair(Collection $transactions): array
    {
        $legs = $this->pairOppositeLegs($transactions);

        foreach ($transactions as $transaction) {
            if (! isset($legs[$transaction->id]) && $this->hasTransferCategory($transaction)) {
                $legs[$transaction->id] = new TransferLeg($transaction->id);
            }
        }

        return $legs;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, TransferLeg>
     */
    private function pairOppositeLegs(Collection $transactions): array
    {
        $incomingByAmount = $transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->amount > 0)
            ->groupBy(fn (Transaction $transaction): string => $this->amountKey($transaction->currency_code, $transaction->amount));

        $legs = [];

        foreach ($transactions as $outgoing) {
            if ($outgoing->amount >= 0) {
                continue;
            }

            $incoming = $this->closestCounterpart($outgoing, $incomingByAmount->get($this->amountKey($outgoing->currency_code, -$outgoing->amount)), $legs);

            if ($incoming !== null) {
                $legs[$outgoing->id] = new TransferLeg($outgoing->id, $incoming->id, $incoming->account_id);
                $legs[$incoming->id] = new TransferLeg($incoming->id, $outgoing->id, $outgoing->account_id);
            }
        }

        return $legs;
    }

    /**
     * @param  Collection<int, Transaction>|null  $candidates
     * @param  array<string, TransferLeg>  $legs  The legs already paired, which cannot pair again.
     */
    private function closestCounterpart(Transaction $outgoing, ?Collection $candidates, array $legs): ?Transaction
    {
        $closest = null;
        $closestDays = self::MAX_DAYS_APART + 1;

        foreach ($candidates ?? [] as $incoming) {
            $days = (int) abs($incoming->transaction_date->diffInDays($outgoing->transaction_date));

            if ($days < $closestDays && $incoming->account_id !== $outgoing->account_id && ! isset($legs[$incoming->id])) {
                $closest = $incoming;
                $closestDays = $days;
            }
        }

        return $closest;
    }

    private function hasTransferCategory(Transaction $transaction): bool
    {
        return in_array($transaction->category?->type, self::TRANSFER_TYPES, true);
    }

    private function amountKey(string $currencyCode, int $amount): string
    {
        return strtoupper($currencyCode).'|'.$amount;
    }
}
