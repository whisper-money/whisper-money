<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Services\AccountMetricsService;
use App\Services\CashflowSummaryService;
use App\Services\CategorySpendingService;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\LabelSpendingService;
use App\Services\MonthlySummary\ReportPresenter;
use App\Services\PeriodComparator;
use App\Services\SavingsGoals\MonthlySavingsGoalStats;
use App\Services\SavingsGoals\MonthlySavingsGoalTotals;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private AccountMetricsService $accountMetricsService,
        private CategorySpendingService $categorySpendingService,
        private LabelSpendingService $labelSpendingService,
        private CashflowSummaryService $summaries,
        private ReportPresenter $presenter,
        private CreditCardStatementService $creditCardStatementService,
        private MonthlySavingsGoalStats $monthlySavingsGoals,
        private MonthlySavingsGoalTotals $monthlySavingsTotals,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'monthlySummary' => Inertia::defer(fn () => $this->latestSummary($request), 'dashboard'),
            'netWorthEvolution' => Inertia::defer(fn () => $this->getNetWorthEvolution($request), 'dashboard'),
            'topCategories' => Inertia::defer(fn () => $this->getTopCategories($request), 'dashboard'),
            'topLabels' => Inertia::defer(fn () => $this->getTopLabels($request), 'dashboard'),
            'cashflowSummary' => Inertia::defer(fn () => $this->getCashflowSummary($request), 'dashboard'),
            ...$this->creditCardStatementService->isAvailableTo($request->user()) ? [
                'creditCardUsage' => Inertia::defer(fn () => $this->getCreditCardUsage($request), 'dashboard'),
            ] : [],
            'monthlySavingsGoals' => Inertia::defer(fn () => $this->runningMonthlyGoals($request), 'dashboard'),
            // Archived goals included: the same count the cashflow card and
            // the monthly summary give for that month.
            'monthlySavingsLastMonth' => Inertia::defer(fn () => $this->monthlySavingsTotals->verdicts(
                $this->monthlySavingsGoals->presentForUser($request->user()),
                today()->subMonthNoOverflow(),
            ), 'dashboard'),
        ]);
    }

    /**
     * The newest sent summary, for the notice at the top of the dashboard.
     *
     * Deferred with the rest of the page's data: a notice is not worth two
     * queries on the first paint, and it rides the follow-up request the
     * dashboard already makes rather than adding one of its own.
     *
     * One notice at most, and only for the newest month. Dismissing it hides
     * the notice entirely rather than surfacing an older undismissed month:
     * "your February summary is ready" in April reads as a bug, not a reminder.
     *
     * @return array<string, mixed>|null
     */
    private function latestSummary(Request $request): ?array
    {
        $summary = $request->user()->monthlySummaries()
            ->whereNotNull('sent_at')
            ->orderByDesc('period')
            ->first();

        if ($summary === null || $summary->dismissed_at !== null) {
            return null;
        }

        $locale = app()->getLocale();

        return [
            'id' => $summary->id,
            'monthLabel' => $summary->periodStart()->locale($locale)->isoFormat('MMMM'),
            'headline' => $this->presenter->headline($summary, $locale),
        ];
    }

    /**
     * The monthly goals still running, for this month's card. An archived goal
     * has nothing left to save towards. The card only needs this month, so
     * that is all it gets: no history, no goal fields it does not draw.
     *
     * @return list<array{id: string, name: string, monthly: array{current: array<string, mixed>|null}}>
     */
    private function runningMonthlyGoals(Request $request): array
    {
        return array_map(fn (array $goal): array => [
            'id' => $goal['id'],
            'name' => $goal['name'],
            'monthly' => ['current' => $goal['monthly']['current']],
        ], $this->monthlySavingsGoals->present(
            $request->user()->savingsGoals()->monthly()->notArchived()->listed()->get(),
        ));
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
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return $this->accountMetricsService->getNetWorthEvolution($user->currency_code, $accounts, $start, $end);
    }

    /**
     * What is in use of each live credit card, which its dashboard card shows
     * instead of a balance. Archived cards have no card to show it on.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getCreditCardUsage(Request $request): array
    {
        $creditCards = Account::query()
            ->where('user_id', $request->user()->id)
            ->where('type', AccountType::CreditCard)
            ->notArchived()
            ->with('creditCardDetail')
            ->get();

        return $this->creditCardStatementService->usageByAccount($creditCards, $request->user());
    }

    private function getTopCategories(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $from = $now->copy()->subDays(30);
        $to = $now->copy();

        $period = new PeriodComparator($from, $to);
        $previousPeriod = $period->previous();

        $currentSpending = $this->categorySpendingService->forPeriod($user->id, $period->from, $period->to);
        $previousSpending = $this->categorySpendingService->forPeriod($user->id, $previousPeriod->from, $previousPeriod->to);

        $totalAmount = $currentSpending->sum('amount');

        return $currentSpending
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
            ->values()
            ->all();
    }

    private function getTopLabels(Request $request): array
    {
        $now = Carbon::now();

        return $this->labelSpendingService->topForPeriod(
            $request->user()->id,
            new PeriodComparator($now->copy()->subDays(30), $now),
        );
    }

    private function getCashflowSummary(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $period = new PeriodComparator($now->copy()->startOfMonth(), $now->copy()->endOfMonth());

        return $this->summaries->forComparedPeriods($user->id, $user->currency_code, $period, $period->previous());
    }
}
