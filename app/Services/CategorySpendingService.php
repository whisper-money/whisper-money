<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategorySpendingService
{
    /** How many categories {@see self::comparedExpense()} ranks. */
    private const TOP_CATEGORIES = 10;

    public function __construct(
        private CategoryTree $tree,
        private CashflowAggregateQuery $aggregates,
    ) {}

    /**
     * Expense spending rolled up the category tree.
     *
     * Without a drill target, child category amounts fold into their root
     * ancestor so only parents are listed. With one, the parent's children
     * become the rows (plus a direct node for transactions sitting on the
     * parent itself). Soft-deleted categories are excluded, as is what an
     * archived account no longer contributes
     * ({@see Transaction::scopeWithoutArchivedAccountActivity()}).
     */
    public function forPeriod(string $userId, Carbon $from, Carbon $to, ?string $drillParentId = null): Collection
    {
        $perCategory = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->joinOwningAccount()
            ->withoutArchivedAccountActivity()
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Expense)
                    ->whereNull('categories.deleted_at');
            })
            ->select('transactions.category_id', DB::raw('sum('.Transaction::OWNED_AMOUNT_SQL.') as total_amount'))
            ->groupBy('transactions.category_id')
            ->get()
            ->map(fn ($item): array => [
                'category_id' => $item->category_id,
                'category' => null,
                'amount' => (int) -$item->total_amount,
            ])
            ->values()
            ->all();

        return $this->spentCategories($perCategory, $userId, $drillParentId);
    }

    /**
     * A period's expense side the way the cashflow card adds it up
     * ({@see CashflowSummaryService::forComparedPeriods()}): in the user's
     * currency, at the owner's share, without what an archived account no
     * longer contributes, refunds netted. Onboarding estimates are left out:
     * they are round guesses, not spending.
     *
     * Categories are ranked and compared with the period before. Spending with
     * no category is its own figure, so the rows and `uncategorized` add up to
     * `total`, the cashflow expense, unless a category nets below zero or falls
     * outside the top ten. When drilling into a parent, `total` is what its
     * subtree spent and `uncategorized` is 0: a parent has no uncategorized
     * children.
     *
     * @return array{
     *     categories: list<array{category: mixed, category_id: ?string, amount: int, previous_amount: int, total_amount: int, has_children: bool, is_direct: bool}>,
     *     uncategorized: int,
     *     total: int,
     * }
     */
    public function comparedExpense(User $user, PeriodComparator $period, ?string $drillParentId = null): array
    {
        $previousPeriod = $period->previous();

        $aggregates = $this->aggregates->aggregateByPeriod(
            $this->measuredTransactions($user->id)
                ->whereBetween('transactions.transaction_date', [$previousPeriod->from, $period->to]),
            $user->currency_code,
            ['current' => $period, 'previous' => $previousPeriod],
        );

        $current = $this->expenseSide($aggregates['current']);
        $categories = $this->spentCategories($this->perCategory($current), $user->id, $drillParentId);
        $previous = $this->spentCategories($this->perCategory($this->expenseSide($aggregates['previous'])), $user->id, $drillParentId);

        [$uncategorized, $total] = $drillParentId === null
            ? [-CashflowAggregate::total($current->filter(fn (CashflowAggregate $aggregate): bool => $aggregate->transaction->category_id === null)), -CashflowAggregate::total($current)]
            : [0, (int) $categories->sum('amount')];

        return [
            'categories' => $categories
                ->sortByDesc('amount')
                ->take(self::TOP_CATEGORIES)
                ->map(fn (array $item): array => [
                    'category' => $item['category'],
                    'category_id' => $item['category_id'],
                    'amount' => $item['amount'],
                    'previous_amount' => $previous->firstWhere('category_id', $item['category_id'])['amount'] ?? 0,
                    'total_amount' => $total,
                    'has_children' => $item['has_children'],
                    'is_direct' => $item['is_direct'],
                ])
                ->values()
                ->all(),
            'uncategorized' => $uncategorized,
            'total' => $total,
        ];
    }

    /**
     * How many movements make up {@see self::comparedExpense()}'s
     * `uncategorized` figure: outflows with no category, the uncategorized
     * half of {@see Transaction::isExpenseSide()}, counted by the same rules.
     */
    public function uncategorizedExpenseCount(User $user, PeriodComparator $period): int
    {
        return $this->measuredTransactions($user->id)
            ->whereBetween('transactions.transaction_date', [$period->from, $period->to])
            ->countingTowardsTotals()
            ->whereNull('transactions.category_id')
            ->where('transactions.amount', '<', 0)
            ->count();
    }

    /**
     * Whether the period holds onboarding estimates, which
     * {@see self::comparedExpense()} leaves out and the cashflow card counts:
     * while it does, the two totals differ.
     */
    public function hasEstimates(User $user, PeriodComparator $period): bool
    {
        return Transaction::query()
            ->where('transactions.user_id', $user->id)
            ->whereBetween('transactions.transaction_date', [$period->from, $period->to])
            ->estimates()
            ->exists();
    }

    /**
     * @return Builder<Transaction>
     */
    private function measuredTransactions(string $userId): Builder
    {
        return Transaction::query()
            ->where('transactions.user_id', $userId)
            ->withoutEstimates();
    }

    /**
     * @param  Collection<int, CashflowAggregate>  $aggregates
     * @return Collection<int, CashflowAggregate>
     */
    private function expenseSide(Collection $aggregates): Collection
    {
        return $aggregates->filter(fn (CashflowAggregate $aggregate): bool => $aggregate->transaction->isExpenseSide());
    }

    /**
     * @param  Collection<int, CashflowAggregate>  $aggregates
     * @return array<int, array{category_id: string, category: null, amount: int}>
     */
    private function perCategory(Collection $aggregates): array
    {
        return $aggregates
            ->filter(fn (CashflowAggregate $aggregate): bool => $aggregate->transaction->category_id !== null)
            ->groupBy(fn (CashflowAggregate $aggregate): string => $aggregate->transaction->category_id)
            ->map(fn (Collection $group, string $categoryId): array => [
                'category_id' => $categoryId,
                'category' => null,
                'amount' => -CashflowAggregate::total($group),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{category_id: ?string, category: mixed, amount: int}>  $perCategory
     */
    private function spentCategories(array $perCategory, string $userId, ?string $drillParentId): Collection
    {
        // Positives only, on purpose: this ranks where the money went, and a
        // category that a refund nets below zero has no place in a "top
        // spending" list. The cashflow screen is the surface that nets.
        return collect($this->tree->rollUp($perCategory, $userId, $drillParentId))
            ->filter(fn (array $item): bool => $item['amount'] > 0)
            ->values();
    }
}
