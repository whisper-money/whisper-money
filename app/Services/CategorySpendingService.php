<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategorySpendingService
{
    public function __construct(private CategoryTree $tree) {}

    /**
     * Expense spending rolled up the category tree.
     *
     * Without a drill target, child category amounts fold into their root
     * ancestor so only parents are listed. With one, the parent's children
     * become the rows (plus a direct node for transactions sitting on the
     * parent itself). Soft-deleted categories are excluded.
     */
    public function forPeriod(string $userId, Carbon $from, Carbon $to, ?string $drillParentId = null): Collection
    {
        $perCategory = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Expense)
                    ->whereNull('categories.deleted_at');
            })
            ->select('transactions.category_id', DB::raw('sum(transactions.amount) as total_amount'))
            ->groupBy('transactions.category_id')
            ->get()
            ->map(fn ($item): array => [
                'category_id' => $item->category_id,
                'category' => null,
                'amount' => (int) -$item->total_amount,
            ])
            ->values()
            ->all();

        return collect($this->tree->rollUp($perCategory, $userId, $drillParentId))
            ->filter(fn (array $item): bool => $item['amount'] > 0)
            ->values();
    }
}
