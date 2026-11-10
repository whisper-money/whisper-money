<?php

namespace App\Services\Notifications;

use App\Enums\TransactionSource;
use App\Models\User;
use App\Services\CashflowSummaryService;
use App\Services\PeriodComparator;
use Carbon\CarbonInterface;

/**
 * What happened in a user's data while they were not looking: the bank rows
 * that synced in since their last visit, how many of those still need a
 * category, and how this month's spending compares to the same stretch of
 * the last one.
 *
 * Counts and a percentage only, never an amount: like the monthly summary
 * email, whatever this feeds sits in an inbox for years.
 */
class WhileAwayRecap
{
    /**
     * Too few days into the month and the comparison is one or two bills
     * against one or two bills, which says nothing worth an email.
     */
    private const MIN_DAYS_INTO_MONTH = 7;

    /**
     * Rows a bank or provider sync writes. Manual and imported rows are the
     * user's own doing, so they cannot have arrived while they were away.
     *
     * @var list<TransactionSource>
     */
    private const SYNCED_SOURCES = [
        TransactionSource::EnableBanking,
        TransactionSource::Wise,
    ];

    public function __construct(private CashflowSummaryService $summaries) {}

    /**
     * @return array{newTransactions: int, uncategorized: int, spendingChange: ?float}
     */
    public function for(User $user, CarbonInterface $since): array
    {
        $synced = $user->transactions()
            ->whereIn('source', self::SYNCED_SOURCES)
            ->whereNull('split_parent_id')
            ->where('created_at', '>=', $since);

        return [
            'newTransactions' => (clone $synced)->count(),
            'uncategorized' => (clone $synced)->whereNull('category_id')->count(),
            'spendingChange' => $this->spendingChange($user),
        ];
    }

    /**
     * Month-to-date spending against the same days of last month, as a signed
     * percentage (positive is more), or null when there is nothing fair to
     * compare against.
     */
    private function spendingChange(User $user): ?float
    {
        $today = $user->localNow()->startOfDay();

        if ($today->day < self::MIN_DAYS_INTO_MONTH) {
            return null;
        }

        $current = new PeriodComparator($today->copy()->startOfMonth(), $today->copy()->endOfDay());
        $lastMonth = $today->copy()->subMonthNoOverflow();
        $previous = new PeriodComparator($lastMonth->copy()->startOfMonth(), $lastMonth->copy()->endOfDay());

        $summary = $this->summaries->forComparedPeriods($user->id, $user->currency_code, $current, $previous);

        $spent = $summary['current']['expense'];
        $spentBefore = $summary['previous']['expense'];

        if ($spentBefore <= 0 || $spent < 0) {
            return null;
        }

        return round((($spent - $spentBefore) / $spentBefore) * 100, 1);
    }
}
