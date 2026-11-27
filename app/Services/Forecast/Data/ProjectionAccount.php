<?php

namespace App\Services\Forecast\Data;

/**
 * An account the forecast projects: the balance it starts from (at the start
 * of the projection's first day), its yearly growth rate in basis points for
 * an investment or property, and the balance its owner wants to keep.
 *
 * An account with only a balance history may carry the trend of its last
 * months instead (see BalanceTrend): `trendSlope` is added on the close of
 * each whole month and `trendBand` widens each whole month's band. A trend
 * replaces the yearly rate, since the balances it was drawn from already
 * include the returns.
 */
final readonly class ProjectionAccount
{
    public function __construct(
        public string $id,
        public int $openingBalance,
        public ?int $annualRateBps = null,
        public ?int $minimumBalance = null,
        public ?int $trendSlope = null,
        public ?int $trendBand = null,
    ) {}

    /**
     * @param  array{id: string, openingBalance: int, annualRateBps?: int|null, minimumBalance?: int|null, trendSlope?: int|null, trendBand?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['openingBalance'],
            $data['annualRateBps'] ?? null,
            $data['minimumBalance'] ?? null,
            $data['trendSlope'] ?? null,
            $data['trendBand'] ?? null,
        );
    }

    /**
     * @return array{id: string, openingBalance: int, annualRateBps: int|null, minimumBalance: int|null, trendSlope: int|null, trendBand: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'openingBalance' => $this->openingBalance,
            'annualRateBps' => $this->annualRateBps,
            'minimumBalance' => $this->minimumBalance,
            'trendSlope' => $this->trendSlope,
            'trendBand' => $this->trendBand,
        ];
    }
}
