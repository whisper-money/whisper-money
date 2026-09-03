<?php

namespace App\Services\Achievements;

/**
 * Everything about one user's past that the medals are read from, loaded once.
 *
 * All the series are keyed by month (YYYY-MM) in ascending order and cover the
 * same span: the month of their first transaction through the last closed
 * month. Money is in the minor units of {@see $currency}, which is the user's
 * own when a ladder exists for it and the fallback when it does not — so a
 * threshold and a figure are always in the same money.
 */
final readonly class History
{
    /**
     * @param  array<string, array<string, mixed>>  $months  cashflow per month: income, expense, net, savings_rate, savings, investments
     * @param  array<string, int>  $netWorth  total at each month end
     * @param  array<string, int>  $liquid  checking and savings balances at each month end
     * @param  array<string, int>  $transactions  transactions recorded in the month
     * @param  array<string, int>  $uncategorized  of those, the ones with no category
     * @param  array<string, ?string>  $events  one-off milestones, as YYYY-MM or null
     */
    public function __construct(
        public string $currency,
        private array $months = [],
        public array $netWorth = [],
        public array $liquid = [],
        public array $transactions = [],
        public array $uncategorized = [],
        public array $events = [],
        public ?int $goalReachedAmount = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->months === [];
    }

    /**
     * One figure per month, in order.
     *
     * @return array<string, int|float>
     */
    public function series(string $figure): array
    {
        return array_map(
            fn (array $month): int|float => $month[$figure] ?? 0,
            $this->months,
        );
    }

    /**
     * @return list<string>
     */
    public function monthKeys(): array
    {
        return array_keys($this->months);
    }
}
