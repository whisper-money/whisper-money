<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastFrequency;
use App\Models\ForecastItem;

/**
 * What OccurrenceExpander needs from a forecast line, detached from Eloquent
 * so the parity fixtures shared with the TypeScript engine can build it too.
 * Dates are `Y-m-d` strings.
 */
final readonly class ExpandableItem
{
    /**
     * @param  list<ItemVersion>  $versions
     * @param  list<ItemOverride>  $overrides
     */
    public function __construct(
        public string $id,
        public string $accountId,
        public ?string $destinationAccountId,
        public ForecastFrequency $frequency,
        public string $anchorDate,
        public ?string $endsOn,
        public array $versions,
        public array $overrides = [],
    ) {}

    /**
     * @param  array{id: string, accountId: string, destinationAccountId?: string|null, frequency: string, anchorDate: string, endsOn?: string|null, versions: list<array{effectiveFrom: string, amount: int}>, overrides?: list<array{occurrenceDate: string, amount?: int|null, movedTo?: string|null, skipped?: bool}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['accountId'],
            $data['destinationAccountId'] ?? null,
            ForecastFrequency::from($data['frequency']),
            $data['anchorDate'],
            $data['endsOn'] ?? null,
            array_map(ItemVersion::fromArray(...), $data['versions']),
            array_map(ItemOverride::fromArray(...), $data['overrides'] ?? []),
        );
    }

    /**
     * Reads the line with its `versions` and `overrides` relations, loading
     * them when the caller did not. A transfer out of the app has no
     * destination here, so it moves only the source account: it is spend.
     */
    public static function fromModel(ForecastItem $item): self
    {
        $item->loadMissing(['versions', 'overrides']);

        return new self(
            $item->id,
            $item->account_id,
            $item->destination_account_id,
            $item->frequency,
            $item->anchor_date->toDateString(),
            $item->ends_on?->toDateString(),
            $item->versions->map(ItemVersion::fromModel(...))->values()->all(),
            $item->overrides->map(ItemOverride::fromModel(...))->values()->all(),
        );
    }
}
