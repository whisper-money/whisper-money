<?php

namespace App\Services\Forecast;

use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads the past the forecast learns from: a user's own transactions in one
 * space, as they count towards totals (an archived account stops on the day
 * it was archived). A split original is soft-deleted, so only its parts are
 * read. Rows come oldest first, with their category and account loaded.
 *
 * Categories belong to the user rather than to a space, so the category
 * tree is read user-wide.
 */
class ForecastHistory
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * @param  list<string>|null  $accountIds  Null reads every account.
     * @return Collection<int, Transaction>
     */
    public function transactions(User $user, string $spaceId, string $from, string $to, ?array $accountIds = null): Collection
    {
        return $this->query($user, $spaceId, $from, $to, $accountIds)->get();
    }

    /**
     * Like transactions(), leaving out what already paid a forecast line or
     * the user marked a one-off.
     *
     * @param  list<string>|null  $accountIds  Null reads every account.
     * @return Collection<int, Transaction>
     */
    public function unplannedTransactions(User $user, string $spaceId, string $from, string $to, ?array $accountIds = null): Collection
    {
        return $this->query($user, $spaceId, $from, $to, $accountIds)
            ->whereNotIn('transactions.id', ForecastMatch::query()->select('transaction_id'))
            ->whereNotIn('transactions.id', ForecastExcludedTransaction::query()->select('transaction_id'))
            ->get();
    }

    /**
     * Like transactions(), leaving out what the category estimates must not
     * count: what paid a line the forecast still projects (it is planned on
     * its own) and what the user marked a one-off. What paid a detected
     * recurring line counts, since those lines no longer project.
     *
     * @param  list<string>|null  $accountIds  Null reads every account.
     * @return Collection<int, Transaction>
     */
    public function estimableTransactions(User $user, string $spaceId, string $from, string $to, ?array $accountIds = null): Collection
    {
        return $this->query($user, $spaceId, $from, $to, $accountIds)
            ->whereNotIn('transactions.id', ForecastMatch::query()
                ->whereIn('forecast_item_id', ForecastItem::query()->where('user_id', $user->id)->projecting()->select('id'))
                ->select('transaction_id'))
            ->whereNotIn('transactions.id', ForecastExcludedTransaction::query()->select('transaction_id'))
            ->get();
    }

    /**
     * The date of each account's first transaction of the user, whatever
     * the space; accounts without any are left out.
     *
     * @param  list<string>  $accountIds
     * @return array<string, string> `Y-m-d` by account id.
     */
    public function firstTransactionDates(User $user, array $accountIds): array
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('account_id', $accountIds)
            ->groupBy('account_id')
            ->selectRaw('account_id, min(transaction_date) as first_date')
            ->pluck('first_date', 'account_id')
            ->map(fn (mixed $date): string => substr((string) $date, 0, 10))
            ->all();
    }

    /**
     * @param  list<string>|null  $accountIds
     * @return Builder<Transaction>
     */
    private function query(User $user, string $spaceId, string $from, string $to, ?array $accountIds): Builder
    {
        return Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->forSpace($spaceId)
            ->transactionDateWithin($from, $to)
            ->countingTowardsTotals()
            ->when($accountIds !== null, fn (Builder $query) => $query->whereIn('transactions.account_id', $accountIds))
            ->with(['category:id,parent_id,type', 'account:id,type,currency_code,ownership_percentage'])
            ->orderBy('transactions.transaction_date')
            ->orderBy('transactions.id');
    }

    /**
     * Each of the user's categories pointing at its top-level ancestor (a
     * top-level category points at itself).
     *
     * @return array<string, string>
     */
    public function parentCategories(User $user): array
    {
        $parentMap = $this->tree->parentMap($user->id);
        $roots = [];

        foreach (array_keys($parentMap) as $categoryId) {
            $chain = $this->tree->ancestorAndSelfIds($user->id, $categoryId, $parentMap);
            $roots[$categoryId] = end($chain);
        }

        return $roots;
    }
}
