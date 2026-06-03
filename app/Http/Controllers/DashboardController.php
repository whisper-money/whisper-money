<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\AccountMetricsService;
use App\Services\CategoryTree;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private AccountMetricsService $accountMetricsService,
        private CategoryTree $tree,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'showEncryptionPrompt' => session('show_encryption_prompt', false),
            'netWorthEvolution' => Inertia::defer(fn () => $this->getNetWorthEvolution($request), 'dashboard'),
            'topCategories' => Inertia::defer(fn () => $this->getTopCategories($request), 'dashboard'),
            'cashflowSummary' => Inertia::defer(fn () => $this->getCashflowSummary($request), 'dashboard'),
        ]);
    }

    private function getNetWorthEvolution(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $start = $now->copy()->subMonths(12);
        $end = $now->copy();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['bank:id,name,logo', 'realEstateDetail:account_id,linked_loan_account_id'])
            ->get();

        return $this->accountMetricsService->getNetWorthEvolution($user->currency_code, $accounts, $start, $end);
    }

    private function getTopCategories(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $from = $now->copy()->subDays(30);
        $to = $now->copy();

        $period = new PeriodComparator($from, $to);
        $previousPeriod = $period->previous();

        $currentSpending = $this->getCategorySpending($user->id, $period->from, $period->to);
        $previousSpending = $this->getCategorySpending($user->id, $previousPeriod->from, $previousPeriod->to);

        $totalAmount = $currentSpending->sum('amount');

        return $currentSpending
            ->sortByDesc('amount')
            ->take(10)
            ->map(function ($item) use ($previousSpending, $totalAmount) {
                $previousAmount = $previousSpending->firstWhere('category_id', $item['category_id'])['amount'] ?? 0;

                return [
                    'category' => $item['category'],
                    'amount' => $item['amount'],
                    'previous_amount' => $previousAmount,
                    'total_amount' => $totalAmount,
                ];
            })
            ->values()
            ->all();
    }

    private function getCashflowSummary(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $from = $now->copy()->startOfMonth();
        $to = $now->copy()->endOfMonth();

        $period = new PeriodComparator($from, $to);
        $previousPeriod = $period->previous();

        return [
            'current' => $this->calculateCashflowSummary($user->id, $period->from, $period->to),
            'previous' => $this->calculateCashflowSummary($user->id, $previousPeriod->from, $previousPeriod->to),
        ];
    }

    /**
     * Spending per top-level category: child category amounts roll up into
     * their root ancestor so the dashboard only lists parents.
     *
     * @return Collection<int, array{category_id: string, amount: int, category: Category}>
     */
    private function getCategorySpending(string $userId, Carbon $from, Carbon $to): Collection
    {
        $rootMap = $this->tree->rootAncestorMap($userId);

        $rolledUp = Transaction::query()
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
            ->groupBy(fn ($item): string => $rootMap[$item->category_id] ?? $item->category_id)
            ->map(fn (Collection $items, string $rootId): array => [
                'category_id' => $rootId,
                'amount' => (int) -$items->sum('total_amount'),
            ])
            ->filter(fn (array $item): bool => $item['amount'] > 0);

        $categories = Category::query()
            ->whereIn('id', $rolledUp->keys())
            ->get()
            ->keyBy('id');

        return $rolledUp
            ->map(fn (array $item): array => [...$item, 'category' => $categories->get($item['category_id'])])
            ->filter(fn (array $item): bool => $item['category'] !== null)
            ->values();
    }

    private function calculateCashflowSummary(string $userId, Carbon $from, Carbon $to): array
    {
        $income = max(0, $this->getTransactionSum($userId, $from, $to, CategoryType::Income));
        $expense = max(0, -$this->getTransactionSum($userId, $from, $to, CategoryType::Expense));

        $net = $income - $expense;
        $savingsRate = $income > 0 ? round((($income - $expense) / $income) * 100, 1) : 0;

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $net,
            'savings_rate' => $savingsRate,
        ];
    }

    private function getTransactionSum(string $userId, Carbon $from, Carbon $to, CategoryType $type): int
    {
        return Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->where(function ($q) use ($type) {
                $q->whereExists(function ($sub) use ($type) {
                    $sub->select(DB::raw(1))
                        ->from('categories')
                        ->whereColumn('categories.id', 'transactions.category_id')
                        ->where('categories.type', $type);
                })
                    ->orWhere(function ($q) use ($type) {
                        $q->whereNull('transactions.category_id')
                            ->where('transactions.amount', $type === CategoryType::Income ? '>' : '<', 0);
                    });
            })
            ->sum('transactions.amount');
    }
}
