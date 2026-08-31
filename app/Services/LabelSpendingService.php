<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Enums\LabelSource;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LabelSpendingService
{
    private const LIMIT = 10;

    /**
     * The labels the user spent the most on during the period, biggest first,
     * each with the same figure for the preceding period so the widget can show
     * a trend. Empty when nothing labelled was spent — the dashboard then draws
     * no card at all.
     *
     * @return list<array{id: string, name: string, color: string, amount: int, previous_amount: int, total_amount: int}>
     */
    public function topForPeriod(string $userId, PeriodComparator $period): array
    {
        $current = $this->forPeriod($userId, $period->from, $period->to);

        if ($current->isEmpty()) {
            return [];
        }

        $previousPeriod = $period->previous();
        $previous = $this->forPeriod($userId, $previousPeriod->from, $previousPeriod->to);
        $totalAmount = $current->sum('amount');

        return $current
            ->sortByDesc('amount')
            ->take(self::LIMIT)
            ->map(fn (array $label): array => [
                ...$label,
                'previous_amount' => $previous[$label['id']]['amount'] ?? 0,
                'total_amount' => $totalAmount,
            ])
            ->values()
            ->all();
    }

    /**
     * Expense spending per label over a period, keyed by label id.
     *
     * Amounts are ownership-weighted and leave out what an archived account no
     * longer contributes, matching {@see CategorySpendingService::forPeriod()};
     * amounts are summed as they are stored, with no currency conversion, so
     * both dashboard cards read the same money. Labels backing a savings goal
     * are skipped: they track contributions, not spending.
     *
     * A transaction counts towards every label attached to it, so the totals can
     * add up to more than the period's expenses.
     *
     * @return Collection<string, array{id: string, name: string, color: string, amount: int}>
     */
    private function forPeriod(string $userId, Carbon $from, Carbon $to): Collection
    {
        return Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->joinOwningAccount()
            ->withoutArchivedAccountActivity()
            ->join('label_transaction', 'label_transaction.transaction_id', '=', 'transactions.id')
            ->join('labels', function (JoinClause $join): void {
                $join->on('labels.id', '=', 'label_transaction.label_id')
                    ->where('labels.source', '=', LabelSource::User)
                    ->whereNull('labels.deleted_at');
            })
            ->leftJoin('categories', function (JoinClause $join): void {
                $join->on('categories.id', '=', 'transactions.category_id')
                    ->whereNull('categories.deleted_at');
            })
            ->where($this->expenseSide(...))
            ->groupBy('labels.id', 'labels.name', 'labels.color')
            ->select('labels.id', 'labels.name', 'labels.color', DB::raw('sum('.Transaction::OWNED_AMOUNT_SQL.') as total_amount'))
            ->toBase()
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'color' => $row->color,
                'amount' => (int) -$row->total_amount,
            ])
            ->filter(fn (array $label): bool => $label['amount'] > 0)
            ->keyBy('id');
    }

    /**
     * {@see Transaction::isExpenseSide()} expressed in SQL: an expense category,
     * or money leaving with no category at all.
     */
    private function expenseSide(Builder $query): void
    {
        $query->where('categories.type', CategoryType::Expense)
            ->orWhere(function (Builder $uncategorized): void {
                $uncategorized->whereNull('transactions.category_id')
                    ->where('transactions.amount', '<', 0);
            });
    }
}
