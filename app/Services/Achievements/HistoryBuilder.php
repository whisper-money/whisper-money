<?php

namespace App\Services\Achievements;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceLookup;
use App\Services\CashflowSummaryService;
use App\Services\NetWorthCalculator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads one user's whole past into a {@see History}.
 *
 * The whole past, on every sweep, rather than the months since the last one: a
 * user who imports three years of statements today changes what happened in
 * 2023, and a medal dated by when we noticed would be a lie. The cost is a few
 * hundred rows and one balance walk per user, on a nightly job — if it ever
 * stops being cheap, the fix is to skip users with no new transactions since
 * their last sweep, not to shorten the window.
 */
class HistoryBuilder
{
    public function __construct(
        private CashflowSummaryService $cashflow,
        private NetWorthCalculator $netWorth,
        private Ladders $ladders,
    ) {}

    public function for(User $user): History
    {
        $currency = $this->ladders->currencyFor($user->currency_code);
        $first = $this->firstMonth($user);
        $last = now()->subMonth()->startOfMonth();

        if ($first === null || $first->gt($last)) {
            return new History($currency);
        }

        $accounts = Account::query()->where('user_id', $user->id)->get();
        $lookup = BalanceLookup::forAccounts($accounts->pluck('id'), $first, $last->copy()->endOfMonth());
        $months = $this->cashflow->forMonths($user->id, $currency, $first, $last->copy()->endOfMonth());
        $counts = $this->transactionCounts($user, $first, $last);
        $goal = $this->goalReached($user);

        return new History(
            currency: $currency,
            months: $months,
            netWorth: $this->netWorthSeries($user, $accounts, $lookup, array_keys($months), $currency),
            liquid: $this->liquidSeries($accounts, $lookup, array_keys($months), $currency),
            transactions: $this->column($counts, array_keys($months), 'total'),
            uncategorized: $this->column($counts, array_keys($months), 'uncategorized'),
            events: $this->events($user, $accounts, $lookup, array_keys($months), $goal),
            goalReachedAmount: $goal['amount'] ?? null,
        );
    }

    private function firstMonth(User $user): ?Carbon
    {
        $earliest = Transaction::query()
            ->where('user_id', $user->id)
            ->min('transaction_date');

        return $earliest === null ? null : Carbon::parse($earliest)->startOfMonth();
    }

    /**
     * Transactions recorded per month and how many of them were left without a
     * category, in one grouped query.
     *
     * @return array<string, array{total: int, uncategorized: int}>
     */
    private function transactionCounts(User $user, Carbon $first, Carbon $last): array
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$first, $last->copy()->endOfMonth()])
            ->selectRaw("date_format(transaction_date, '%Y-%m') as period")
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when category_id is null then 1 else 0 end) as uncategorized')
            ->groupBy('period')
            // Rows of aggregates, not models: read off the base query so they
            // stay the plain objects they are.
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->period => [
                'total' => (int) $row->total,
                'uncategorized' => (int) $row->uncategorized,
            ]])
            ->all();
    }

    /**
     * @param  array<string, array<string, int>>  $rows
     * @param  list<string>  $months
     * @return array<string, int>
     */
    private function column(array $rows, array $months, string $key): array
    {
        $series = [];

        foreach ($months as $month) {
            $series[$month] = $rows[$month][$key] ?? 0;
        }

        return $series;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  list<string>  $months
     * @return array<string, int>
     */
    private function netWorthSeries(User $user, Collection $accounts, BalanceLookup $lookup, array $months, string $currency): array
    {
        $excluded = $this->netWorth->excludedTypesFor($user);
        $series = [];

        foreach ($months as $month) {
            $series[$month] = $this->netWorth->at($accounts, $lookup, $this->endOf($month), $currency, $excluded);
        }

        return $series;
    }

    /**
     * What is actually reachable in an emergency: current and savings accounts,
     * nothing that has to be sold or unwound first.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  list<string>  $months
     * @return array<string, int>
     */
    private function liquidSeries(Collection $accounts, BalanceLookup $lookup, array $months, string $currency): array
    {
        $liquid = $accounts->filter(fn (Account $account): bool => in_array(
            $account->type,
            [AccountType::Checking, AccountType::Savings],
            true,
        ));

        $series = [];

        foreach ($months as $month) {
            $series[$month] = $this->netWorth->at($liquid, $lookup, $this->endOf($month), $currency);
        }

        return $series;
    }

    /**
     * The one-off milestones, each as the month it happened.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  list<string>  $months
     * @param  array{month: string, amount: int}|array{}  $goal
     * @return array<string, ?string>
     */
    private function events(User $user, Collection $accounts, BalanceLookup $lookup, array $months, array $goal): array
    {
        return [
            'first_bank' => $this->monthOf($user->bankingConnections()->min('created_at')),
            'three_accounts' => $this->monthOf($this->thirdConnectedAccountAt($user)),
            'first_rule' => $this->monthOf($user->automationRules()->min('created_at')),
            'first_budget' => $this->monthOf($user->budgets()->min('created_at')),
            'goal_reached' => $goal['month'] ?? null,
            'loan_paid_off' => $this->loanPaidOff($accounts, $lookup, $months),
        ];
    }

    private function thirdConnectedAccountAt(User $user): ?string
    {
        return $user->accounts()
            ->whereNotNull('banking_connection_id')
            ->orderBy('created_at')
            ->skip(2)
            ->take(1)
            ->value('created_at');
    }

    /**
     * The first goal to reach its target, and what it was worth.
     *
     * Dated by when the goal finished rather than by the contribution that
     * crossed the line: nothing records the crossing, and reconstructing it
     * would be a walk through every tagged transaction of every goal for a
     * single medal.
     *
     * @return array{month: string, amount: int}|array{}
     */
    private function goalReached(User $user): array
    {
        foreach ($user->savingsGoals()->orderBy('created_at')->get() as $goal) {
            if ($goal->target_amount > 0 && $goal->savedAmountInCents() >= $goal->target_amount) {
                return [
                    'month' => $goal->measuredAt()->format('Y-m'),
                    'amount' => $goal->target_amount,
                ];
            }
        }

        return [];
    }

    /**
     * The month a loan first reached zero, having owed something before it. Per
     * account rather than over the total, so paying one of two loans off still
     * counts.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  list<string>  $months
     */
    private function loanPaidOff(Collection $accounts, BalanceLookup $lookup, array $months): ?string
    {
        $cleared = [];

        foreach ($accounts->where('type', AccountType::Loan) as $loan) {
            $owed = false;

            foreach ($months as $month) {
                $balance = abs($lookup->getBalanceAt($loan->id, $this->endOf($month)));

                if ($balance > 0) {
                    $owed = true;

                    continue;
                }

                if ($owed) {
                    $cleared[] = $month;

                    break;
                }
            }
        }

        return $cleared === [] ? null : min($cleared);
    }

    private function monthOf(mixed $timestamp): ?string
    {
        return $timestamp === null ? null : Carbon::parse($timestamp)->format('Y-m');
    }

    private function endOf(string $month): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $month.'-01')->endOfMonth();
    }
}
