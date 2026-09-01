<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\AccountMetricsService;
use App\Services\BalanceLookup;
use App\Services\CategorySpendingService;
use App\Services\ExchangeRateService;
use App\Services\LoanAmortizationService;
use App\Services\NetWorthCalculator;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardAnalyticsController extends Controller
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private AccountMetricsService $accountMetricsService,
        private LoanAmortizationService $loanAmortizationService,
        private CategorySpendingService $categorySpendingService,
        private NetWorthCalculator $netWorthCalculator,
    ) {}

    public function netWorth(Request $request)
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();

        $user = $request->user();
        $userCurrency = $user->currency_code;
        $excludedTypes = $this->netWorthCalculator->excludedTypesFor($user);

        $accounts = Account::query()
            ->where('user_id', $request->user()->id)
            ->get();

        // Load every account's balance history for the compared range in a
        // fixed number of queries instead of one balance lookup per account.
        $lookup = BalanceLookup::forAccounts(
            $accounts->pluck('id'),
            $previousPeriod->to,
            $period->to,
        );

        return response()->json([
            'current' => $this->netWorthCalculator->at($accounts, $lookup, $period->to, $userCurrency, $excludedTypes),
            'previous' => $this->netWorthCalculator->at($accounts, $lookup, $previousPeriod->to, $userCurrency, $excludedTypes),
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
        [$start, $end] = $this->authorizedDateRange($request, $account);

        $linkedLoanAccount = $this->getLinkedLoanAccount($account);
        $displayCurrencyCode = $this->displayCurrencyFor($request, $account);
        $lookup = $this->balanceLookupFor($account, $linkedLoanAccount, $start->copy()->startOfMonth(), $end);

        $points = [];
        $current = $start->copy()->startOfMonth();
        $endMonth = $end->copy()->startOfMonth();

        while ($current->lte($endMonth)) {
            $date = $current->copy()->endOfMonth();
            $points[] = [
                'month' => $date->format('Y-m'),
                ...$this->balancePointAt($account, $linkedLoanAccount, $lookup, $date, $displayCurrencyCode),
            ];
            $current->addMonth();
        }

        return $this->evolutionResponse(
            [...$points, ...$this->projectedPoints($account, $linkedLoanAccount, $points, $displayCurrencyCode)],
            $account,
            $displayCurrencyCode,
        );
    }

    public function accountDailyBalanceEvolution(Request $request, Account $account)
    {
        [$start, $end] = $this->authorizedDateRange($request, $account);

        $linkedLoanAccount = $this->getLinkedLoanAccount($account);
        $displayCurrencyCode = $this->displayCurrencyFor($request, $account);
        $lookup = $this->balanceLookupFor($account, $linkedLoanAccount, $start, $end);

        $points = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $date = $current->copy()->endOfDay();
            $points[] = [
                'date' => $date->format('Y-m-d'),
                ...$this->balancePointAt($account, $linkedLoanAccount, $lookup, $date, $displayCurrencyCode),
            ];
            $current->addDay();
        }

        return $this->evolutionResponse($points, $account, $displayCurrencyCode);
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
            'parent' => 'nullable|uuid',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $drillParentId = $validated['parent'] ?? null;

        $currentSpending = $this->categorySpendingService->forPeriod($request->user()->id, $period->from, $period->to, $drillParentId);
        $previousSpending = $this->categorySpendingService->forPeriod($request->user()->id, $previousPeriod->from, $previousPeriod->to, $drillParentId);

        $totalAmount = $currentSpending->sum('amount');

        $top = $currentSpending
            ->sortByDesc('amount')
            ->take(10)
            ->map(function ($item) use ($previousSpending, $totalAmount) {
                $previousAmount = $previousSpending->firstWhere('category_id', $item['category_id'])['amount'] ?? 0;

                return [
                    'category' => $item['category'],
                    'category_id' => $item['category_id'],
                    'amount' => $item['amount'],
                    'previous_amount' => $previousAmount,
                    'total_amount' => $totalAmount,
                    'has_children' => $item['has_children'],
                    'is_direct' => $item['is_direct'],
                ];
            })
            ->values();

        return response()->json($top);
    }

    private function calculateSpending(Carbon $from, Carbon $to): int
    {
        return max(0, -$this->sumByCategoryType(CategoryType::Expense, $from, $to));
    }

    private function calculateCashFlow(Carbon $from, Carbon $to): array
    {
        return [
            'income' => max(0, $this->sumByCategoryType(CategoryType::Income, $from, $to)),
            'expense' => max(0, -$this->sumByCategoryType(CategoryType::Expense, $from, $to)),
        ];
    }

    /**
     * Signed sum of the user's transactions in the range whose category is of the
     * given type: income comes out positive, expenses negative.
     */
    private function sumByCategoryType(CategoryType $type, Carbon $from, Carbon $to): int
    {
        return (int) Transaction::query()
            ->where('transactions.user_id', request()->user()->id)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->joinOwningAccount()
            ->withoutArchivedAccountActivity()
            ->join('categories', function ($join) use ($type) {
                $join->on('transactions.category_id', '=', 'categories.id')
                    ->where('categories.type', '=', $type)
                    ->whereNull('categories.deleted_at');
            })
            ->sum(Transaction::ownedAmount());
    }

    /**
     * Make sure the account belongs to the caller and return the requested range.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function authorizedDateRange(Request $request, Account $account): array
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        return [Carbon::parse($validated['from']), Carbon::parse($validated['to'])];
    }

    /**
     * The user's currency when it differs from the account's, null when no
     * conversion is needed.
     */
    private function displayCurrencyFor(Request $request, Account $account): ?string
    {
        $userCurrency = $request->user()->currency_code;

        return strcasecmp($account->currency_code, $userCurrency) !== 0 ? $userCurrency : null;
    }

    private function balanceLookupFor(Account $account, ?Account $linkedLoanAccount, Carbon $from, Carbon $to): BalanceLookup
    {
        return BalanceLookup::forAccounts(
            array_values(array_filter([$account->id, $linkedLoanAccount?->id])),
            $from,
            $to,
        );
    }

    /**
     * Balance, invested amount and linked-mortgage balance at a single date, with
     * their display-currency equivalents. The monthly and daily series share this:
     * they differ only in the dates they ask for.
     *
     * @return array<string, int|null>
     */
    private function balancePointAt(Account $account, ?Account $linkedLoanAccount, BalanceLookup $lookup, Carbon $date, ?string $displayCurrencyCode): array
    {
        $value = $lookup->getBalanceAt($account->id, $date);

        $point = [
            'timestamp' => $date->timestamp,
            'value' => $value,
        ];

        if ($account->type->supportsInvestedAmount()) {
            $investedAmount = $lookup->getInvestedAmountAt($account->id, $date);
            $point['invested_amount'] = $investedAmount;

            if ($displayCurrencyCode !== null && $investedAmount !== null) {
                $point['display_invested_amount'] = $this->convertBalanceForDate($displayCurrencyCode, $account->currency_code, $investedAmount, $date);
            }
        }

        if ($linkedLoanAccount !== null) {
            $mortgageBalance = $lookup->getBalanceAt($linkedLoanAccount->id, $date);
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

        return $point;
    }

    /**
     * Future months appended to the historical series: the amortization schedule
     * for loans, and revaluation plus the linked mortgage for real estate.
     *
     * @param  list<array<string, mixed>>  $points  the historical series
     * @return list<array<string, mixed>>
     */
    private function projectedPoints(Account $account, ?Account $linkedLoanAccount, array $points, ?string $displayCurrencyCode): array
    {
        return match ($account->type) {
            AccountType::Loan => $this->loanProjection($account, $displayCurrencyCode),
            AccountType::RealEstate => $this->realEstateProjection($account, $linkedLoanAccount, $points, $displayCurrencyCode),
            default => [],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loanProjection(Account $account, ?string $displayCurrencyCode): array
    {
        $loanDetail = $account->loanDetail;

        if ($loanDetail === null) {
            return [];
        }

        $now = Carbon::now();
        $points = [];

        foreach ($this->loanAmortizationService->generateProjection($loanDetail, 12) as $yearMonth => $balanceCents) {
            $projectedDate = Carbon::createFromFormat('Y-m', $yearMonth)->endOfMonth();

            // Only add future months that are beyond the current date
            if ($projectedDate->lte($now)) {
                continue;
            }

            $points[] = $this->projectedPoint($account, $projectedDate, $balanceCents, $displayCurrencyCode);
        }

        return $points;
    }

    /**
     * Real estate projects market value forward from the last historical point at
     * the revaluation rate, alongside the linked mortgage, so the chart shows both
     * moving together.
     *
     * @param  list<array<string, mixed>>  $points  the historical series
     * @return list<array<string, mixed>>
     */
    private function realEstateProjection(Account $account, ?Account $linkedLoanAccount, array $points, ?string $displayCurrencyCode): array
    {
        // The ?? already covers a missing detail row, so no nullsafe operator here
        // (phpstan flags the combination as redundant).
        $revaluationPercentage = (float) ($account->realEstateDetail->revaluation_percentage ?? 0);
        $linkedLoanDetail = $linkedLoanAccount?->loadMissing('loanDetail')->loanDetail;

        if ($revaluationPercentage === 0.0 && $linkedLoanDetail === null) {
            return [];
        }

        $monthsAhead = 12;
        $lastPoint = end($points);
        $baseValue = is_array($lastPoint) ? $lastPoint['value'] : 0;
        $monthlyRate = $revaluationPercentage / 12 / 100;
        $loanProjection = $linkedLoanDetail !== null
            ? $this->loanAmortizationService->generateProjection($linkedLoanDetail, $monthsAhead)
            : [];

        $now = Carbon::now();
        $projected = [];

        for ($i = 1; $i <= $monthsAhead; $i++) {
            $projectedDate = $now->copy()->addMonthsNoOverflow($i)->endOfMonth();
            $projectedValue = (int) round($baseValue * pow(1 + $monthlyRate, $i));
            $point = $this->projectedPoint($account, $projectedDate, $projectedValue, $displayCurrencyCode);
            $mortgageProj = $loanProjection[$projectedDate->format('Y-m')] ?? null;

            if ($mortgageProj !== null) {
                $point['mortgage_balance'] = $this->convertBalanceForDate(
                    $linkedLoanAccount->currency_code,
                    $account->currency_code,
                    $mortgageProj,
                    $projectedDate,
                );

                if ($displayCurrencyCode !== null) {
                    $point['display_mortgage_balance'] = $this->convertBalanceForDate(
                        $linkedLoanAccount->currency_code,
                        $displayCurrencyCode,
                        $mortgageProj,
                        $projectedDate,
                    );
                }
            }

            $projected[] = $point;
        }

        return $projected;
    }

    /**
     * A single projected month. Its display value is converted at today's rate:
     * there is no exchange rate for a date that hasn't happened yet.
     *
     * @return array<string, mixed>
     */
    private function projectedPoint(Account $account, Carbon $projectedDate, int $value, ?string $displayCurrencyCode): array
    {
        $point = [
            'month' => $projectedDate->format('Y-m'),
            'timestamp' => $projectedDate->timestamp,
            'value' => $value,
            'projected' => true,
        ];

        if ($displayCurrencyCode !== null) {
            $point['display_value'] = $this->convertBalanceForDate(
                $account->currency_code,
                $displayCurrencyCode,
                $value,
                Carbon::today(),
            );
        }

        return $point;
    }

    /**
     * @param  list<array<string, mixed>>  $points
     */
    private function evolutionResponse(array $points, Account $account, ?string $displayCurrencyCode): JsonResponse
    {
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
