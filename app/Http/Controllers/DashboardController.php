<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AccountMetricsService;
use App\Services\CashflowSummaryService;
use App\Services\CategorySpendingService;
use App\Services\LabelSpendingService;
use App\Services\MonthlySummary\EmailPresenter;
use App\Services\PeriodComparator;
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
        private EmailPresenter $presenter,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'showEncryptionPrompt' => session('show_encryption_prompt', false),
            'monthlySummary' => Inertia::defer(fn () => $this->latestSummary($request), 'dashboard'),
            'netWorthEvolution' => Inertia::defer(fn () => $this->getNetWorthEvolution($request), 'dashboard'),
            'topCategories' => Inertia::defer(fn () => $this->getTopCategories($request), 'dashboard'),
            'topLabels' => Inertia::defer(fn () => $this->getTopLabels($request), 'dashboard'),
            'cashflowSummary' => Inertia::defer(fn () => $this->getCashflowSummary($request), 'dashboard'),
        ]);
    }

    /**
     * The newest sent summary, for the notice at the top of the dashboard.
     *
     * Deferred with the rest of the page's data: a notice is not worth two
     * queries on the first paint, and it rides the follow-up request the
     * dashboard already makes rather than adding one of its own.
     *
     * @return array<string, mixed>|null
     */
    private function latestSummary(Request $request): ?array
    {
        $summary = $request->user()->monthlySummaries()
            ->whereNotNull('sent_at')
            ->orderByDesc('period')
            ->first();

        if ($summary === null) {
            return null;
        }

        $locale = app()->getLocale();

        return [
            'id' => $summary->id,
            'monthLabel' => $summary->periodStart()->locale($locale)->isoFormat('MMMM'),
            'headline' => $this->presenter->present($summary, $locale)['headline'],
        ];
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
