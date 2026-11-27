<?php

namespace App\Services\Forecast\Data;

/**
 * What the user usually moves from one of their accounts to another in a
 * `Y-m` month, in positive minor units of both accounts' currency (a
 * transfer only pairs within one currency). In the month the projection
 * starts in, `amount` is what is left of it after what already moved
 * (`moved`); `planned` is the month's whole figure. It lands whole on
 * `date`.
 *
 * One of the two accounts may be one the forecast leaves out (a loan, a
 * house): the projector then moves only the side it projects, so the money
 * leaves or enters the forecast.
 *
 * @api
 */
final readonly class TransferEstimate
{
    public function __construct(
        public string $fromAccountId,
        public string $toAccountId,
        public string $fromAccountName,
        public string $toAccountName,
        public string $month,
        public int $amount,
        public int $planned,
        public int $moved,
        public string $date,
    ) {}

    /**
     * The id its occurrences carry instead of a line's, the same in every
     * month, so the page shows the pair as one row.
     */
    public function key(): string
    {
        return 'transfer-estimate:'.$this->fromAccountId.':'.$this->toAccountId;
    }

    public function touches(string $accountId): bool
    {
        return $this->fromAccountId === $accountId || $this->toAccountId === $accountId;
    }

    /**
     * "Payroll → Daily".
     */
    public function label(): string
    {
        return $this->fromAccountName.' → '.$this->toAccountName;
    }

    /**
     * The transfer as the projector moves it, or null when nothing is left
     * to move this month.
     */
    public function toOccurrence(): ?Occurrence
    {
        if ($this->amount <= 0) {
            return null;
        }

        return new Occurrence($this->key(), $this->fromAccountId, $this->toAccountId, $this->date, $this->date, -$this->amount);
    }
}
