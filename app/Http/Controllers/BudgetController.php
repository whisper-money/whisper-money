<?php

namespace App\Http\Controllers;

use App\Features\SavingsGoals;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Services\BudgetPeriodService;
use App\Services\BudgetService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class BudgetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BudgetPeriodService $budgetPeriodService,
        protected BudgetService $budgetService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $budgets = $user
            ->budgets()
            ->with(['categories', 'labels', 'periods' => function ($query) {
                // Same ordering as Budget::getCurrentPeriod, for the same
                // reason: the card reads `periods[0]`, and where two periods
                // cover today the earliest-starting one is the one the user
                // never configured.
                $query->where('start_date', '<=', today())
                    ->where('end_date', '>=', today())
                    ->orderByDesc('start_date')
                    ->with(['budgetTransactions']);
            }])
            ->get();

        $savingsGoalsEnabled = Feature::active(SavingsGoals::class);

        return Inertia::render('budgets/index', [
            'budgets' => $budgets,
            'savingsGoals' => $savingsGoalsEnabled ? SavingsGoal::withStatsForUser($user) : [],
            'savingsGoalsEnabled' => $savingsGoalsEnabled,
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    public function show(Request $request, Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $user = $request->user();

        $viewedPeriod = $this->periodToView($request, $budget);

        $viewedPeriod->load([
            'budgetTransactions.transaction.account.bank',
            'budgetTransactions.transaction.category',
            'budgetTransactions.transaction.labels',
        ]);

        Transaction::loadSplitSiblings($viewedPeriod->budgetTransactions->pluck('transaction')->filter());

        $previousPeriod = $budget->periods()
            ->where('end_date', '<', $viewedPeriod->start_date)
            ->orderBy('end_date', 'desc')
            ->with(['budgetTransactions.transaction'])
            ->first();

        $nextPeriod = $budget->periods()
            ->where('start_date', '>', $viewedPeriod->end_date)
            ->where('start_date', '<=', today())
            ->orderBy('start_date', 'asc')
            ->first();

        $budget->load(['categories', 'labels']);

        $categories = Category::query()
            ->where('user_id', $user->id)
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
            ->orderBy('name')
            ->get();

        return Inertia::render('budgets/show', [
            'budget' => $budget,
            'currentPeriod' => $viewedPeriod,
            'previousPeriod' => $previousPeriod,
            'nextPeriod' => $nextPeriod,
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    /**
     * The period the page shows: the one explicitly asked for (scoped to this
     * budget, past or current only), or the one covering today.
     *
     * An archived budget gets no new periods, so it falls back to the last one
     * it had instead of minting one — visiting the page must not resume a budget
     * that stopped counting.
     */
    private function periodToView(Request $request, Budget $budget): BudgetPeriod
    {
        $periodId = $request->query('period');

        if ($periodId) {
            return $budget->periods()
                ->where('id', $periodId)
                ->where('start_date', '<=', today())
                ->firstOrFail();
        }

        $viewedPeriod = $budget->getCurrentPeriod()
            ?? ($budget->isArchived() ? $budget->periods()->orderByDesc('start_date')->first() : null);

        // Same anchoring as the scheduled command: without an explicit start
        // date this picks up where the chain ends, which can be in the past -
        // showing a stale period as the current one and appending another row on
        // every visit. A budget always has at least the period it was created
        // with, so this is only reached by one that is still running.
        return $viewedPeriod ?? $this->budgetPeriodService->generatePeriod($budget, null, today());
    }

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $budget = $this->budgetService->create(
            $request->user(),
            [
                'name' => $request->name,
                'period_type' => $request->period_type,
                'period_start_day' => $request->period_start_day,
                'rollover_type' => $request->rollover_type,
                'is_catch_all' => $request->boolean('is_catch_all'),
            ],
            (int) $request->allocated_amount,
            $request->category_ids ?? [],
            $request->label_ids ?? [],
        );

        return redirect()->route('budgets.show', $budget);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        $this->budgetService->update(
            $budget,
            $request->only(['name', 'period_type', 'period_start_day', 'rollover_type']),
            $request->has('allocated_amount') ? (int) $request->allocated_amount : null,
        );

        return redirect()->route('budgets.show', $budget);
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return redirect()->route('budgets.index');
    }

    /**
     * Archiving records the day it happened and is one-way: the budget turns
     * read-only, stops absorbing transactions and stops claiming its categories
     * away from the catch-all, and no further periods are generated for it. The
     * periods it already has keep their figures, which is why the budget stays
     * reachable instead of being deleted.
     *
     * The periods `GenerateBudgetPeriods` had already run ahead are left behind
     * on purpose: `show()` only navigates to periods that have started, so they
     * are already invisible and cleaning them up would buy nothing.
     */
    public function archive(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('archive', $budget);

        $budget->update(['archived_at' => now()]);

        // Named route, not back(): the dialog only exists on this page, and the
        // previous-url redirect resolves to the app's internal host behind a
        // proxy (same reason as SavingsGoalController::syncTransactions).
        return redirect()->route('budgets.show', $budget);
    }
}
