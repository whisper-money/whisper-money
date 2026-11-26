<?php

namespace App\Services\Forecast\Data;

/**
 * An account the forecast projects: the balance it starts from (at the start
 * of the projection's first day), its yearly growth rate in basis points for
 * an investment or property, and the balance its owner wants to keep.
 */
final readonly class ProjectionAccount
{
    public function __construct(
        public string $id,
        public int $openingBalance,
        public ?int $annualRateBps = null,
        public ?int $minimumBalance = null,
    ) {}

    /**
     * @param  array{id: string, openingBalance: int, annualRateBps?: int|null, minimumBalance?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['openingBalance'],
            $data['annualRateBps'] ?? null,
            $data['minimumBalance'] ?? null,
        );
    }
}
