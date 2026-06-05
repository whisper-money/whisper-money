<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Services\BudgetPeriodService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BudgetPeriodService $budgetPeriodService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $budgets = $user
            ->budgets()
            ->with(['categories', 'labels', 'periods' => function ($query) {
                $query->where('start_date', '<=', today())
                    ->where('end_date', '>=', today())
                    ->with(['budgetTransactions']);
            }])
            ->get();

        return Inertia::render('budgets/index', [
            'budgets' => $budgets,
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    public function show(Request $request, Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $user = $request->user();

        // If a specific period UUID is requested, load it (scoped to this budget, past/current only)
        $periodId = $request->query('period');
        if ($periodId) {
            $viewedPeriod = $budget->periods()
                ->where('id', $periodId)
                ->where('start_date', '<=', today())
                ->firstOrFail();
        } else {
            $viewedPeriod = $budget->getCurrentPeriod();

            if (! $viewedPeriod) {
                $viewedPeriod = $this->budgetPeriodService->generatePeriod($budget);
            }
        }

        $viewedPeriod->load([
            'budgetTransactions.transaction.account.bank',
            'budgetTransactions.transaction.category',
            'budgetTransactions.transaction.labels',
        ]);

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
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id']);

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

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

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $result = DB::transaction(function () use ($request) {
            $budget = $request->user()->budgets()->create([
                'name' => $request->name,
                'period_type' => $request->period_type,
                'period_start_day' => $request->period_start_day,
                'rollover_type' => $request->rollover_type,
            ]);

            $budget->categories()->sync($request->category_ids ?? []);
            $budget->labels()->sync($request->label_ids ?? []);

            $period = $this->budgetPeriodService->generatePeriod($budget, $request->allocated_amount, null, true);
            $previousPeriod = $this->budgetPeriodService->generatePreviousPeriod($budget, $period, $request->allocated_amount, true);

            return ['budget' => $budget, 'period' => $period, 'previousPeriod' => $previousPeriod];
        });

        // Dispatch jobs to assign historical transactions for the current and previous periods
        AssignHistoricalTransactionsToBudget::dispatch($result['budget'], $result['period']);
        AssignHistoricalTransactionsToBudget::dispatch($result['budget'], $result['previousPeriod']);

        return redirect()->route('budgets.show', $result['budget']);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        DB::transaction(function () use ($request, $budget) {
            $budget->update($request->only([
                'name',
                'period_type',
                'period_start_day',
                'rollover_type',
            ]));

            // If allocated_amount is provided, update current and future periods
            if ($request->has('allocated_amount')) {
                $budget->periods()
                    ->where('start_date', '>=', now()->startOfDay())
                    ->update(['allocated_amount' => $request->allocated_amount]);
            }
        });

        return redirect()->route('budgets.show', $budget);
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return redirect()->route('budgets.index');
    }
}
