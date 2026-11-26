<?php

namespace App\Services\Forecast\Data;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastSuggestionKind;

/**
 * A forecast line the history suggests, for the user to confirm: nothing is
 * stored until they do. `amount` is signed from the account, in its minor
 * units; `amountTolerance` is how far the charges strayed from it. The
 * schedule repeats every `frequency` from `nextDate`, on `anchorDay` of the
 * month. A transfer names the account it goes to, or says that one is not in
 * the app (`destinationUnknown`).
 *
 * The review flow of a later phase reads it; until then only the tests do.
 *
 * @api
 */
final readonly class ForecastCandidate
{
    /**
     * @param  list<string>  $transactionIds  The charges it was built from, oldest first.
     */
    public function __construct(
        public ForecastSuggestionKind $kind,
        public string $accountId,
        public string $currencyCode,
        public string $name,
        public ?string $fingerprint,
        public ?string $categoryId,
        public ForecastFrequency $frequency,
        public int $amount,
        public int $amountTolerance,
        public int $anchorDay,
        public string $nextDate,
        public string $lastChargedOn,
        public ForecastConfidence $confidence,
        public array $transactionIds,
        public ?string $destinationAccountId = null,
        public bool $destinationUnknown = false,
    ) {}

    /**
     * What a dismissal of this suggestion is remembered by: its fingerprint,
     * or, for one recognised by amount and day alone, its account, direction
     * and day of the month.
     */
    public function dismissalKey(): string
    {
        return $this->fingerprint ?? sprintf('~%s:%s:%d', $this->accountId, $this->amount < 0 ? 'out' : 'in', $this->anchorDay);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'accountId' => $this->accountId,
            'currencyCode' => $this->currencyCode,
            'name' => $this->name,
            'fingerprint' => $this->fingerprint,
            'categoryId' => $this->categoryId,
            'frequency' => $this->frequency->value,
            'amount' => $this->amount,
            'amountTolerance' => $this->amountTolerance,
            'anchorDay' => $this->anchorDay,
            'nextDate' => $this->nextDate,
            'lastChargedOn' => $this->lastChargedOn,
            'confidence' => $this->confidence->value,
            'transactionIds' => $this->transactionIds,
            'destinationAccountId' => $this->destinationAccountId,
            'destinationUnknown' => $this->destinationUnknown,
        ];
    }
}
