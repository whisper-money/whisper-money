<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use App\Services\AccountMetricsService;
use App\Services\CashflowSummaryService;
use App\Services\CategorySpendingService;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\LabelSpendingService;
use App\Services\MonthlySummary\ReportPresenter;
use App\Services\PeriodComparator;
use App\Services\SavingsGoals\CurrentMonthSavings;
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
        private CurrentMonthSavings $currentMonthSavings,
    ) {}

    public function __invoke(Request $request): Response
    {
        $selectedDashboard = $this->selectedDashboard($request);

        return Inertia::render('dashboard', [
            // The user's own dashboards and the one on screen, with its widgets.
            // Null is the system dashboard, which the page still draws until
            // the dashboard picker ships.
            'dashboards' => fn (): array => $request->user()->dashboards()
                ->orderBy('position')
                ->orderBy('created_at')
                ->get(['id', 'name', 'position'])
                ->map(fn (Dashboard $dashboard): array => $dashboard->only(['id', 'name', 'position']))
                ->all(),
            'defaultDashboardId' => fn (): ?string => $request->user()->default_dashboard_id,
            'selectedDashboard' => fn (): ?array => $selectedDashboard === null ? null : $this->presentDashboard($selectedDashboard),
            // The window the top categories and top labels cards report on, so
            // their links and drill-downs ask for the same days the server summed.
            'spendingWindow' => fn (): array => $this->lastThirtyDays($request->user())->toDateStrings(),
            'monthlySummary' => Inertia::defer(fn () => $this->latestSummary($request), 'dashboard'),
            'netWorthEvolution' => Inertia::defer(fn () => $this->getNetWorthEvolution($request), 'dashboard'),
            'topCategories' => Inertia::defer(fn () => $this->getTopCategories($request), 'dashboard'),
            'topLabels' => Inertia::defer(fn () => $this->getTopLabels($request), 'dashboard'),
            'cashflowSummary' => Inertia::defer(fn () => $this->getCashflowSummary($request), 'dashboard'),
            'creditCardUsage' => Inertia::defer(fn () => $this->getCreditCardUsage($request), 'dashboard'),
            'monthlySavingsGoals' => Inertia::defer(fn () => $this->currentMonthSavings->runningGoals($request->user()), 'dashboard'),
            'monthlySavingsLastMonth' => Inertia::defer(fn () => $this->currentMonthSavings->lastMonth($request->user()), 'dashboard'),
        ]);
    }

    /**
     * The dashboard asked for in `?dashboard=`, else the user's default. Null
     * is the system dashboard. Another user's dashboard, or one that does not
     * exist, is a 404 rather than a quiet fallback: the link is wrong.
     */
    private function selectedDashboard(Request $request): ?Dashboard
    {
        $user = $request->user();
        $requested = $request->query('dashboard');

        if (is_string($requested) && $requested !== '') {
            return $user->dashboards()->findOrFail($requested);
        }

        return $user->default_dashboard_id === null ? null : $user->defaultDashboard;
    }

    /**
     * @return array{id: string, name: string, widgets: list<array{id: string, system_widget: string|null, widget_definition_id: string|null, position: int, width: string, height: string, config: array<string, mixed>|null}>}
     */
    private function presentDashboard(Dashboard $dashboard): array
    {
        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'widgets' => $dashboard->widgets()
                ->orderBy('position')
                ->get()
                ->map(fn (DashboardWidget $widget): array => [
                    'id' => $widget->id,
                    'system_widget' => $widget->system_widget?->value,
                    'widget_definition_id' => $widget->widget_definition_id,
                    'position' => $widget->position,
                    'width' => $widget->width->value,
                    'height' => $widget->height->value,
                    'config' => $widget->config,
                ])
                ->values()
                ->all(),
        ];
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
     * The last 12 months up to the user's own current month, not the server's
     * UTC one: at 21:00 on 31 October in Buenos Aires the server is already in
     * November, and the chart would end on a November that repeats October.
     */
    private function getNetWorthEvolution(Request $request): array
    {
        $user = $request->user();
        $today = $user->localToday();
        $start = $today->copy()->subMonths(12);
        $end = $today->copy()->endOfDay();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['bank:id,name,logo', 'realEstateDetail:account_id,linked_loan_account_id'])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return $this->accountMetricsService->getNetWorthEvolution($user->currency_code, $accounts, $start, $end, $today);
    }

    /**
     * What is in use of each live credit card, keyed by account id, which its
     * dashboard card shows instead of a balance.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getCreditCardUsage(Request $request): array
    {
        return collect($this->creditCardStatementService->liveUsageFor($request->user()))
            ->mapWithKeys(fn (array $card): array => [$card['account']->id => $card['usage']])
            ->all();
    }

    private function getTopCategories(Request $request): array
    {
        $user = $request->user();
        $period = $this->lastThirtyDays($user);
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
        return $this->labelSpendingService->topForPeriod(
            $request->user()->id,
            $this->lastThirtyDays($request->user()),
        );
    }

    /**
     * The owner's current month, read on their own clock: at 21:00 on 31
     * October in Buenos Aires the server is already in November, and the card
     * would show an empty November instead of the month still in progress.
     */
    private function getCashflowSummary(Request $request): array
    {
        $user = $request->user();
        $today = $user->localToday();
        $period = new PeriodComparator($today->copy()->startOfMonth(), $today->copy()->endOfMonth());

        return $this->summaries->forComparedPeriods($user->id, $user->currency_code, $period, $period->previous());
    }

    /**
     * The 30 days ending on the owner's today ({@see User::localToday()}), both
     * ends included, for the top categories and top labels cards. Whole days:
     * `transaction_date` is a date, and the server's UTC date is a day off for
     * American users every evening and for Asian ones every morning.
     */
    private function lastThirtyDays(User $user): PeriodComparator
    {
        $today = $user->localToday();

        return new PeriodComparator($today->copy()->subDays(29), $today);
    }
}
