<?php

namespace App\Services\Forecast\Data;

/**
 * Everything ForecastProjector works from. `startDate` is a `Y-m-d` day; the
 * projection covers its month and the `horizonMonths - 1` months after it.
 */
final readonly class ProjectionInput
{
    /**
     * @param  list<ProjectionAccount>  $accounts
     * @param  list<Occurrence>  $occurrences
     * @param  list<MonthlyEstimate>  $estimates
     */
    public function __construct(
        public array $accounts,
        public array $occurrences,
        public array $estimates,
        public string $startDate,
        public int $horizonMonths,
    ) {}

    /**
     * @param  array{accounts: list<array{id: string, openingBalance: int, annualRateBps?: int|null, minimumBalance?: int|null}>, occurrences: list<array{itemId: string, accountId: string, destinationAccountId?: string|null, date: string, occurrenceDate?: string, amount: int}>, estimates?: list<array{accountId: string, month: string, amount: int}>, startDate: string, horizonMonths: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(ProjectionAccount::fromArray(...), $data['accounts']),
            array_map(Occurrence::fromArray(...), $data['occurrences']),
            array_map(MonthlyEstimate::fromArray(...), $data['estimates'] ?? []),
            $data['startDate'],
            $data['horizonMonths'],
        );
    }
}
