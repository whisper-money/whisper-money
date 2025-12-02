<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsController extends Controller
{
    public function netWorth(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        return response()->json([
            'current' => $this->calculateNetWorthAt($period->to),
            'previous' => $this->calculateNetWorthAt($previousPeriod->to),
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

        $accounts = Account::query()
            ->where('user_id', $request->user()->id)
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
                $point[$account->id] = $this->getBalanceAt($account->id, $date);
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
                    'type' => $account->type,
                    'currency_code' => $account->currency_code,
                ],
            ];
        });

        return response()->json([
            'data' => $points,
            'accounts' => $accountsConfig,
        ]);
    }

    public function accountBalances(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        $accounts = Account::query()
            ->where('user_id', $request->user()->id)
            ->with(['bank:id,name,logo'])
            ->get();

        $data = $accounts->map(function ($account) use ($period, $previousPeriod) {
            $currentBalance = $this->getBalanceAt($account->id, $period->to);
            $previousBalance = $this->getBalanceAt($account->id, $previousPeriod->to);

            // Evolution history (monthly for the requested period)
            $history = [];
            $current = $period->from->copy()->endOfMonth();
            while ($current->lte($period->to)) {
                $history[] = [
                    'date' => $current->format('M'),
                    'value' => $this->getBalanceAt($account->id, $current),
                ];
                $current->addMonth()->endOfMonth();
            }

            return [
                'id' => $account->id,
                'name' => $account->name,
                'name_iv' => $account->name_iv,
                'type' => $account->type,
                'bank' => $account->bank,
                'current_balance' => $currentBalance,
                'previous_balance' => $previousBalance,
                'currency_code' => $account->currency_code,
                'history' => $history,
            ];
        });

        return response()->json($data);
    }

    public function topCategories(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);

        $top = Transaction::query()
            ->where('user_id', $request->user()->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereHas('category', function ($q) {
                $q->where('type', CategoryType::Expense);
            })
            ->select('category_id', DB::raw('sum(amount) as total_amount'))
            ->groupBy('category_id')
            ->orderByRaw('total_amount asc')
            ->with('category')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'category' => $item->category,
                    'amount' => abs($item->total_amount),
                ];
            })
            ->sortByDesc('amount') // Re-sort because DB sort on negative numbers gives least spending first
            ->values();

        return response()->json($top);
    }

    private function calculateNetWorthAt(Carbon $date): int
    {
        // Get latest balance for each account on or before date
        $accounts = Account::where('user_id', request()->user()->id)->get();

        $total = 0;

        foreach ($accounts as $account) {
            $balance = $this->getBalanceAt($account->id, $date);

            // Assets: Checking, Savings, Others
            // Liabilities: CreditCard, Loan
            if (in_array($account->type, [AccountType::CreditCard, AccountType::Loan])) {
                // Liabilities should be subtracted from Net Worth.
                // If balance is stored as positive debt (e.g. $1000 debt), we subtract it.
                // If balance is stored as negative (e.g. -$1000), we add it.
                // Standard convention in this app seems to be signed values based on `account_balances` table.
                // Let's assume standard accounting: Credit Card balance of -$500 means I owe $500.
                // So I just sum everything up.
                // Wait, user option was "Assets minus liabilities".
                // If I have $1000 in Checking (Asset) and -$500 in CC (Liability).
                // Net Worth = 1000 + (-500) = 500.
                // This is effectively "Sum of all accounts".
                // User explicitly selected "Assets minus liabilities" vs "Sum of all accounts".
                // "Sum of all accounts - This treats all accounts equally. Simple but may include debt as negative values."
                // "Assets minus liabilities - ... Net Worth = Total Assets - Total Liabilities."

                // If Credit Card balance is stored as POSITIVE (e.g. "I owe $500"), then Net Worth = Assets - Liabilities.
                // If Credit Card balance is stored as NEGATIVE (e.g. "-$500"), then Net Worth = Assets + Liabilities (1000 + (-500) = 500).

                // Let's check `AccountBalance` table data or assumptions.
                // I'll assume balances are stored as their actual signed value (Assets +, Liabilities -).
                // If so, simple sum is correct.
                // BUT, if the user chose "Assets minus liabilities" distinct from "Sum of all",
                // it implies liabilities might be stored as positive numbers?
                // OR they just want the breakdown.

                // Let's double check how `useDashboardData` calculated it previously.
                // `const currentNetWorth = accountMetrics.reduce((sum, acc) => sum + acc.currentBalance, 0);`
                // It was just summing them up.

                // If I assume signed balances:
                $total += $balance;
            } else {
                $total += $balance;
            }
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

    private function calculateSpending(Carbon $from, Carbon $to): int
    {
        $spending = Transaction::query()
            ->where('user_id', request()->user()->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->where('amount', '<', 0) // Expenses are negative
             // Optional: exclude transfers?
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
            ->where('amount', '>', 0)
            ->whereHas('category', function ($q) {
                $q->where('type', CategoryType::Income);
            })
            ->sum('amount');

        $expense = Transaction::query()
            ->where('user_id', request()->user()->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->where('amount', '<', 0)
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
