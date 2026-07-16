<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ConvertsTransactionCurrency;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexTransactionRequest;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\CategoryTree;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class TransactionAnalysisController extends Controller
{
    use ConvertsTransactionCurrency;

    /**
     * A daily breakdown is used while the filtered set spans this many days or
     * fewer; beyond that the chart switches to monthly buckets.
     */
    private const DAILY_BUCKET_MAX_DAYS = 62;

    /**
     * The drawer lists the five biggest expenses with an option to reveal the
     * rest, so ten covers both states without shipping the whole set.
     */
    private const LARGEST_EXPENSES_LIMIT = 10;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private CategoryTree $tree,
    ) {}

    public function summary(IndexTransactionRequest $request): JsonResponse
    {
        $user = $request->user();
        $space = $user->activeSpace();

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

        $transactions = $space->transactions()
            ->with(['account.bank', 'category', 'labels'])
            ->applyFilters($filters)
            ->get();

        $this->preloadExchangeRates($transactions, $currency);

        $byCategory = $this->categoryBreakdown($transactions, $currency, $space->id);
        $byTag = $this->tagBreakdown($transactions, $currency);
        $byPayee = $this->payeeBreakdown($transactions, $currency);
        $byAccount = $this->accountBreakdown($transactions, $currency);

        return response()
            ->json([
                'currency' => $currency,
                'summary' => $this->summaryTotals($transactions, $currency),
                'by_category' => $byCategory->values(),
                'distinct_category_count' => $byCategory->count(),
                'by_tag' => $byTag->values(),
                'distinct_label_count' => $byTag->count(),
                'by_payee' => $byPayee->values(),
                'distinct_payee_count' => $byPayee->count(),
                'by_account' => $byAccount->values(),
                'distinct_account_count' => $byAccount->count(),
                'largest_expenses' => $this->largestExpenses($transactions, $currency),
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
            if ($transaction->isIncomeSide()) {
                $income += $this->convertTransactionAmount($transaction, $currency);
            } elseif ($transaction->isExpenseSide()) {
                $expense += $this->convertTransactionAmount($transaction, $currency);
            }
        }

        // Refunds net against their side before it is clamped, so a credit in
        // an expense category lowers spending instead of inflating income —
        // matching how the cashflow screen reconciles the same transactions.
        $income = max(0, $income);
        $expense = max(0, -$expense);

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
     * Expenses grouped by their top-level category, with the sub-categories
     * that carry spending nested beneath each parent so the split is visible
     * instead of folded into the parent total.
     */
    private function categoryBreakdown(Collection $transactions, string $currency, string $spaceId): Collection
    {
        $expenses = $transactions->filter(
            fn (Transaction $transaction): bool => $transaction->isExpenseSide(),
        );

        $grouped = $expenses
            ->filter(fn (Transaction $transaction): bool => $transaction->category_id !== null)
            ->groupBy('category_id')
            ->map(fn (Collection $group): array => [
                'category_id' => $group->first()->category_id,
                'amount' => -$group->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $currency)),
            ])
            ->filter(fn (array $node): bool => $node['amount'] > 0)
            ->values()
            ->all();

        $rows = array_map(fn (array $node): array => [
            'category_id' => $node['category_id'],
            'name' => $node['category']->name,
            'color' => $node['category']->color,
            'icon' => $node['category']->icon,
            'amount' => $node['amount'],
            'children' => array_map(fn (array $child): array => [
                'category_id' => $child['category_id'],
                'name' => $child['category']->name,
                'color' => $child['category']->color,
                'icon' => $child['category']->icon,
                'amount' => $child['amount'],
            ], $node['children']),
        ], $this->tree->spendingBreakdown($grouped, $spaceId));

        $uncategorized = -$expenses
            ->filter(fn (Transaction $transaction): bool => $transaction->category_id === null)
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $currency));

        if ($uncategorized > 0) {
            $rows[] = [
                'category_id' => null,
                'name' => __('Uncategorized'),
                'color' => 'gray',
                'icon' => 'HelpCircle',
                'amount' => $uncategorized,
                'children' => [],
            ];
        }

        return collect($rows)
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
            if (! $transaction->isExpenseSide()) {
                continue;
            }

            $amount = -$this->convertTransactionAmount($transaction, $currency);

            foreach ($transaction->labels as $label) {
                $totals[$label->id] ??= ['id' => $label->id, 'name' => $label->name, 'color' => $label->color, 'amount' => 0];
                $totals[$label->id]['amount'] += $amount;
            }
        }

        return collect($totals)
            ->filter(fn (array $tag): bool => $tag['amount'] > 0)
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * Expenses grouped by the party paid (the creditor on the transaction).
     * Transactions without a named creditor are skipped, since an unnamed
     * bucket carries no meaning for the user.
     */
    private function payeeBreakdown(Collection $transactions, string $currency): Collection
    {
        $totals = [];

        foreach ($transactions as $transaction) {
            if (! $transaction->isExpenseSide()) {
                continue;
            }

            $amount = -$this->convertTransactionAmount($transaction, $currency);

            $name = trim((string) $transaction->creditor_name);

            if ($name === '') {
                continue;
            }

            $totals[$name] ??= ['name' => $name, 'amount' => 0];
            $totals[$name]['amount'] += $amount;
        }

        return collect($totals)
            ->filter(fn (array $payee): bool => $payee['amount'] > 0)
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * Expenses grouped by the account that funded them, so a set spanning
     * several cards shows where the spending was charged.
     */
    private function accountBreakdown(Collection $transactions, string $currency): Collection
    {
        $totals = [];

        foreach ($transactions as $transaction) {
            if (! $transaction->isExpenseSide()) {
                continue;
            }

            $amount = -$this->convertTransactionAmount($transaction, $currency);

            $account = $transaction->account;

            $totals[$account->id] ??= [
                'id' => $account->id,
                'name' => $account->name,
                'bank' => $account->bank ? ['name' => $account->bank->name, 'logo' => $account->bank->logo] : null,
                'amount' => 0,
            ];
            $totals[$account->id]['amount'] += $amount;
        }

        return collect($totals)
            ->filter(fn (array $account): bool => $account['amount'] > 0)
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * The biggest individual expenses, richest-first, each carrying the same
     * display fields the transaction table shows so the drawer can render a
     * familiar row. Capped at the limit the drawer can reveal.
     *
     * @return array<int, array{id: string, date: string, description: ?string, amount: int, category: ?array{name: string, color: ?string, icon: ?string}, account: array{name: string, bank: ?array{name: string, logo: ?string}}, labels: array<int, array{id: string, name: string, color: ?string}>}>
     */
    private function largestExpenses(Collection $transactions, string $currency): array
    {
        return $transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->isExpenseSide() && $transaction->amount < 0)
            ->sortBy(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $currency))
            ->take(self::LARGEST_EXPENSES_LIMIT)
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'date' => $transaction->transaction_date->toDateString(),
                'description' => $transaction->description,
                'amount' => abs($this->convertTransactionAmount($transaction, $currency)),
                'category' => $transaction->category ? [
                    'name' => $transaction->category->name,
                    'color' => $transaction->category->color,
                    'icon' => $transaction->category->icon,
                ] : null,
                'account' => [
                    'name' => $transaction->account->name,
                    'bank' => $transaction->account->bank ? [
                        'name' => $transaction->account->bank->name,
                        'logo' => $transaction->account->bank->logo,
                    ] : null,
                ],
                'labels' => $transaction->labels
                    ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Income and expense bucketed over the filtered span, plus a running
     * expense total so the pace of spending is visible.
     *
     * @return array{bucket: string, points: array<int, array{date: string, label: string, income: int, expense: int, cumulative_expense: int, cumulative_net: int}>}
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
            $buckets[$key] ??= ['income' => 0, 'expense' => 0];

            if ($transaction->isIncomeSide()) {
                $buckets[$key]['income'] += $this->convertTransactionAmount($transaction, $currency);
            } elseif ($transaction->isExpenseSide()) {
                $buckets[$key]['expense'] -= $this->convertTransactionAmount($transaction, $currency);
            }
        }

        $points = [];
        $cumulativeExpense = 0;
        $cumulativeNet = 0;
        $cursor = $daily ? $start->copy()->startOfDay() : $start->copy()->startOfMonth();
        $last = $daily ? $end->copy()->startOfDay() : $end->copy()->startOfMonth();

        while ($cursor->lte($last)) {
            $key = $cursor->format($keyFormat);
            $income = $buckets[$key]['income'] ?? 0;
            $expense = $buckets[$key]['expense'] ?? 0;
            $cumulativeExpense += $expense;
            $cumulativeNet += $income - $expense;

            $points[] = [
                'date' => $key,
                'label' => $daily ? $cursor->format('M j') : $cursor->format('M Y'),
                'income' => $income,
                'expense' => $expense,
                'cumulative_expense' => $cumulativeExpense,
                'cumulative_net' => $cumulativeNet,
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
}
