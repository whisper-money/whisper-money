<?php

namespace App\Services\Forecast;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Transaction;
use App\Services\Forecast\Data\TransferLeg;
use Illuminate\Support\Collection;

/**
 * Finds the transfers among a user's transactions. Two legs pair when money
 * leaves one of their accounts and the same amount, in the same currency,
 * lands in another within two days (five when one is a credit card, whose
 * payments settle slower); the closest in time wins. A leg filed as an
 * expense or an income is what it says, so a rent and a salary of the same
 * round amount never pair. Legs in different currencies are not paired:
 * nothing here converts them. A leg whose
 * category is a transfer, a saving or an investment is a transfer too, even
 * when its other side is not in the app: its counterpart is then unknown.
 */
class TransferPairer
{
    private const MAX_DAYS_APART = 2;

    private const MAX_DAYS_APART_FOR_CARDS = 5;

    private const SPEND_OR_INCOME_TYPES = [CategoryType::Expense, CategoryType::Income];

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
            ->filter(fn (Transaction $transaction): bool => $transaction->amount > 0 && $this->canPair($transaction))
            ->groupBy(fn (Transaction $transaction): string => $this->amountKey($transaction->currency_code, $transaction->amount));

        $legs = [];

        foreach ($transactions as $outgoing) {
            if ($outgoing->amount >= 0 || ! $this->canPair($outgoing)) {
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
        $closestDays = PHP_INT_MAX;

        foreach ($candidates ?? [] as $incoming) {
            $days = (int) $incoming->transaction_date->diffInDays($outgoing->transaction_date, true);

            if ($days < $closestDays && $days <= $this->maxDaysApart($outgoing, $incoming) && $incoming->account_id !== $outgoing->account_id && ! isset($legs[$incoming->id])) {
                $closest = $incoming;
                $closestDays = $days;
            }
        }

        return $closest;
    }

    private function canPair(Transaction $transaction): bool
    {
        return ! in_array($transaction->category?->type, self::SPEND_OR_INCOME_TYPES, true);
    }

    private function maxDaysApart(Transaction $outgoing, Transaction $incoming): int
    {
        $card = AccountType::CreditCard;

        return $outgoing->account?->type === $card || $incoming->account?->type === $card ? self::MAX_DAYS_APART_FOR_CARDS : self::MAX_DAYS_APART;
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
