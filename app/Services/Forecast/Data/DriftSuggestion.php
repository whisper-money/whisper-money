<?php

namespace App\Services\Forecast\Data;

/**
 * A line whose last two payments both strayed beyond its tolerance, in the
 * same direction: the forecast can offer to update it ("¿actualizar desde
 * X?"). `amount` is what the latest payment was, signed from the line's
 * account in its minor units; `since` is the occurrence the drift started on.
 *
 * The edit flow of a later phase reads it; until then only the tests do.
 *
 * @api
 */
final readonly class DriftSuggestion
{
    public function __construct(
        public string $itemId,
        public int $amount,
        public string $since,
    ) {}

    /**
     * @return array{itemId: string, amount: int, since: string}
     */
    public function toArray(): array
    {
        return [
            'itemId' => $this->itemId,
            'amount' => $this->amount,
            'since' => $this->since,
        ];
    }
}
