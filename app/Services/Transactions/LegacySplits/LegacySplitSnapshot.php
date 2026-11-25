<?php

namespace App\Services\Transactions\LegacySplits;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use App\Services\CashflowAnalyticsService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures a legacy split conversion must leave as they were, for the
 * users it touches, read the way the application reads them. Taken before and
 * after, inside the conversion's database transaction, and compared by
 * {@see LegacySplitVerifier}.
 *
 * Every figure is keyed by a bucket of ids, never by a description, so the
 * snapshot can be printed without showing what a transaction was.
 *
 * Figures are keyed by family, then by bucket.
 *
 * @phpstan-type Figures array<string, array<string, int>>
 */
class LegacySplitSnapshot
{
    /** The monthly cashflow figures compared, as the cashflow summary names them. */
    private const array CASHFLOW_FIGURES = ['income', 'expense', 'net', 'savings', 'investments', 'fixed_expense', 'variable_expense', 'general_expense', 'reserved'];

    public function __construct(private readonly CashflowAnalyticsService $cashflow) {}

    /**
     * @param  array<string, list<string>>  $monthsByUser  `Y-m` months by user id
     * @param  list<string>  $budgetPeriodIds  periods to read on top of the users' own, see {@see self::budgetPeriodsHolding()}
     * @return Figures
     */
    public function take(array $monthsByUser, array $budgetPeriodIds = []): array
    {
        $userIds = array_keys($monthsByUser);

        return [
            'category' => $this->postingTotals($userIds, fn (Builder $query) => $query, 'transactions.space_id, coalesce('.Transaction::POSTING_CATEGORY_SQL.", '-')"),
            'label' => $this->postingTotals($userIds, fn (Builder $query) => $query->join('label_transaction', 'label_transaction.transaction_id', '=', 'transactions.id'), 'label_transaction.label_id'),
            'native cashflow' => $this->postingTotals(
                $userIds,
                fn (Builder $query) => $query->leftJoin('categories', 'categories.id', '=', DB::raw(Transaction::POSTING_CATEGORY_SQL)),
                "date_format(transactions.transaction_date, '%Y-%m'), coalesce(categories.type, '-')",
            ),
            'budget period' => $this->budgetPeriodTotals($userIds, $budgetPeriodIds),
            'account day' => $this->accountDayTotals($userIds),
            'cashflow' => $this->monthlyCashflow($monthsByUser),
        ];
    }

    /**
     * Sum and count of postings, the way every category and label total reads
     * them: an unsplit row is one posting, a row split into lines one per line
     * ({@see Transaction::scopeJoinPostingLines()}). Keyed by user, $bucketSql
     * and currency.
     *
     * @param  list<string>  $userIds
     * @param  callable(Builder<Transaction>): Builder<Transaction>  $join
     * @return array<string, int>
     */
    private function postingTotals(array $userIds, callable $join, string $bucketSql): array
    {
        $query = $join(Transaction::query()->whereIn('transactions.user_id', $userIds)->joinPostingLines());

        $rows = $query->toBase()
            ->selectRaw("concat_ws('|', transactions.user_id, {$bucketSql}, coalesce(transactions.currency_code, '-')) as bucket")
            ->selectRaw('sum('.Transaction::POSTING_AMOUNT_SQL.') as total, count(*) as postings')
            ->groupBy('bucket')
            ->get();

        return $rows->flatMap(fn (object $row): array => [
            "{$row->bucket} sum" => (int) $row->total,
            "{$row->bucket} count" => (int) $row->postings,
        ])->all();
    }

    /**
     * Every budget period holding a row of these transactions, whoever owns
     * its budget: a split moves every row of the transaction, not only the
     * ones its owner's budgets hold.
     *
     * @param  list<string>  $transactionIds
     * @return list<string>
     */
    public function budgetPeriodsHolding(array $transactionIds): array
    {
        return collect($transactionIds)
            ->chunk(1000)
            ->flatMap(fn (Collection $ids) => DB::table('budget_transactions')->whereIn('transaction_id', $ids->all())->distinct()->pluck('budget_period_id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * What every period of the users' budgets, and every period listed, has
     * spent, archived budgets and deleted ones included: the plain sum of its
     * rows, as the budget screens read it.
     *
     * @param  list<string>  $userIds
     * @param  list<string>  $budgetPeriodIds
     * @return array<string, int>
     */
    private function budgetPeriodTotals(array $userIds, array $budgetPeriodIds): array
    {
        return DB::table('budget_periods')
            ->join('budgets', 'budgets.id', '=', 'budget_periods.budget_id')
            ->leftJoin('budget_transactions', 'budget_transactions.budget_period_id', '=', 'budget_periods.id')
            ->where(fn (QueryBuilder $query) => $query->whereIn('budgets.user_id', $userIds)->orWhereIn('budget_periods.id', $budgetPeriodIds))
            ->groupBy('budget_periods.id')
            ->selectRaw('budget_periods.id as bucket, coalesce(sum(budget_transactions.amount), 0) as spent')
            ->pluck('spent', 'bucket')
            ->map(fn (mixed $spent): int => (int) $spent)
            ->all();
    }

    /**
     * Per account, day and source, the live amounts {@see BalanceSyncService}
     * adds up into a connected account's balance history.
     *
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    private function accountDayTotals(array $userIds): array
    {
        return Transaction::query()
            ->whereIn('user_id', $userIds)
            ->toBase()
            ->selectRaw("concat_ws('|', account_id, coalesce(source_date, transaction_date), source) as bucket, sum(amount) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Each user's cashflow summary of every month listed, in the user's
     * currency, as the cashflow screen shows it.
     *
     * @param  array<string, list<string>>  $monthsByUser
     * @return array<string, int> keyed by `user id|Y-m|figure`
     */
    private function monthlyCashflow(array $monthsByUser): array
    {
        $figures = [];

        foreach (User::query()->whereKey(array_keys($monthsByUser))->get() as $user) {
            foreach ($monthsByUser[$user->id] as $month) {
                $start = Carbon::parse("{$month}-01")->startOfMonth();
                $summary = $this->cashflow->summary($user, new PeriodComparator($start, $start->copy()->endOfMonth()))['current'];

                foreach (self::CASHFLOW_FIGURES as $figure) {
                    $figures["{$user->id}|{$month}|{$figure}"] = (int) $summary[$figure];
                }
            }
        }

        return $figures;
    }
}
