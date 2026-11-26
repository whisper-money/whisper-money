<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemSource;

/**
 * A forecast line about to be stored: typed in by the user, or confirmed
 * from a suggestion. `amount` is signed from the line's account, in its
 * minor units. A transfer names the account it goes to, or leaves the app
 * (`destinationExternal`), in which case it counts as spend.
 */
final readonly class NewForecastLine
{
    /**
     * @param  array<string, mixed>|null  $matchRules  JsonLogic; built from the name when null and not a transfer.
     */
    public function __construct(
        public string $accountId,
        public string $name,
        public int $amount,
        public ForecastFrequency $frequency,
        public string $anchorDate,
        public ?string $endsOn = null,
        public ?string $categoryId = null,
        public ?string $destinationAccountId = null,
        public bool $destinationExternal = false,
        public ForecastItemSource $source = ForecastItemSource::Manual,
        public ?string $fingerprint = null,
        public ?int $amountTolerance = null,
        public ?array $matchRules = null,
    ) {}

    public static function fromCandidate(ForecastCandidate $candidate): self
    {
        return new self(
            accountId: $candidate->accountId,
            name: $candidate->name,
            amount: $candidate->amount,
            frequency: $candidate->frequency,
            anchorDate: $candidate->nextDate,
            categoryId: $candidate->categoryId,
            destinationAccountId: $candidate->destinationAccountId,
            destinationExternal: $candidate->destinationUnknown,
            source: ForecastItemSource::Detected,
            fingerprint: $candidate->fingerprint,
            amountTolerance: $candidate->amountTolerance,
        );
    }

    public function isTransfer(): bool
    {
        return $this->destinationAccountId !== null || $this->destinationExternal;
    }
}
