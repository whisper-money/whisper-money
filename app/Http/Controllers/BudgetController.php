<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\Category;
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
            ->with(['category', 'label', 'periods' => function ($query) {
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

        $budget->load(['category', 'label']);

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color']);

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code']);

        $banks = Bank::query()
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        return Inertia::render('budgets/show', [
            'budget' => $budget,
            'currentPeriod' => $viewedPeriod,
            'previousPeriod' => $previousPeriod,
            'nextPeriod' => $nextPeriod,
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
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
                'category_id' => $request->category_id,
                'label_id' => $request->label_id,
                'rollover_type' => $request->rollover_type,
            ]);

            $period = $this->budgetPeriodService->generatePeriod($budget, $request->allocated_amount, null, true);

            return ['budget' => $budget, 'period' => $period];
        });

        // Dispatch job to assign historical transactions
        AssignHistoricalTransactionsToBudget::dispatch($result['budget'], $result['period']);

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
                'category_id',
                'label_id',
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
