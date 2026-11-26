<?php

namespace App\Services\Forecast\Data;

/**
 * One side of a transfer between the user's accounts. Without a counterpart
 * only this leg is in the app (its category says it is a transfer, a saving
 * or an investment), so where the money went, or came from, is unknown.
 *
 * The matching and spike flows of later phases read it; until then only the
 * tests do.
 *
 * @api
 */
final readonly class TransferLeg
{
    public function __construct(
        public string $transactionId,
        public ?string $counterpartTransactionId = null,
        public ?string $counterpartAccountId = null,
    ) {}

    /**
     * Whether the money moved between two of the user's accounts in the app,
     * so the other leg already carries it.
     */
    public function isInternal(): bool
    {
        return $this->counterpartAccountId !== null;
    }
}
