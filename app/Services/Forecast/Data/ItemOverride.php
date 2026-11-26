<?php

namespace App\Services\Forecast\Data;

use App\Models\ForecastItemOverride;

/**
 * A "this month only" change to the occurrence the schedule puts on
 * `occurrenceDate`.
 */
final readonly class ItemOverride
{
    public function __construct(
        public string $occurrenceDate,
        public ?int $amount = null,
        public ?string $movedTo = null,
        public bool $skipped = false,
    ) {}

    /**
     * @param  array{occurrenceDate: string, amount?: int|null, movedTo?: string|null, skipped?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['occurrenceDate'],
            $data['amount'] ?? null,
            $data['movedTo'] ?? null,
            $data['skipped'] ?? false,
        );
    }

    public static function fromModel(ForecastItemOverride $override): self
    {
        return new self(
            $override->occurrence_date->toDateString(),
            $override->amount,
            $override->moved_to?->toDateString(),
            $override->skipped,
        );
    }
}
