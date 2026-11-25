<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Services\BudgetTransactionService;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Moves the budget rows of a split between its original and its parts, never
 * changing what any period has spent.
 *
 * A budget row is a snapshot (transaction, period, amount) and a period's
 * spent is the plain sum of its rows, read without the transaction. A closed
 * period keeps rows written under the tracking it ran on, and an archived
 * budget takes no new rows at all, so rows that a split or a merge would
 * otherwise drop with the row they hang from cannot be rebuilt from what the
 * budget tracks today: they are moved instead.
 *
 * @phpstan-type FallbackRow array{transaction_id: string, budget_period_id: string}
 * @phpstan-type MovedRows array{moved: int, fallback: list<FallbackRow>}
 */
class SplitBudgetRows
{
    public function __construct(
        private readonly BudgetTransactionService $budgets,
        private readonly CategoryTree $tree,
    ) {}

    /**
     * Share each row the original holds among its parts and take it off the
     * original. A row goes where the budget, as it tracks today, would put it
     * reading each part as a posting of its own carrying the original's
     * labels. When those shares do not add up to the row (tracking changed
     * since, ownership rounded once for the whole row, an archived budget),
     * the row is shared by part amount instead, the residue on the last part,
     * and listed under `fallback`.
     *
     * @param  Collection<int, Transaction>  $parts  in the order they were made
     * @param  bool  $archivedOnly  only the rows of archived budgets
     * @return MovedRows
     */
    public function toParts(Transaction $original, Collection $parts, bool $archivedOnly = false): array
    {
        $rows = $this->rowsOf([$original->id], $archivedOnly);

        if ($rows->isEmpty()) {
            return ['moved' => 0, 'fallback' => []];
        }

        $parts = $parts->values();
        $standIns = $this->standIns($original, $parts);
        $lookups = new BudgetAssignmentLookups($this->tree);
        $amounts = [];
        $fallback = [];

        foreach ($rows as $row) {
            $shares = $this->explainedShares($row, $standIns, $lookups);

            if (array_sum($shares) !== $row->amount) {
                $shares = self::proportionalShares($row->amount, $parts);
                $fallback[] = ['transaction_id' => $original->id, 'budget_period_id' => $row->budget_period_id];
            }

            foreach ($parts as $position => $part) {
                $amounts[$part->id][$row->budget_period_id] = $shares[$position];
            }
        }

        $this->replace($rows, $amounts);

        return ['moved' => $rows->count(), 'fallback' => $fallback];
    }

    /**
     * Put the rows the parts hold back on the original: one row per period,
     * the sum of the parts' rows there.
     *
     * @param  Collection<int, Transaction>  $parts
     * @param  bool  $archivedOnly  only the rows of archived budgets
     * @return int the rows taken off the parts
     */
    public function toOriginal(Transaction $original, Collection $parts, bool $archivedOnly = false): int
    {
        $rows = $this->rowsOf($parts->pluck('id')->all(), $archivedOnly);
        $sums = $rows->groupBy('budget_period_id')->map(fn (Collection $held): int => (int) $held->sum('amount'));

        $this->replace($rows, [$original->id => $sums->all()]);

        return $rows->count();
    }

    /**
     * @param  array<int, string>  $transactionIds
     * @return EloquentCollection<int, BudgetTransaction>
     */
    private function rowsOf(array $transactionIds, bool $archivedOnly): EloquentCollection
    {
        return BudgetTransaction::query()
            ->whereIn('transaction_id', $transactionIds)
            ->when($archivedOnly, fn (Builder $query) => $query->whereIn(
                'budget_period_id',
                BudgetPeriod::query()->select('id')->whereIn('budget_id', Budget::withTrashed()->select('id')->whereNotNull('archived_at')),
            ))
            ->with(['budgetPeriod.budget' => fn ($budget) => $budget->withTrashed()->with(['categories:id', 'labels:id'])])
            ->orderBy('budget_period_id')
            ->get();
    }

    /**
     * Each part as the budget would read it on its own: its amount and
     * category, the original's labels and account.
     *
     * @param  Collection<int, Transaction>  $parts
     * @return list<Transaction>
     */
    private function standIns(Transaction $original, Collection $parts): array
    {
        $labels = $original->labels()->get();
        // With a trashed one, as a budget weighs it (BudgetTransactionService::ownedAmount()).
        $account = $original->account()->withTrashed()->first();

        return $parts->map(function (Transaction $part) use ($account, $labels): Transaction {
            $standIn = clone $part;

            return $standIn
                ->setRelation('labels', $labels)
                ->setRelation('splits', new EloquentCollection)
                ->setRelation('account', $account);
        })->all();
    }

    /**
     * @param  list<Transaction>  $standIns
     * @return list<int>
     */
    private function explainedShares(BudgetTransaction $row, array $standIns, BudgetAssignmentLookups $lookups): array
    {
        $budget = $row->budgetPeriod?->budget;

        if ($budget === null) {
            return array_fill(0, count($standIns), 0);
        }

        return array_map(fn (Transaction $standIn): int => $this->budgets->amountForBudget($standIn, $budget, $lookups), $standIns);
    }

    /**
     * $amount shared by part amount, rounded towards zero, the residue on the
     * last part so the shares add up to $amount exactly.
     *
     * @param  Collection<int, Transaction>  $parts
     * @return list<int>
     */
    private static function proportionalShares(int $amount, Collection $parts): array
    {
        $total = (int) $parts->sum('amount');
        $last = $parts->count() - 1;
        $shares = [];

        foreach ($parts->values() as $position => $part) {
            $shares[] = $position === $last || $total === 0
                ? 0
                : intdiv($amount * $part->amount, $total);
        }

        $shares[$last] = $amount - array_sum($shares);

        return $shares;
    }

    /**
     * Delete $rows and add $amounts (transaction id => period id => amount) to
     * whatever those transactions already hold in those periods. A zero
     * amount writes nothing.
     *
     * @param  EloquentCollection<int, BudgetTransaction>  $rows
     * @param  array<string, array<string, int>>  $amounts
     */
    private function replace(EloquentCollection $rows, array $amounts): void
    {
        BudgetTransaction::query()->whereKey($rows->modelKeys())->delete();

        $existing = BudgetTransaction::query()
            ->whereIn('transaction_id', array_keys($amounts))
            ->get()
            ->mapWithKeys(fn (BudgetTransaction $row): array => ["{$row->transaction_id}|{$row->budget_period_id}" => $row->amount]);

        $upserts = [];

        foreach ($amounts as $transactionId => $byPeriod) {
            foreach (array_filter($byPeriod) as $periodId => $amount) {
                $upserts[] = [
                    'transaction_id' => $transactionId,
                    'budget_period_id' => $periodId,
                    'amount' => $amount + (int) $existing->get("{$transactionId}|{$periodId}", 0),
                ];
            }
        }

        BudgetTransaction::upsert($upserts, ['transaction_id', 'budget_period_id'], ['amount']);
    }
}
