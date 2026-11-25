<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Http\Requests\ReorderPlanningItemsRequest;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetPeriodRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetManagementService;
use App\Services\BudgetPeriodService;
use App\Services\SavingsGoals\MonthlySavingsGoalStats;
use App\Services\SavingsGoals\MonthlySavingsGoalTotals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BudgetPeriodService $budgetPeriodService,
        protected BudgetManagementService $budgetManagementService,
        protected MonthlySavingsGoalStats $monthlySavingsGoals,
        protected MonthlySavingsGoalTotals $monthlySavingsTotals,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $applicationDate = $user->localToday()->toImmutable();
        $activeSpaceId = $user->activeSpace()->id;
        $budgets = $user
            ->budgets()
            ->orderBy('position')
            ->orderBy('name')
            ->with(['categories', 'labels', 'periods' => function ($query) use ($applicationDate) {
                $query->covering($applicationDate)
                    // Same ordering as Budget::getCurrentPeriod, for the same
                    // reason: the card reads `periods[0]`, and where two periods
                    // cover today the earliest-starting one is the one the user
                    // never configured.
                    ->orderByDesc('start_date')
                    ->withSum('budgetTransactions as spent_amount', 'amount');
            }])
            ->get();

        $budgets->each(function (Budget $budget) use ($applicationDate, $activeSpaceId): void {
            if ($budget->space_id !== $activeSpaceId) {
                $budget->setAttribute('next_planning_period', null);

                return;
            }

            $nextPlanningPeriod = $budget->getNextPlanningPeriod($applicationDate, $budget->periods->first());
            $budget->setAttribute('next_planning_period', $nextPlanningPeriod === null ? null : [
                'id' => $nextPlanningPeriod->id,
                'start_date' => $nextPlanningPeriod->start_date,
                'end_date' => $nextPlanningPeriod->end_date,
                'allocated_amount' => $nextPlanningPeriod->allocated_amount,
            ]);
        });

        // Both kinds come from one query; each is presented its own way.
        [$monthlyGoals, $oneOffGoals] = $user->savingsGoals()->listed()->get()
            ->partition(fn (SavingsGoal $goal): bool => $goal->isMonthly());

        $presentedMonthlyGoals = $this->monthlySavingsGoals->present($monthlyGoals);

        return Inertia::render('budgets/index', [
            'budgets' => $budgets,
            'budgetSummary' => $this->buildBudgetSummary($budgets),
            'savingsGoals' => SavingsGoal::withStats($oneOffGoals),
            'monthlySavingsGoals' => $presentedMonthlyGoals,
            // Archived goals included, as on the cashflow card and the summary.
            'monthlySavingsLastMonth' => $this->monthlySavingsTotals->verdicts($presentedMonthlyGoals, $user->localToday()->subMonthNoOverflow()),
            // Only when the create dialog asks for them: the savings accounts of
            // the active space, the same set the server validates the auto-tag
            // account against, each with the running goal it already feeds.
            'autoTagAccounts' => Inertia::optional(fn () => $this->autoTagAccounts($user)),
            // What the create dialog's month-end reminder starts at.
            'savingsGoalReminderDefault' => $user->wantsSavingsGoalRemindersByDefault(),
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    /**
     * @return list<array{id: string, name: string, bank: array{name: string}|null, used_by: string|null}>
     */
    private function autoTagAccounts(User $user): array
    {
        $usedBy = SavingsGoal::query()
            ->where('user_id', $user->id)
            ->monthly()
            ->notArchived()
            ->whereNotNull('auto_tag_account_id')
            ->pluck('name', 'auto_tag_account_id');

        return Account::query()
            ->forSpace($user->activeSpace())
            ->where('user_id', $user->id)
            ->where('type', AccountType::Savings->value)
            ->whereNull('archived_at')
            ->with('bank:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'bank_id'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'bank' => $account->bank ? ['name' => $account->bank->name] : null,
                'used_by' => $usedBy->get($account->id),
            ])
            ->all();
    }

    /**
     * Build the overview from the same active periods shown by the index.
     * Catch-all budgets stay separate so they are not double-counted as an
     * additional allocation on top of category/label budgets.
     */
    private function buildBudgetSummary(Collection $budgets): array
    {
        $activeBudgets = $budgets
            ->map(function (Budget $budget): ?array {
                $period = $budget->periods->first();

                if (! $period) {
                    return null;
                }

                $allocated = (int) $period->allocated_amount;
                $carriedOver = (int) ($period->carried_over_amount ?? 0);
                $available = $allocated + $carriedOver;
                $spent = (int) ($period->spent_amount ?? 0);
                $status = $period->limitStatus($spent);
                $percentageUsed = $available > 0
                    ? round(($spent / $available) * 100, 1)
                    : 0;

                $period->setAttribute('status', $status);

                return [
                    'period_type' => $budget->period_type->value,
                    'is_catch_all' => (bool) $budget->is_catch_all,
                    'allocated_amount' => $allocated,
                    'carried_over_amount' => $carriedOver,
                    'available_amount' => $available,
                    'spent_amount' => $spent,
                    'remaining_amount' => $available - $spent,
                    'percentage_used' => $percentageUsed,
                    'status' => $status,
                ];
            })
            ->filter()
            ->values();

        $specificBudgets = $activeBudgets->where('is_catch_all', false)->values();
        $catchAllBudgets = $activeBudgets->where('is_catch_all', true)->values();
        $periodTypeOrder = collect(BudgetPeriodType::cases())
            ->mapWithKeys(fn (BudgetPeriodType $periodType, int $index): array => [
                $periodType->value => $index,
            ]);
        $groups = $specificBudgets
            ->groupBy('period_type')
            ->map(function (Collection $group, string $periodType): array {
                return ['period_type' => $periodType] + $this->aggregateBudgetStats($group);
            })
            ->sortBy(fn (array $group): int => $periodTypeOrder[$group['period_type']] ?? PHP_INT_MAX)
            ->values()
            ->all();

        return $this->aggregateBudgetStats($specificBudgets) + [
            'groups' => $groups,
            'catch_all' => $catchAllBudgets->isEmpty()
                ? null
                : $this->aggregateBudgetStats($catchAllBudgets),
        ];
    }

    private function aggregateBudgetStats(Collection $budgets): array
    {
        $totalAllocated = (int) $budgets->sum('allocated_amount');
        $totalCarriedOver = (int) $budgets->sum('carried_over_amount');
        $totalAvailable = (int) $budgets->sum('available_amount');
        $totalSpent = (int) $budgets->sum('spent_amount');

        return [
            'budgets_count' => $budgets->count(),
            'total_allocated' => $totalAllocated,
            'total_carried_over' => $totalCarriedOver,
            'total_available' => $totalAvailable,
            'total_spent' => $totalSpent,
            'total_remaining' => $totalAvailable - $totalSpent,
            'percentage_used' => $totalAvailable > 0
                ? round(($totalSpent / $totalAvailable) * 100, 1)
                : 0,
            'status' => BudgetPeriod::limitStatusFor($totalSpent, $totalAvailable),
            'over_limit_count' => $budgets
                ->where('status', 'over_limit')
                ->count(),
            'close_to_limit_count' => $budgets
                ->where('status', 'close_to_limit')
                ->count(),
        ];
    }

    /**
     * The period the detail page reads as current.
     *
     * An archived budget gets no new periods: it falls back to the last one it
     * had instead of minting one, so opening the page cannot resume a budget
     * that stopped counting.
     */
    private function activePeriodFor(Budget $budget, CarbonImmutable $applicationDate): BudgetPeriod
    {
        $activePeriod = $budget->getCurrentPeriod($applicationDate);

        if ($activePeriod !== null) {
            return $activePeriod;
        }

        return $budget->isArchived()
            ? $budget->periods()->orderByDesc('start_date')->firstOrFail()
            : $this->budgetPeriodService->generatePeriod($budget, null, $applicationDate);
    }

    public function show(Request $request, Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $user = $request->user();
        // The policy only lets the owner in: reuse them for the owner's today
        // rather than loading them again.
        $budget->setRelation('user', $user);
        $applicationDate = $budget->ownerToday()->toImmutable();
        $activePeriod = $this->activePeriodFor($budget, $applicationDate);
        $directSuccessor = $budget->getNextPlanningPeriod($applicationDate, $activePeriod);

        [$viewedPeriod, $isPlanningPeriod] = $this->resolveViewedPeriod(
            $request,
            $budget,
            $activePeriod,
            $directSuccessor,
            $applicationDate,
        );

        // The planning period has no spending yet by definition, so it is the
        // one view that does not pay for the transaction graph.
        if (! $isPlanningPeriod) {
            $viewedPeriod->load([
                'budgetTransactions.transaction.account.bank',
                'budgetTransactions.transaction.category',
                'budgetTransactions.transaction.labels',
                'budgetTransactions.transaction.splits.category',
            ]);

            Transaction::loadSplitSiblings($viewedPeriod->budgetTransactions->pluck('transaction')->filter());
        }

        $previousPeriod = $budget->periods()
            ->where('end_date', '<', $viewedPeriod->start_date)
            ->orderBy('end_date', 'desc')
            ->with(['budgetTransactions.transaction'])
            ->first();

        $budget->load(['categories', 'labels']);

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->where('space_id', $budget->space_id)
            ->forDisplay()
            ->get();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank')
            ->orderBy('name')
            ->get();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->where('space_id', $budget->space_id)
            ->orderBy('name')
            ->get();

        return Inertia::render('budgets/show', [
            'budget' => $budget,
            'currentPeriod' => $viewedPeriod,
            'previousPeriod' => $previousPeriod,
            'nextPeriod' => $this->nextPeriodFor($budget, $viewedPeriod, $activePeriod, $directSuccessor, $isPlanningPeriod, $applicationDate),
            'nextPlanningPeriod' => $isPlanningPeriod ? null : $directSuccessor,
            'is_planning_period' => $isPlanningPeriod,
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    /**
     * The period the page shows, and whether it is the one being planned.
     *
     * ?period= addresses a past period, or the single period after the current
     * one when that is still being planned. Anything else in the future is not
     * addressable: it does not exist for the user yet.
     *
     * @return array{0: BudgetPeriod, 1: bool}
     */
    private function resolveViewedPeriod(
        Request $request,
        Budget $budget,
        BudgetPeriod $activePeriod,
        ?BudgetPeriod $directSuccessor,
        CarbonImmutable $applicationDate,
    ): array {
        $periodId = $request->query('period');

        if (! $periodId) {
            return [$activePeriod, false];
        }

        $viewedPeriod = $budget->periods()->whereKey($periodId)->firstOrFail();

        // An archived budget has nothing left to plan, and a budget in another
        // space is not the one the user is working in.
        $canPlanThisBudget = ! $budget->isArchived()
            && $budget->space_id === $request->user()->activeSpace()->id;

        $isPlanningPeriod = $canPlanThisBudget
            && $directSuccessor !== null
            && $viewedPeriod->id === $directSuccessor->id;

        if ($viewedPeriod->start_date->greaterThan($applicationDate) && ! $isPlanningPeriod) {
            abort(404);
        }

        return [$viewedPeriod, $isPlanningPeriod];
    }

    /**
     * Where the "next" arrow points: nowhere from the planning period, which is
     * already the end of the chain; the planning period itself from the current
     * one; and otherwise the next period that has actually started.
     */
    private function nextPeriodFor(
        Budget $budget,
        BudgetPeriod $viewedPeriod,
        BudgetPeriod $activePeriod,
        ?BudgetPeriod $directSuccessor,
        bool $isPlanningPeriod,
        CarbonImmutable $applicationDate,
    ): ?BudgetPeriod {
        if ($isPlanningPeriod) {
            return null;
        }

        if ($viewedPeriod->id === $activePeriod->id) {
            return $directSuccessor;
        }

        return $budget->periods()
            ->where('start_date', '>', $viewedPeriod->end_date)
            ->whereDate('start_date', '<=', $applicationDate->toDateString())
            ->orderBy('start_date', 'asc')
            ->first();
    }

    /**
     * Persists the order of the Planning list, which mixes both types.
     *
     * A NULL position means "never dragged", so the client falls back to the
     * automatic ordering; one drag gives every live item a number and the list
     * is manual from then on.
     */
    public function reorder(ReorderPlanningItemsRequest $request): RedirectResponse
    {
        // ponytail: one update per row; fine for the handful of budgets and
        // goals a user has. Switch to a single CASE update if that ever grows.
        foreach (array_values($request->validated('items')) as $position => $item) {
            $model = $item['type'] === 'goal' ? SavingsGoal::class : Budget::class;

            $model::query()
                ->whereKey($item['id'])
                ->where('user_id', $request->user()->id)
                ->update(['position' => $position]);
        }

        return back();
    }

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $result = $this->budgetManagementService->create(
            $request->user(),
            $request->user()->activeSpace(),
            [
                'name' => $request->name,
                'period_type' => $request->period_type,
                'period_start_day' => $request->period_start_day,
                'rollover_type' => $request->rollover_type,
                'expense_type' => $request->enum('expense_type', BudgetExpenseType::class) ?? BudgetExpenseType::Variable,
                'is_catch_all' => $request->boolean('is_catch_all'),
                'allocated_amount' => $request->integer('allocated_amount'),
            ],
            $request->category_ids ?? [],
            $request->label_ids ?? [],
        );

        return redirect()->route('budgets.show', $result['budget']);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        $this->budgetManagementService->update(
            $request->user(),
            $budget->space,
            $budget->id,
            $request->only(['name', 'allocated_amount', 'category_ids', 'label_ids', 'expense_type']),
            $request->user()->localToday()->toImmutable(),
        );

        return redirect()->route('budgets.show', $budget);
    }

    public function updatePeriod(UpdateBudgetPeriodRequest $request, Budget $budget, BudgetPeriod $period): RedirectResponse
    {
        $this->authorize('update', $budget);

        $this->budgetManagementService->updateNextPeriodAllocation(
            $request->user(),
            $request->user()->activeSpace(),
            $budget->id,
            $period->id,
            $request->integer('allocated_amount'),
            $request->user()->localToday()->toImmutable(),
        );

        return redirect()->route('budgets.show', ['budget' => $budget, 'period' => $period]);
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        $this->budgetManagementService->delete(
            $request->user(),
            $request->user()->activeSpace(),
            $budget->id,
        );

        return redirect()->route('budgets.index');
    }

    /**
     * Archiving records the day it happened and is one-way: the budget turns
     * read-only, stops absorbing transactions and, for anything it does not
     * already hold, stops claiming its categories away from the catch-all, and
     * no further periods are generated for it. The periods it already has keep
     * their figures, which is why the budget stays reachable instead of being
     * deleted.
     *
     * The periods `GenerateBudgetPeriods` had already run ahead are left behind
     * on purpose: `show()` only navigates to periods that have started, so they
     * are already invisible and cleaning them up would buy nothing.
     */
    public function archive(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('archive', $budget);

        $this->budgetManagementService->archive($budget);

        // Named route, not back(): the dialog only exists on this page, and the
        // previous-url redirect resolves to the app's internal host behind a
        // proxy (same reason as SavingsGoalController::syncTransactions).
        return redirect()->route('budgets.show', $budget);
    }
}
