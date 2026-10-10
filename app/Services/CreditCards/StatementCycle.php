<?php

namespace App\Services\CreditCards;

use Carbon\CarbonImmutable;

/**
 * One credit card statement: the purchases between the day after the previous
 * closing and its own closing date, both inclusive, charged on its due date.
 */
final readonly class StatementCycle
{
    public function __construct(
        public CarbonImmutable $periodFrom,
        public CarbonImmutable $closingDate,
        public CarbonImmutable $dueDate,
    ) {}

    public function contains(CarbonImmutable $day): bool
    {
        return $day->betweenIncluded($this->periodFrom, $this->closingDate);
    }

    /**
     * @return array{period_from: string, closing_date: string, due_date: string}
     */
    public function toArray(): array
    {
        return [
            'period_from' => $this->periodFrom->toDateString(),
            'closing_date' => $this->closingDate->toDateString(),
            'due_date' => $this->dueDate->toDateString(),
        ];
    }
}
