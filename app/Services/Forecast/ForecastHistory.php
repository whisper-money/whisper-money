<?php

namespace App\Services\Forecast;

use App\Enums\CategoryType;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Support\Collection;

/**
 * Reads the past the forecast learns from: a user's own transactions in one
 * space, as they count towards totals (an archived account stops on the day
 * it was archived). A split original is soft-deleted, so only its parts are
 * read. Rows come oldest first, with their category and account loaded.
 */
class ForecastHistory
{
    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * @param  list<string>|null  $accountIds  Null reads every account.
     * @param  bool  $onlyUnplanned  Leave out what already paid a forecast line or was marked a one-off.
     * @return Collection<int, Transaction>
     */
    public function transactions(User $user, string $spaceId, string $from, string $to, ?array $accountIds = null, bool $onlyUnplanned = false): Collection
    {
        return Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->forSpace($spaceId)
            ->transactionDateWithin($from, $to)
            ->countingTowardsTotals()
            ->when($accountIds !== null, fn ($query) => $query->whereIn('transactions.account_id', $accountIds))
            ->when($onlyUnplanned, fn ($query) => $query
                ->whereNotIn('transactions.id', ForecastMatch::query()->select('transaction_id'))
                ->whereNotIn('transactions.id', ForecastExcludedTransaction::query()->select('transaction_id')))
            ->with(['category:id,parent_id,type', 'account:id,currency_code,ownership_percentage'])
            ->orderBy('transactions.transaction_date')
            ->orderBy('transactions.id')
            ->get();
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

    /**
     * Spend the forecast estimates: what an expense category holds (refunds
     * included, they lower it) and what is uncategorized and leaves the
     * account. The same split as Transaction::scopeExpenseSide().
     */
    public static function isSpend(Transaction $transaction): bool
    {
        if ($transaction->category === null) {
            return $transaction->amount < 0;
        }

        return $transaction->category->type === CategoryType::Expense;
    }
}
