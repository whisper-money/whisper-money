<?php

namespace App\Services\MonthlySummary;

use App\Enums\BankingConnectionStatus;
use App\Enums\RuleSuggestionStatus;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\MonthlySummary;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\RuleSuggestionAvailability;
use App\Services\BalanceLookup;
use App\Services\CashflowSummaryService;
use App\Services\CategorySpendingService;
use App\Services\ExchangeRateService;
use App\Services\NetWorthCalculator;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Freezes one closed month into the payload every surface reads from.
 *
 * Figures come from the same services the app itself uses — the same net worth
 * calculator as the dashboard chart, the same cashflow and category services as
 * the cashflow screen — so the email cannot quote a number the user cannot find
 * in the product. Everything is scoped by `user_id`, matching what the web
 * shows today; the space travels with the summary as its identity, not as a
 * filter, ready for the day the web itself becomes space-scoped.
 */
class SummaryBuilder
{
    /**
     * Months of history carried in the payload, enough for a savings-rate
     * sparkline, a net worth trend and a streak.
     */
    private const HISTORY_MONTHS = 12;

    /**
     * A connection whose access expires within this many days is worth acting on
     * this month.
     */
    private const EXPIRY_WARNING_DAYS = 21;

    public function __construct(
        private NetWorthCalculator $netWorthCalculator,
        private CashflowSummaryService $cashflow,
        private CategorySpendingService $categorySpending,
        private Readiness $readiness,
        private RuleSuggestionAvailability $ruleSuggestions,
        private ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Carbon $month, bool $complete): array
    {
        $month = $month->copy()->startOfMonth();
        $currency = $user->currency_code;
        $accounts = $this->accountsOf($user);
        $lookup = $this->lookupFor($accounts, $month);
        $months = $this->cashflow->forMonths(
            $user->id,
            $currency,
            $month->copy()->subMonths(self::HISTORY_MONTHS),
            $month->copy()->endOfMonth(),
        );

        $cashflow = $this->cashflowSection($months, $month);

        return [
            'period' => $month->format('Y-m'),
            'currency' => $currency,
            'generated_at' => now()->toIso8601String(),
            'complete' => $complete,
            'has_history' => $this->readiness->hasHistoryBefore($user, $month),
            'net_worth' => $this->netWorthSection($user, $accounts, $lookup, $month, $currency),
            'cashflow' => $cashflow,
            'savings_rate_history' => $this->savingsRateHistory($months),
            'streak_months' => $this->streakMonths($months, $month, $user, $currency),
            'best_savings_rate_in_year' => $this->isBestSavingsRate($months, $month),
            'categories' => $this->categoriesSection($user, $month, $cashflow['expense']),
            'biggest_drop' => $this->biggestDrop($user, $month),
            'invested' => $this->investedSection($accounts, $lookup, $month, $currency),
            'budgets' => $this->budgetsSection($user, $month),
            'goal' => $this->goalSection($user, $month),
            'todos' => $this->todosSection($user, $month),
            'account_names' => $this->accountNames($accounts),
        ];
    }

    /**
     * @return Collection<int, Account>
     */
    private function accountsOf(User $user): Collection
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name')
            ->get();
    }

    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function lookupFor(Collection $accounts, Carbon $month): BalanceLookup
    {
        return BalanceLookup::forAccounts(
            $accounts->pluck('id'),
            $month->copy()->subMonths(self::HISTORY_MONTHS)->startOfMonth(),
            $month->copy()->endOfMonth(),
        );
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<string, mixed>
     */
    private function netWorthSection(User $user, Collection $accounts, BalanceLookup $lookup, Carbon $month, string $currency): array
    {
        $excluded = $this->netWorthCalculator->excludedTypesFor($user);
        $history = [];

        for ($ago = self::HISTORY_MONTHS; $ago >= 0; $ago--) {
            $end = $month->copy()->subMonths($ago)->endOfMonth();
            $history[] = [
                'month' => $end->format('Y-m'),
                'value' => $this->netWorthCalculator->at($accounts, $lookup, $end, $currency, $excluded),
            ];
        }

        $current = end($history)['value'];
        $previous = $history[count($history) - 2]['value'] ?? 0;
        $yearAgo = $history[0]['value'];

        return [
            'current' => $current,
            'previous' => $previous,
            'diff' => $current - $previous,
            'diff_percent' => $this->percentChange($previous, $current),
            'year_percent' => $this->percentChange($yearAgo, $current),
            'history' => $history,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $months
     * @return array<string, mixed>
     */
    private function cashflowSection(array $months, Carbon $month): array
    {
        $current = $months[$month->format('Y-m')] ?? [];
        $previous = $months[$month->copy()->subMonth()->format('Y-m')] ?? [];

        return [
            'income' => (int) ($current['income'] ?? 0),
            'expense' => (int) ($current['expense'] ?? 0),
            'net' => (int) ($current['net'] ?? 0),
            'savings_rate' => (float) ($current['savings_rate'] ?? 0),
            'previous' => [
                'income' => (int) ($previous['income'] ?? 0),
                'expense' => (int) ($previous['expense'] ?? 0),
                'net' => (int) ($previous['net'] ?? 0),
                'savings_rate' => (float) ($previous['savings_rate'] ?? 0),
            ],
            'expense_change_percent' => $this->percentChange(
                (int) ($previous['expense'] ?? 0),
                (int) ($current['expense'] ?? 0),
            ),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $months
     * @return list<array{month: string, rate: float}>
     */
    private function savingsRateHistory(array $months): array
    {
        $history = [];

        foreach ($months as $key => $summary) {
            $history[] = ['month' => $key, 'rate' => (float) ($summary['savings_rate'] ?? 0)];
        }

        return $history;
    }

    /**
     * Consecutive months ending at the closed one where more came in than went
     * out. It is the figure that takes months to earn, which is why it leads the
     * card ranking.
     *
     * The payload's twelve-month window is not enough to count it. A reader
     * whose whole window is in the black has a streak of at least thirteen, and
     * reporting thirteen would be a number they can disprove — so once the count
     * reaches the edge, older months are loaded a year at a time until one of
     * them breaks the run or the reader's history runs out. Almost nobody pays
     * for the extra query: it only fires on a streak that already spans the year.
     *
     * @param  array<string, array<string, mixed>>  $months
     */
    private function streakMonths(array $months, Carbon $month, User $user, string $currency): int
    {
        $streak = 0;
        $cursor = $month->copy();
        $loadedFrom = $month->copy()->subMonths(self::HISTORY_MONTHS)->startOfMonth();

        while (true) {
            if (! isset($months[$cursor->format('Y-m')])) {
                if (! $this->readiness->hasDataFor($user, $cursor)) {
                    break;
                }

                $months = $this->cashflow->forMonths(
                    $user->id,
                    $currency,
                    $loadedFrom->copy()->subMonths(self::HISTORY_MONTHS),
                    $loadedFrom->copy()->subMonth()->endOfMonth(),
                ) + $months;

                $loadedFrom->subMonths(self::HISTORY_MONTHS);

                continue;
            }

            if ((int) ($months[$cursor->format('Y-m')]['net'] ?? 0) <= 0) {
                break;
            }

            $streak++;
            $cursor->subMonth();
        }

        return $streak;
    }

    /**
     * @param  array<string, array<string, mixed>>  $months
     */
    private function isBestSavingsRate(array $months, Carbon $month): bool
    {
        $key = $month->format('Y-m');
        $rate = (float) ($months[$key]['savings_rate'] ?? 0);

        if ($rate <= 0 || count($months) < 3) {
            return false;
        }

        foreach ($months as $candidate => $summary) {
            if ($candidate !== $key && (float) ($summary['savings_rate'] ?? 0) >= $rate) {
                return false;
            }
        }

        return true;
    }

    /**
     * The three biggest categories, and what they took of the month.
     *
     * `$spent` is the month's whole expense side, not the sum of the categorised
     * rows: {@see CategorySpendingService::forPeriod()} inner-joins `categories`
     * and so cannot see a transaction that has none, while the cashflow screen
     * shows those as an "Unknown Expense" row and counts them. Dividing by the
     * categorised sum told a reader with three quarters of their month
     * uncategorised that three categories took 97.9% of the 3,677.96 EUR they
     * spent, on a month the screen beside it totalled at 15,291.43 EUR.
     *
     * The top three stay categories the reader named. An unknown bucket is not a
     * finding worth a sentence — the uncategorised to-do at the foot of the
     * email is where that belongs.
     *
     * @return array<string, mixed>
     */
    private function categoriesSection(User $user, Carbon $month, int $spent): array
    {
        $period = $this->periodFor($month);
        $current = $this->categorySpending->forPeriod($user->id, $period->from, $period->to);
        $previous = $this->previousSpending($user, $month);
        $total = $spent;

        $top = $current->sortByDesc('amount')->take(3)->map(fn (array $item): array => [
            'name' => $item['category']?->name,
            'amount' => (int) $item['amount'],
            'share' => $this->share((int) $item['amount'], $total),
            'previous_amount' => $this->amountFor($previous, $item['category_id']),
            'change_percent' => $this->percentChange(
                $this->amountFor($previous, $item['category_id']),
                (int) $item['amount'],
            ),
        ])->values()->all();

        return [
            'total' => $total,
            'count' => $current->count(),
            'top' => $top,
            'top_share' => $this->share((int) collect($top)->sum('amount'), $total),
        ];
    }

    /**
     * The category that fell the most in absolute money, which is the honest
     * reading: measured in percent, a category that went from 12 € to 3 € wins
     * every month and says nothing.
     *
     * @return array<string, mixed>|null
     */
    private function biggestDrop(User $user, Carbon $month): ?array
    {
        $period = $this->periodFor($month);
        $current = $this->categorySpending->forPeriod($user->id, $period->from, $period->to);
        $previous = $this->previousSpending($user, $month);

        $drops = $current
            ->map(fn (array $item): array => [
                'name' => $item['category']?->name,
                'amount' => (int) $item['amount'],
                'previous_amount' => $this->amountFor($previous, $item['category_id']),
            ])
            ->filter(fn (array $item): bool => $item['previous_amount'] > $item['amount'] && $item['previous_amount'] > 0)
            ->sortByDesc(fn (array $item): int => $item['previous_amount'] - $item['amount']);

        $drop = $drops->first();

        if ($drop === null) {
            return null;
        }

        return [
            ...$drop,
            'change_percent' => $this->percentChange($drop['previous_amount'], $drop['amount']),
        ];
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<string, mixed>|null
     */
    private function investedSection(Collection $accounts, BalanceLookup $lookup, Carbon $month, string $currency): ?array
    {
        $end = $month->copy()->endOfMonth();
        $contributed = 0;
        $value = 0;

        foreach ($accounts as $account) {
            if (! $account->type->supportsInvestedAmount() || $account->isArchivedOn($end)) {
                continue;
            }

            $invested = $lookup->getInvestedAmountAt($account->id, $end);
            $balance = $lookup->getBalanceAt($account->id, $end);

            // An emptied account still reports the last invested amount it ever
            // had, because BalanceLookup carries the newest non-null one forward
            // over the nulls that follow. Read together with a zero balance that
            // says the reader lost every penny, when what they did was move the
            // money out: one reader was going to be told their investments were
            // 34,989.98 EUR down, the month after transferring exactly that to
            // their current account.
            if ($invested === null || $balance === 0) {
                continue;
            }

            $contributed += $this->inUserCurrency($account, $invested, $end, $currency);
            $value += $this->inUserCurrency($account, $balance, $end, $currency);
        }

        if ($contributed === 0) {
            return null;
        }

        return ['contributed' => $contributed, 'value' => $value, 'gain' => $value - $contributed, 'currency' => $currency];
    }

    /**
     * A balance in the reader's currency. Balances are stored in the account's
     * own currency, so without this a BTC or USD account is added to euros as
     * though the numbers were the same kind of thing — which is how a €6,954
     * gain was about to be reported as €34,622.
     */
    private function inUserCurrency(Account $account, int $amount, Carbon $date, string $currency): int
    {
        return $this->exchangeRates->convert(
            $account->currency_code,
            $currency,
            $amount,
            $date->toDateString(),
        );
    }

    /**
     * How the reader's budgets went, judged only on the periods that a report
     * about this month is entitled to judge.
     *
     * Two rules the naive overlap query got wrong. A period is only counted when
     * it sits entirely inside the closed month: a yearly period is still running
     * and cannot be "met" in August, and a weekly one straddling the 1st carries
     * spending from the month either side — {@see BudgetPeriod::spentAmount()}
     * sums the whole period, so an overlapping one drags foreign months in.
     *
     * And the count is of budgets, not periods. A weekly budget has four or five
     * periods in a month; counting those told a reader with four budgets that
     * they had met eleven of fourteen, and named the same budget three times.
     *
     * @return array<string, mixed>
     */
    private function budgetsSection(User $user, Carbon $month): array
    {
        $periods = BudgetPeriod::query()
            ->whereIn('budget_id', Budget::query()->where('user_id', $user->id)->notArchived()->select('id'))
            ->whereDate('start_date', '>=', $month->copy()->startOfMonth())
            ->whereDate('end_date', '<=', $month->copy()->endOfMonth())
            ->with('budget:id,name')
            ->get();

        $overspent = [];
        $budgets = $periods->groupBy('budget_id');

        foreach ($budgets as $budgetPeriods) {
            $over = $budgetPeriods->sum(
                fn (BudgetPeriod $period): int => max(0, $period->spentAmount() - $period->allocated_amount),
            );

            if ($over > 0) {
                $overspent[] = ['name' => $budgetPeriods->first()->budget?->name, 'over_by' => $over];
            }
        }

        return [
            'total' => $budgets->count(),
            'met' => $budgets->count() - count($overspent),
            'overspent' => $overspent,
        ];
    }

    /**
     * The goal furthest along, which is the one worth mentioning. Its percentage
     * is also what next month compares against to spot a crossed decile, and its
     * three-month pace is what lets the analysis say when it lands without
     * inventing the arithmetic.
     *
     * @return array<string, mixed>|null
     */
    private function goalSection(User $user, Carbon $month): ?array
    {
        $goal = $user->savingsGoals()
            ->notArchived()
            ->where('target_amount', '>', 0)
            ->with('label')
            ->get()
            ->sortByDesc(fn (SavingsGoal $candidate): float => $candidate->savedAmountInCents() / max(1, (int) $candidate->target_amount))
            ->first();

        if ($goal === null) {
            return null;
        }

        $saved = $goal->savedAmountInCents();
        $target = (int) $goal->target_amount;
        $pace = $this->goalPace($goal, $month);

        return [
            'name' => $goal->name,
            'saved' => $saved,
            'target' => $target,
            'percent' => round(min(100, $saved / $target * 100), 1),
            'monthly_pace' => $pace,
            'eta_month' => $this->goalEta($month, $target - $saved, $pace),
        ];
    }

    /**
     * Average monthly contribution over the three months ending with the closed
     * one. Zero when the goal has no label to tag contributions with, which is
     * how a goal that only ever had a starting balance behaves.
     */
    private function goalPace(SavingsGoal $goal, Carbon $month): int
    {
        if ($goal->label === null) {
            return 0;
        }

        $contributed = (int) Transaction::query()
            ->join('label_transaction', 'label_transaction.transaction_id', '=', 'transactions.id')
            ->joinOwningAccount()
            ->where('label_transaction.label_id', $goal->label_id)
            ->whereBetween('transactions.transaction_date', [
                $month->copy()->subMonths(2)->startOfMonth(),
                $month->copy()->endOfMonth(),
            ])
            ->sum(DB::raw(SavingsGoal::CONTRIBUTION_AMOUNT_SQL));

        return (int) round(max(0, $contributed) / 3);
    }

    /**
     * The month the goal reaches its target if the current pace holds, or null
     * when it is already there, the pace is zero, or the answer is far enough
     * out that quoting it would be false precision.
     */
    private function goalEta(Carbon $month, int $remaining, int $pace): ?string
    {
        if ($remaining <= 0 || $pace <= 0) {
            return null;
        }

        $months = (int) ceil($remaining / $pace);

        return $months > 36 ? null : $month->copy()->addMonths($months)->format('Y-m');
    }

    /**
     * @return array<string, mixed>
     */
    private function todosSection(User $user, Carbon $month): array
    {
        $period = $this->periodFor($month);

        $uncategorised = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereBetween('transaction_date', [$period->from, $period->to])
            ->selectRaw('count(*) as total, coalesce(sum(abs(amount)), 0) as amount')
            ->first();

        return [
            'uncategorised' => [
                'count' => (int) ($uncategorised->total ?? 0),
                'amount' => (int) ($uncategorised->amount ?? 0),
            ],
            'rule_suggestions' => $this->pendingRuleSuggestions($user),
            'expiring_connections' => $this->expiringConnections($user),
        ];
    }

    /**
     * Suggestions still waiting on the user, with the number of transactions
     * they match. A Pro-only action, but computed for everyone: on a free reader
     * the same count is what argues the upsell.
     *
     * @return array{count: int, transactions: int}
     */
    private function pendingRuleSuggestions(User $user): array
    {
        $run = $this->ruleSuggestions->latestSuccessfulRun($user);

        if ($run === null) {
            return ['count' => 0, 'transactions' => 0];
        }

        $pending = $run->suggestions()
            ->where('status', RuleSuggestionStatus::Pending)
            ->selectRaw('count(*) as total, coalesce(sum(group_size), 0) as transactions')
            ->first();

        return [
            'count' => (int) ($pending->total ?? 0),
            // How many transactions the suggestions actually match, so the email
            // can say what applying them buys without inventing the figure.
            'transactions' => (int) ($pending->transactions ?? 0),
        ];
    }

    /**
     * @return list<array{bank: string|null, days: int}>
     */
    private function expiringConnections(User $user): array
    {
        return $user->bankingConnections()
            ->where('status', BankingConnectionStatus::Active)
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [now(), now()->addDays(self::EXPIRY_WARNING_DAYS)])
            ->get()
            ->map(fn ($connection): array => [
                'bank' => $connection->aspsp_name,
                'days' => max(0, (int) now()->diffInDays($connection->valid_until, false)),
            ])
            ->values()
            ->all();
    }

    /**
     * Bank and account names, for the AI analysis only. Transaction descriptions
     * and merchants deliberately never leave the account.
     *
     * @param  Collection<int, Account>  $accounts
     * @return list<string>
     */
    private function accountNames(Collection $accounts): array
    {
        return $accounts
            ->map(fn (Account $account): string => trim(($account->bank?->name ? $account->bank->name.' · ' : '').$account->name))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function previousSpending(User $user, Carbon $month): Collection
    {
        $previous = $this->periodFor($month->copy()->subMonth());

        return $this->categorySpending->forPeriod($user->id, $previous->from, $previous->to);
    }

    private function periodFor(Carbon $month): PeriodComparator
    {
        return new PeriodComparator($month->copy()->startOfMonth(), $month->copy()->endOfMonth());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $spending
     */
    private function amountFor(Collection $spending, ?string $categoryId): int
    {
        return (int) ($spending->firstWhere('category_id', $categoryId)['amount'] ?? 0);
    }

    private function share(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    private function percentChange(int $from, int $to): float
    {
        return $from !== 0 ? round(($to - $from) / abs($from) * 100, 1) : 0.0;
    }

    /**
     * Last month's frozen percentage for the same goal, so a crossed decile can
     * be detected without recomputing a goal's history — which the product does
     * not track.
     */
    public function previousGoalPercent(User $user, Carbon $month, ?string $goalName): ?float
    {
        if ($goalName === null) {
            return null;
        }

        $previous = MonthlySummary::query()
            ->where('user_id', $user->id)
            ->where('period', $month->copy()->subMonth()->format('Y-m'))
            ->first();

        if ($previous === null || $previous->figure('goal.name') !== $goalName) {
            return null;
        }

        return (float) $previous->figure('goal.percent', 0);
    }
}
