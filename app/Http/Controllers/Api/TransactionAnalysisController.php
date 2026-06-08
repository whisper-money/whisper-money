<?php

namespace App\Http\Controllers\Api;

use App\Features\TransactionAnalysis;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTransactionRequest;
use App\Models\Transaction;
use App\Services\CategoryTree;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

class TransactionAnalysisController extends Controller
{
    /**
     * A daily breakdown is used while the filtered set spans this many days or
     * fewer; beyond that the chart switches to monthly buckets.
     */
    private const DAILY_BUCKET_MAX_DAYS = 62;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private CategoryTree $tree,
    ) {}

    public function summary(IndexTransactionRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(Feature::for($user)->active(TransactionAnalysis::class), 403);

        $validated = $request->validated();
        $currency = $user->currency_code;

        $filters = array_filter([
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'amount_min' => $validated['amount_min'] ?? null,
            'amount_max' => $validated['amount_max'] ?? null,
            'category_ids' => $validated['category_ids'] ?? null,
            'account_ids' => $validated['account_ids'] ?? null,
            'label_ids' => $validated['label_ids'] ?? null,
            'creditor_name' => $validated['creditor_name'] ?? null,
            'debtor_name' => $validated['debtor_name'] ?? null,
            'search' => $validated['search'] ?? null,
        ], fn ($value) => $value !== null);

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->with(['account', 'category', 'labels'])
            ->applyFilters($filters)
            ->get();

        $this->preloadExchangeRates($transactions, $currency);

        $byCategory = $this->categoryBreakdown($transactions, $currency, $user->id);
        $byTag = $this->tagBreakdown($transactions, $currency);

        return response()
            ->json([
                'currency' => $currency,
                'summary' => $this->summaryTotals($transactions, $currency),
                'by_category' => $byCategory->values(),
                'distinct_category_count' => $byCategory->count(),
                'by_tag' => $byTag->values(),
                'distinct_label_count' => $byTag->count(),
                'over_time' => $this->overTime($transactions, $currency),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array{income: int, expense: int, net: int, count: int, days: int, average_expense_per_day: int}
     */
    private function summaryTotals(Collection $transactions, string $currency): array
    {
        $income = 0;
        $expense = 0;

        foreach ($transactions as $transaction) {
            $amount = $this->convertTransactionAmount($transaction, $currency);

            if ($amount > 0) {
                $income += $amount;
            } else {
                $expense += abs($amount);
            }
        }

        $days = $this->spanInDays($transactions);

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'count' => $transactions->count(),
            'days' => $days,
            'average_expense_per_day' => $days > 0 ? intdiv($expense, $days) : $expense,
        ];
    }

    /**
     * Expenses grouped by their top-level category, rolled up through the
     * category tree so parents absorb their children's spending.
     */
    private function categoryBreakdown(Collection $transactions, string $currency, string $userId): Collection
    {
        $expenses = $transactions->filter(
            fn (Transaction $transaction): bool => $this->convertTransactionAmount($transaction, $currency) < 0,
        );

        $grouped = $expenses
            ->filter(fn (Transaction $transaction): bool => $transaction->category_id !== null)
            ->groupBy('category_id')
            ->map(function (Collection $group) use ($currency): array {
                $first = $group->first();

                return [
                    'category_id' => $first->category_id,
                    'category' => $first->category,
                    'amount' => abs($group->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $currency))),
                ];
            })
            ->values()
            ->all();

        $breakdown = collect($this->tree->rollUp($grouped, $userId, null))
            ->map(fn (array $node): array => [
                'category_id' => $node['category_id'],
                'name' => $node['category']->name,
                'color' => $node['category']->color,
                'amount' => $node['amount'],
            ]);

        $uncategorized = abs($expenses
            ->filter(fn (Transaction $transaction): bool => $transaction->category_id === null)
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $currency)));

        if ($uncategorized > 0) {
            $breakdown->push([
                'category_id' => null,
                'name' => __('Uncategorized'),
                'color' => 'gray',
                'amount' => $uncategorized,
            ]);
        }

        return $breakdown
            ->filter(fn (array $node): bool => $node['amount'] > 0)
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * Spending grouped by label. A transaction contributes to every label
     * attached to it, so the totals can exceed overall expenses.
     */
    private function tagBreakdown(Collection $transactions, string $currency): Collection
    {
        $totals = [];

        foreach ($transactions as $transaction) {
            $amount = $this->convertTransactionAmount($transaction, $currency);

            if ($amount >= 0) {
                continue;
            }

            foreach ($transaction->labels as $label) {
                $totals[$label->id] ??= ['id' => $label->id, 'name' => $label->name, 'color' => $label->color, 'amount' => 0];
                $totals[$label->id]['amount'] += abs($amount);
            }
        }

        return collect($totals)
            ->filter(fn (array $tag): bool => $tag['amount'] > 0)
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * Income and expense bucketed over the filtered span, plus a running
     * expense total so the pace of spending is visible.
     *
     * @return array{bucket: string, points: array<int, array{date: string, label: string, income: int, expense: int, cumulative_expense: int}>}
     */
    private function overTime(Collection $transactions, string $currency): array
    {
        if ($transactions->isEmpty()) {
            return ['bucket' => 'day', 'points' => []];
        }

        $dates = $transactions->map(fn (Transaction $transaction): Carbon => $transaction->transaction_date->copy());
        $start = $dates->min();
        $end = $dates->max();

        $daily = $start->diffInDays($end) <= self::DAILY_BUCKET_MAX_DAYS;
        $keyFormat = $daily ? 'Y-m-d' : 'Y-m';

        $buckets = [];
        foreach ($transactions as $transaction) {
            $key = $transaction->transaction_date->format($keyFormat);
            $amount = $this->convertTransactionAmount($transaction, $currency);
            $buckets[$key] ??= ['income' => 0, 'expense' => 0];

            if ($amount > 0) {
                $buckets[$key]['income'] += $amount;
            } else {
                $buckets[$key]['expense'] += abs($amount);
            }
        }

        $points = [];
        $cumulative = 0;
        $cursor = $daily ? $start->copy()->startOfDay() : $start->copy()->startOfMonth();
        $last = $daily ? $end->copy()->startOfDay() : $end->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $key = $cursor->format($keyFormat);
            $income = $buckets[$key]['income'] ?? 0;
            $expense = $buckets[$key]['expense'] ?? 0;
            $cumulative += $expense;

            $points[] = [
                'date' => $key,
                'label' => $daily ? $cursor->format('M j') : $cursor->format('M Y'),
                'income' => $income,
                'expense' => $expense,
                'cumulative_expense' => $cumulative,
            ];

            $daily ? $cursor->addDay() : $cursor->addMonth();
        }

        return ['bucket' => $daily ? 'day' : 'month', 'points' => $points];
    }

    private function spanInDays(Collection $transactions): int
    {
        if ($transactions->isEmpty()) {
            return 0;
        }

        $dates = $transactions->map(fn (Transaction $transaction): Carbon => $transaction->transaction_date);

        return (int) $dates->min()->diffInDays($dates->max()) + 1;
    }

    private function convertTransactionAmount(Transaction $transaction, string $currency): int
    {
        return $this->exchangeRateService->convert(
            $transaction->currency_code ?: $transaction->account?->currency_code ?: $currency,
            $currency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );
    }

    private function preloadExchangeRates(Collection $transactions, string $currency): void
    {
        $dates = $transactions
            ->filter(fn (Transaction $transaction): bool => strcasecmp($transaction->currency_code ?: $transaction->account?->currency_code ?: $currency, $currency) !== 0)
            ->map(fn (Transaction $transaction): string => $transaction->transaction_date->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $this->exchangeRateService->preloadRates($currency, $dates);
    }
}
