<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Services\AccountMetricsService;
use App\Services\BalanceLookup;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsController extends Controller
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private AccountMetricsService $accountMetricsService,
    ) {}

    public function netWorth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        $userCurrency = $request->user()->currency_code;
        $userId = $request->user()->id;

        return response()->json([
            'current' => $this->calculateNetWorthAt($userId, $period->to, $userCurrency),
            'previous' => $this->calculateNetWorthAt($userId, $previousPeriod->to, $userCurrency),
            'currency_code' => $userCurrency,
        ]);
    }

    public function monthlySpending(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $userId = $request->user()->id;

        return response()->json([
            'current' => $this->calculateSpending($userId, $period->from, $period->to),
            'previous' => $this->calculateSpending($userId, $previousPeriod->from, $previousPeriod->to),
        ]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $userId = $request->user()->id;

        return response()->json([
            'current' => $this->calculateCashFlow($userId, $period->from, $period->to),
            'previous' => $this->calculateCashFlow($userId, $previousPeriod->from, $previousPeriod->to),
        ]);
    }

    public function netWorthEvolution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $start = Carbon::parse($validated['from']);
        $end = Carbon::parse($validated['to']);

        $userCurrency = $request->user()->currency_code;

        $accounts = Account::query()
            ->where('user_id', $request->user()->id)
            ->with(['bank:id,name,logo'])
            ->get();

        return response()->json(
            $this->accountMetricsService->getNetWorthEvolution($userCurrency, $accounts, $start, $end)
        );
    }

    public function accountBalanceEvolution(Request $request, Account $account): JsonResponse
    {
        if ($account->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $start = Carbon::parse($validated['from']);
        $end = Carbon::parse($validated['to']);

        $lookup = BalanceLookup::forAccounts([$account->id], $start->copy()->startOfMonth(), $end);

        $points = [];
        $current = $start->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        while ($current->lte($endMonth)) {
            $date = $current->copy()->endOfMonth();
            $point = [
                'month' => $date->format('Y-m'),
                'timestamp' => $date->timestamp,
                'value' => $lookup->getBalanceAt($account->id, $date),
            ];

            if ($account->type->supportsInvestedAmount()) {
                $point['invested_amount'] = $lookup->getInvestedAmountAt($account->id, $date);
            }

            $points[] = $point;
            $current->addMonth();
        }

        return response()->json([
            'data' => $points,
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'name_iv' => $account->name_iv,
                'encrypted' => $account->encrypted,
                'type' => $account->type,
                'currency_code' => $account->currency_code,
            ],
        ]);
    }

    public function accountDailyBalanceEvolution(Request $request, Account $account): JsonResponse
    {
        if ($account->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $start = Carbon::parse($validated['from']);
        $end = Carbon::parse($validated['to']);

        $lookup = BalanceLookup::forAccounts([$account->id], $start, $end);

        $points = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $date = $current->copy();
            $point = [
                'date' => $date->format('Y-m-d'),
                'timestamp' => $date->endOfDay()->timestamp,
                'value' => $lookup->getBalanceAt($account->id, $date),
            ];

            if ($account->type->supportsInvestedAmount()) {
                $point['invested_amount'] = $lookup->getInvestedAmountAt($account->id, $date);
            }

            $points[] = $point;
            $current->addDay();
        }

        return response()->json([
            'data' => $points,
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'name_iv' => $account->name_iv,
                'encrypted' => $account->encrypted,
                'type' => $account->type,
                'currency_code' => $account->currency_code,
            ],
        ]);
    }

    public function netWorthDailyEvolution(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $start = Carbon::parse($validated['from']);
        $end = Carbon::parse($validated['to']);

        $userCurrency = $request->user()->currency_code;

        $accounts = Account::query()
            ->where('user_id', $request->user()->id)
            ->with(['bank:id,name,logo'])
            ->get();

        return response()->json(
            $this->accountMetricsService->getNetWorthDailyEvolution($userCurrency, $accounts, $start, $end)
        );
    }

    public function topCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        $currentSpending = $this->getCategorySpending($request->user()->id, $period->from, $period->to);
        $previousSpending = $this->getCategorySpending($request->user()->id, $previousPeriod->from, $previousPeriod->to);

        $totalAmount = $currentSpending->sum('amount');

        $top = $currentSpending
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
            ->values();

        return response()->json($top);
    }

    private function getCategorySpending(string $userId, Carbon $from, Carbon $to): Collection
    {
        return Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Expense);
            })
            ->select('transactions.category_id', DB::raw('sum(transactions.amount) as total_amount'))
            ->groupBy('transactions.category_id')
            ->with('category')
            ->get()
            ->map(function ($item) {
                return [
                    'category_id' => $item->category_id,
                    'category' => $item->category,
                    'amount' => abs($item->total_amount),
                ];
            });
    }

    private function calculateNetWorthAt(string $userId, Carbon $date, string $userCurrency): int
    {
        $accounts = Account::where('user_id', $userId)->get();

        $total = 0;

        foreach ($accounts as $account) {
            $balance = AccountBalance::query()
                ->where('account_id', $account->id)
                ->where('balance_date', '<=', $date->toDateString())
                ->orderBy('balance_date', 'desc')
                ->value('balance') ?? 0;

            $total += $this->exchangeRateService->convert(
                $account->currency_code,
                $userCurrency,
                $balance,
                $date->toDateString(),
            );
        }

        return $total;
    }

    private function calculateSpending(string $userId, Carbon $from, Carbon $to): int
    {
        $spending = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Expense);
            })
            ->sum('transactions.amount');

        return abs($spending);
    }

    private function calculateCashFlow(string $userId, Carbon $from, Carbon $to): array
    {
        $income = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Income);
            })
            ->sum('transactions.amount');

        $expense = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Expense);
            })
            ->sum('transactions.amount');

        return [
            'income' => $income,
            'expense' => abs($expense),
        ];
    }
}
