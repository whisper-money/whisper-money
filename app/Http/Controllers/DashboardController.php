<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\BalanceLookup;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

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

        $userCurrency = $user->currency_code;

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['bank:id,name,logo'])
            ->get();

        $accountIds = $accounts->pluck('id');

        $lookupEnd = Carbon::now()->gt($end) ? Carbon::now() : $end->copy();
        $lookup = BalanceLookup::forAccounts($accountIds, $start->copy()->startOfMonth(), $lookupEnd);

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
                $originalBalance = $lookup->getBalanceAt($account->id, $date);
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

                if ($account->type->supportsInvestedAmount()) {
                    $investedAmount = $lookup->getInvestedAmountAt($account->id, $date);
                    $point[$account->id.'_invested'] = $investedAmount !== null
                        ? $this->convertBalance($investedAmount, $account->currency_code, $userCurrency, $date->toDateString())
                        : null;
                }
            }

            $points[] = $point;
            $current->addMonth();
        }

        $accountsConfig = $accounts->mapWithKeys(function ($account) use ($userCurrency, $lookup, $now) {
            $config = [
                'id' => $account->id,
                'name' => $account->name,
                'name_iv' => $account->name_iv,
                'encrypted' => $account->encrypted,
                'type' => $account->type,
                'currency_code' => $account->currency_code,
                'bank' => $account->bank,
            ];

            if ($account->type->supportsInvestedAmount()) {
                $investedAmount = $lookup->getInvestedAmountAt($account->id, $now);
                $config['invested_amount'] = $investedAmount !== null
                    ? $this->convertBalance($investedAmount, $account->currency_code, $userCurrency, $now->toDateString())
                    : null;
            }

            return [$account->id => $config];
        });

        return [
            'data' => $points,
            'accounts' => $accountsConfig,
            'currency_code' => $userCurrency,
        ];
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

    private function getCategorySpending(string $userId, Carbon $from, Carbon $to)
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

    private function calculateCashflowSummary(string $userId, Carbon $from, Carbon $to): array
    {
        $income = $this->getTransactionSum($userId, $from, $to, CategoryType::Income);
        $expense = abs($this->getTransactionSum($userId, $from, $to, CategoryType::Expense));

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

    private function convertBalance(int $balance, string $sourceCurrency, string $targetCurrency, string $date): int
    {
        if (strtolower($sourceCurrency) === strtolower($targetCurrency)) {
            return $balance;
        }

        return $this->exchangeRateService->convert($sourceCurrency, $targetCurrency, $balance, $date);
    }
}
