<?php

namespace App\Services\Forecast\Data;

/**
 * An occurrence a transaction could pay: how far it is from the transaction
 * in days and in amount, and the deviation the match would store (actual
 * minus expected, signed from the line's own account).
 */
final readonly class MatchCandidate
{
    public function __construct(
        public string $itemId,
        public string $occurrenceDate,
        public int $dateDistance,
        public int $amountDistance,
        public int $deviation,
    ) {}

    /**
     * Closer in date first, then in amount.
     */
    public function compare(self $other): int
    {
        return [$this->dateDistance, $this->amountDistance] <=> [$other->dateDistance, $other->amountDistance];
    }
}
