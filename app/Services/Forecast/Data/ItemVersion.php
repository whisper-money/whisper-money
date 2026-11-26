<?php

namespace App\Services\Forecast\Data;

use App\Models\ForecastItemVersion;

/**
 * The signed amount of a forecast line from a date on.
 */
final readonly class ItemVersion
{
    public function __construct(
        public string $effectiveFrom,
        public int $amount,
    ) {}

    /**
     * @param  array{effectiveFrom: string, amount: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['effectiveFrom'], $data['amount']);
    }

    public static function fromModel(ForecastItemVersion $version): self
    {
        return new self($version->effective_from->toDateString(), $version->amount);
    }
}
