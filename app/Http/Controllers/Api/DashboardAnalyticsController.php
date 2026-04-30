<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Services\AccountMetricsService;
use App\Services\BalanceLookup;
use App\Services\ExchangeRateService;
use App\Services\LoanAmortizationService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsController extends Controller
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private AccountMetricsService $accountMetricsService,
        private LoanAmortizationService $loanAmortizationService,
    ) {}

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

        return response()->json(
            $this->accountMetricsService->getNetWorthEvolution($userCurrency, $accounts, $start, $end)
        );
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

        $linkedLoanAccount = $this->getLinkedLoanAccount($account);
        $linkedLoanId = $linkedLoanAccount?->id;
        $accountIds = $linkedLoanId ? [$account->id, $linkedLoanId] : [$account->id];

        $lookup = BalanceLookup::forAccounts($accountIds, $start->copy()->startOfMonth(), $end);

        $userCurrency = $request->user()->currency_code;
        $displayCurrencyCode = strcasecmp($account->currency_code, $userCurrency) !== 0
            ? $userCurrency
            : null;

        $points = [];
        $current = $start->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        while ($current->lte($endMonth)) {
            $date = $current->copy()->endOfMonth();
            $value = $lookup->getBalanceAt($account->id, $date);
            $point = [
                'month' => $date->format('Y-m'),
                'timestamp' => $date->timestamp,
                'value' => $value,
            ];

            if ($account->type->supportsInvestedAmount()) {
                $investedAmount = $lookup->getInvestedAmountAt($account->id, $date);
                $point['invested_amount'] = $investedAmount;

                if ($displayCurrencyCode !== null && $investedAmount !== null) {
                    $point['display_invested_amount'] = $this->convertBalanceForDate($userCurrency, $account->currency_code, $investedAmount, $date);
                }
            }

            if ($linkedLoanId) {
                $mortgageBalance = $lookup->getBalanceAt($linkedLoanId, $date);
                $point['mortgage_balance'] = $this->convertBalanceForDate(
                    $linkedLoanAccount->currency_code,
                    $account->currency_code,
                    $mortgageBalance,
                    $date,
                );

                if ($displayCurrencyCode !== null) {
                    $point['display_mortgage_balance'] = $this->convertBalanceForDate(
                        $linkedLoanAccount->currency_code,
                        $displayCurrencyCode,
                        $mortgageBalance,
                        $date,
                    );
                }
            }

            if ($displayCurrencyCode !== null) {
                $point['display_value'] = $this->convertBalanceForDate(
                    $account->currency_code,
                    $displayCurrencyCode,
                    $value,
                    $date,
                );
            }

            $points[] = $point;
            $current->addMonth();
        }

        // Append projected future months for loan accounts with loan details
        if ($account->type === AccountType::Loan) {
            $loanDetail = $account->loanDetail;

            if ($loanDetail) {
                $projection = $this->loanAmortizationService->generateProjection($loanDetail, 12);
                $now = Carbon::now();

                foreach ($projection as $yearMonth => $balanceCents) {
                    $projectedDate = Carbon::createFromFormat('Y-m', $yearMonth)->endOfMonth();

                    // Only add future months that are beyond the current date
                    if ($projectedDate->lte($now)) {
                        continue;
                    }

                    $projectedPoint = [
                        'month' => $yearMonth,
                        'timestamp' => $projectedDate->timestamp,
                        'value' => $balanceCents,
                        'projected' => true,
                    ];

                    if ($displayCurrencyCode !== null) {
                        $projectedPoint['display_value'] = $this->convertBalanceForDate(
                            $account->currency_code,
                            $displayCurrencyCode,
                            $balanceCents,
                            Carbon::today(),
                        );
                    }

                    $points[] = $projectedPoint;
                }
            }
        }

        // Append projected future months for real estate accounts with revaluation
        // and/or a linked loan, so the chart shows both market value and mortgage
        // forward together.
        if ($account->type === AccountType::RealEstate) {
            $realEstateDetail = $account->realEstateDetail;
            $revaluationPercentage = $realEstateDetail?->revaluation_percentage;
            $hasRevaluation = $revaluationPercentage !== null && (float) $revaluationPercentage !== 0.0;

            $linkedLoanDetail = null;
            if ($linkedLoanAccount) {
                $linkedLoanAccount->loadMissing('loanDetail');
                $linkedLoanDetail = $linkedLoanAccount->loanDetail;
            }

            if ($hasRevaluation || $linkedLoanDetail) {
                $monthsAhead = 12;
                $now = Carbon::now();
                $lastPoint = end($points);
                $baseValue = is_array($lastPoint) ? $lastPoint['value'] : 0;
                $monthlyRate = $hasRevaluation ? ((float) $revaluationPercentage / 12 / 100) : 0.0;

                $loanProjection = $linkedLoanDetail
                    ? $this->loanAmortizationService->generateProjection($linkedLoanDetail, $monthsAhead)
                    : [];

                for ($i = 1; $i <= $monthsAhead; $i++) {
                    $projectedDate = $now->copy()->addMonthsNoOverflow($i)->endOfMonth();
                    $yearMonth = $projectedDate->format('Y-m');

                    $projectedValue = (int) round($baseValue * pow(1 + $monthlyRate, $i));

                    $projectedPoint = [
                        'month' => $yearMonth,
                        'timestamp' => $projectedDate->timestamp,
                        'value' => $projectedValue,
                        'projected' => true,
                    ];

                    if ($displayCurrencyCode !== null) {
                        $projectedPoint['display_value'] = $this->convertBalanceForDate(
                            $account->currency_code,
                            $displayCurrencyCode,
                            $projectedValue,
                            Carbon::today(),
                        );
                    }

                    if ($linkedLoanDetail && array_key_exists($yearMonth, $loanProjection)) {
                        $mortgageProj = $loanProjection[$yearMonth];
                        $projectedPoint['mortgage_balance'] = $this->convertBalanceForDate(
                            $linkedLoanAccount->currency_code,
                            $account->currency_code,
                            $mortgageProj,
                            $projectedDate,
                        );

                        if ($displayCurrencyCode !== null) {
                            $projectedPoint['display_mortgage_balance'] = $this->convertBalanceForDate(
                                $linkedLoanAccount->currency_code,
                                $displayCurrencyCode,
                                $mortgageProj,
                                $projectedDate,
                            );
                        }
                    }

                    $points[] = $projectedPoint;
                }
            }
        }

        $response = [
            'data' => $points,
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'name_iv' => $account->name_iv,
                'encrypted' => $account->encrypted,
                'type' => $account->type,
                'currency_code' => $account->currency_code,
            ],
        ];

        if ($displayCurrencyCode !== null) {
            $response['display_currency_code'] = $displayCurrencyCode;
        }

        return response()->json($response);
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

        $linkedLoanAccount = $this->getLinkedLoanAccount($account);
        $linkedLoanId = $linkedLoanAccount?->id;
        $accountIds = $linkedLoanId ? [$account->id, $linkedLoanId] : [$account->id];

        $lookup = BalanceLookup::forAccounts($accountIds, $start, $end);

        $userCurrency = $request->user()->currency_code;
        $displayCurrencyCode = strcasecmp($account->currency_code, $userCurrency) !== 0
            ? $userCurrency
            : null;

        $points = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $date = $current->copy();
            $value = $lookup->getBalanceAt($account->id, $date);
            $point = [
                'date' => $date->format('Y-m-d'),
                'timestamp' => $date->endOfDay()->timestamp,
                'value' => $value,
            ];

            if ($account->type->supportsInvestedAmount()) {
                $investedAmount = $lookup->getInvestedAmountAt($account->id, $date);
                $point['invested_amount'] = $investedAmount;

                if ($displayCurrencyCode !== null && $investedAmount !== null) {
                    $point['display_invested_amount'] = $this->convertBalanceForDate($userCurrency, $account->currency_code, $investedAmount, $date);
                }
            }

            if ($linkedLoanId) {
                $mortgageBalance = $lookup->getBalanceAt($linkedLoanId, $date);
                $point['mortgage_balance'] = $this->convertBalanceForDate(
                    $linkedLoanAccount->currency_code,
                    $account->currency_code,
                    $mortgageBalance,
                    $date,
                );

                if ($displayCurrencyCode !== null) {
                    $point['display_mortgage_balance'] = $this->convertBalanceForDate(
                        $linkedLoanAccount->currency_code,
                        $displayCurrencyCode,
                        $mortgageBalance,
                        $date,
                    );
                }
            }

            if ($displayCurrencyCode !== null) {
                $point['display_value'] = $this->convertBalanceForDate(
                    $account->currency_code,
                    $displayCurrencyCode,
                    $value,
                    $date,
                );
            }

            $points[] = $point;
            $current->addDay();
        }

        $response = [
            'data' => $points,
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'name_iv' => $account->name_iv,
                'encrypted' => $account->encrypted,
                'type' => $account->type,
                'currency_code' => $account->currency_code,
            ],
        ];

        if ($displayCurrencyCode !== null) {
            $response['display_currency_code'] = $displayCurrencyCode;
        }

        return response()->json($response);
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

        return response()->json(
            $this->accountMetricsService->getNetWorthDailyEvolution($userCurrency, $accounts, $start, $end)
        );
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

    private function calculateNetWorthAt(Carbon $date, string $userCurrency): int
    {
        $accounts = Account::where('user_id', request()->user()->id)->get();

        $total = 0;

        foreach ($accounts as $account) {
            $balance = AccountBalance::query()
                ->where('account_id', $account->id)
                ->where('balance_date', '<=', $date->toDateString())
                ->orderBy('balance_date', 'desc')
                ->value('balance') ?? 0;

            $convertedBalance = $this->exchangeRateService->convert(
                $account->currency_code,
                $userCurrency,
                $balance,
                $date->toDateString(),
            );

            $total += $account->type->reducesNetWorth()
                ? -abs($convertedBalance)
                : $convertedBalance;
        }

        return $total;
    }

    private function calculateSpending(Carbon $from, Carbon $to): int
    {
        $spending = Transaction::query()
            ->where('transactions.user_id', request()->user()->id)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Expense);
            })
            ->sum('transactions.amount');

        return abs($spending);
    }

    private function calculateCashFlow(Carbon $from, Carbon $to): array
    {
        $income = Transaction::query()
            ->where('transactions.user_id', request()->user()->id)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->join('categories', function ($join) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', CategoryType::Income);
            })
            ->sum('transactions.amount');

        $expense = Transaction::query()
            ->where('transactions.user_id', request()->user()->id)
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

    /**
     * Get the linked loan account for a real estate account, if any.
     */
    private function getLinkedLoanAccount(Account $account): ?Account
    {
        if ($account->type !== AccountType::RealEstate) {
            return null;
        }

        return $account->realEstateDetail?->linkedLoanAccount;
    }

    private function convertBalanceForDate(string $sourceCurrency, string $targetCurrency, int $amount, Carbon $date): int
    {
        if (strcasecmp($sourceCurrency, $targetCurrency) === 0) {
            return $amount;
        }

        return $this->exchangeRateService->convert(
            $sourceCurrency,
            $targetCurrency,
            $amount,
            $date->toDateString(),
        );
    }
}
