<?php

namespace App\Services\Forecast;

use App\Models\Transaction;
use App\Services\Ai\DescriptionTokenizer;

/**
 * The identity of a counterparty across its charges: the creditor name when
 * the bank gives one, the description otherwise, cut down to the words that
 * do not change from one charge to the next (see
 * DescriptionTokenizer::stableTokens()). An empty fingerprint means the
 * transaction says nothing stable about who it is with, and callers fall back
 * to its amount and day instead.
 */
final class DescriptionFingerprint
{
    public function __construct(private readonly DescriptionTokenizer $tokenizer) {}

    public function of(?string $description, ?string $creditorName = null): string
    {
        $fromCreditor = $this->normalized((string) $creditorName);

        return $fromCreditor !== '' ? $fromCreditor : $this->normalized((string) $description);
    }

    public function ofTransaction(Transaction $transaction): string
    {
        return $this->of($transaction->description, $transaction->creditor_name);
    }

    /**
     * A history grouped the way the detectors read it: by account, direction
     * and fingerprint, each group with its fingerprint and its charges in the
     * order given. Zero amounts move nothing and are left out.
     *
     * @param  iterable<int, Transaction>  $transactions
     * @return list<array{0: string, 1: list<Transaction>}>
     */
    public function groups(iterable $transactions): array
    {
        $groups = [];

        foreach ($transactions as $transaction) {
            if ($transaction->amount === 0) {
                continue;
            }

            $fingerprint = $this->ofTransaction($transaction);
            $key = $transaction->account_id.'|'.($transaction->amount < 0 ? 'out' : 'in').'|'.$fingerprint;
            $groups[$key] ??= [$fingerprint, []];
            $groups[$key][1][] = $transaction;
        }

        return array_values($groups);
    }

    private function normalized(string $value): string
    {
        return implode(' ', $this->tokenizer->stableTokens($value));
    }
}
