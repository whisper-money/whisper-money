<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsController extends Controller
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    public function netWorth(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        $userCurrency = $request->user()->currency_code;

        return response()->json([
            'current' => $this->calculateNetWorthAt($period->to, $userCurrency),
            'previous' => $this->calculateNetWorthAt($previousPeriod->to, $userCurrency),
            'currency_code' => $userCurrency,
        ]);
    }

    public function monthlySpending(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        return response()->json([
            'current' => $this->calculateSpending($period->from, $period->to),
            'previous' => $this->calculateSpending($previousPeriod->from, $previousPeriod->to),
        ]);
    }

    public function cashFlow(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        return response()->json([
            'current' => $this->calculateCashFlow($period->from, $period->to),
            'previous' => $this->calculateCashFlow($previousPeriod->from, $previousPeriod->to),
        ]);
    }

    public function netWorthEvolution(Request $request)
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

        $points = [];
        $current = $start->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        while ($current->lte($endMonth)) {
            $date = $current->copy()->endOfMonth();
            $point = [
                'month' => $date->format('Y-m'),
                'timestamp' => $date->timestamp,
            ];

            foreach ($accounts as $account) {
                $originalBalance = $this->getBalanceAt($account->id, $date);
                $convertedBalance = $this->convertBalance(
                    $originalBalance,
                    $account->currency_code,
                    $userCurrency,
                    $date->toDateString(),
                );

                $point[$account->id] = $convertedBalance;

                if ($account->currency_code !== $userCurrency) {
                    $point[$account->id.'_original'] = [
                        'amount' => $originalBalance,
                        'currency_code' => $account->currency_code,
                    ];
                }
            }

            $points[] = $point;
            $current->addMonth();
        }

        $accountsConfig = $accounts->mapWithKeys(function ($account) {
            return [
                $account->id => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'name_iv' => $account->name_iv,
                    'encrypted' => $account->encrypted,
                    'type' => $account->type,
                    'currency_code' => $account->currency_code,
                    'bank' => $account->bank,
                ],
            ];
        });

        return response()->json([
            'data' => $points,
            'accounts' => $accountsConfig,
            'currency_code' => $userCurrency,
        ]);
    }

    public function accountBalanceEvolution(Request $request, Account $account)
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

        $points = [];
        $current = $start->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        while ($current->lte($endMonth)) {
            $date = $current->copy()->endOfMonth();
            $points[] = [
                'month' => $date->format('Y-m'),
                'timestamp' => $date->timestamp,
                'value' => $this->getBalanceAt($account->id, $date),
            ];
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

    public function accountDailyBalanceEvolution(Request $request, Account $account)
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

        $points = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $date = $current->copy();
            $points[] = [
                'date' => $date->format('Y-m-d'),
                'timestamp' => $date->endOfDay()->timestamp,
                'value' => $this->getBalanceAt($account->id, $date),
            ];
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

    public function netWorthDailyEvolution(Request $request)
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

        $points = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $date = $current->copy();
            $point = [
                'date' => $date->format('Y-m-d'),
                'timestamp' => $date->endOfDay()->timestamp,
            ];

            foreach ($accounts as $account) {
                $originalBalance = $this->getBalanceAt($account->id, $date);
                $convertedBalance = $this->convertBalance(
                    $originalBalance,
                    $account->currency_code,
                    $userCurrency,
                    $date->toDateString(),
                );

                $point[$account->id] = $convertedBalance;

                if ($account->currency_code !== $userCurrency) {
                    $point[$account->id.'_original'] = [
                        'amount' => $originalBalance,
                        'currency_code' => $account->currency_code,
                    ];
                }
            }

            $points[] = $point;
            $current->addDay();
        }

        $accountsConfig = $accounts->mapWithKeys(function ($account) {
            return [
                $account->id => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'name_iv' => $account->name_iv,
                    'encrypted' => $account->encrypted,
                    'type' => $account->type,
                    'currency_code' => $account->currency_code,
                    'bank' => $account->bank,
                ],
            ];
        });

        return response()->json([
            'data' => $points,
            'accounts' => $accountsConfig,
            'currency_code' => $userCurrency,
        ]);
    }

    public function topCategories(Request $request)
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

    private function getCategorySpending(string $userId, Carbon $from, Carbon $to)
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereHas('category', function ($q) {
                $q->where('type', CategoryType::Expense);
            })
            ->select('category_id', DB::raw('sum(amount) as total_amount'))
            ->groupBy('category_id')
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

    private function calculateNetWorthAt(Carbon $date, string $userCurrency): int
    {
        $accounts = Account::where('user_id', request()->user()->id)->get();

        $total = 0;

        foreach ($accounts as $account) {
            $balance = $this->getBalanceAt($account->id, $date);
            $total += $this->convertBalance(
                $balance,
                $account->currency_code,
                $userCurrency,
                $date->toDateString(),
            );
        }

        return $total;
    }

    private function getBalanceAt(string $accountId, Carbon $date): int
    {
        return AccountBalance::query()
            ->where('account_id', $accountId)
            ->where('balance_date', '<=', $date->toDateString())
            ->orderBy('balance_date', 'desc')
            ->value('balance') ?? 0;
    }

    /**
     * Convert a balance from one currency to another, skipping conversion when currencies match.
     */
    private function convertBalance(int $balance, string $sourceCurrency, string $targetCurrency, string $date): int
    {
        if (strtolower($sourceCurrency) === strtolower($targetCurrency)) {
            return $balance;
        }

        return $this->exchangeRateService->convert($sourceCurrency, $targetCurrency, $balance, $date);
    }

    private function calculateSpending(Carbon $from, Carbon $to): int
    {
        $spending = Transaction::query()
            ->where('user_id', request()->user()->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereHas('category', function ($q) {
                $q->where('type', CategoryType::Expense);
            })
            ->sum('amount');

        return abs($spending);
    }

    private function calculateCashFlow(Carbon $from, Carbon $to): array
    {
        $income = Transaction::query()
            ->where('user_id', request()->user()->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereHas('category', function ($q) {
                $q->where('type', CategoryType::Income);
            })
            ->sum('amount');

        $expense = Transaction::query()
            ->where('user_id', request()->user()->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereHas('category', function ($q) {
                $q->where('type', CategoryType::Expense);
            })
            ->sum('amount');

        return [
            'income' => $income,
            'expense' => abs($expense),
        ];
    }
}
