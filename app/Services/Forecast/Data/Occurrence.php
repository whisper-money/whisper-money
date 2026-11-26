<?php

namespace App\Services\Forecast\Data;

/**
 * One dated movement of a forecast line. `occurrenceDate` is the date its
 * schedule gives it, which matching and overrides key on; `date` is when it is
 * expected to land, after any move. `amount` is signed from the line's
 * account: a transfer takes it from `accountId` and gives its opposite to
 * `destinationAccountId`.
 *
 * The forecast pages, jobs and MCP tool of the later phases call it; until
 * then only the tests do.
 *
 * @api
 */
final readonly class Occurrence
{
    public function __construct(
        public string $itemId,
        public string $accountId,
        public ?string $destinationAccountId,
        public string $date,
        public string $occurrenceDate,
        public int $amount,
    ) {}

    /**
     * @param  array{itemId: string, accountId: string, destinationAccountId?: string|null, date: string, occurrenceDate?: string, amount: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['itemId'],
            $data['accountId'],
            $data['destinationAccountId'] ?? null,
            $data['date'],
            $data['occurrenceDate'] ?? $data['date'],
            $data['amount'],
        );
    }

    public function isTransfer(): bool
    {
        return $this->destinationAccountId !== null;
    }

    /**
     * @return array{itemId: string, accountId: string, destinationAccountId: string|null, date: string, occurrenceDate: string, amount: int}
     */
    public function toArray(): array
    {
        return [
            'itemId' => $this->itemId,
            'accountId' => $this->accountId,
            'destinationAccountId' => $this->destinationAccountId,
            'date' => $this->date,
            'occurrenceDate' => $this->occurrenceDate,
            'amount' => $this->amount,
        ];
    }
}
