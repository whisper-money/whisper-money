<?php

namespace App\Services\Forecast\Data;

/**
 * A charge far above what its parent category usually sees, for the user to
 * say whether it was a one-off or will repeat. Amounts are positive spend in
 * the transaction's minor units.
 *
 * The spike flow of a later phase reads it; until then only the tests do.
 *
 * @api
 */
final readonly class SpikeCandidate
{
    public function __construct(
        public string $transactionId,
        public string $accountId,
        public string $categoryId,
        public string $currencyCode,
        public string $transactionDate,
        public int $amount,
        public int $categoryP95,
        public int $categoryMonthlyMedian,
    ) {}
}
