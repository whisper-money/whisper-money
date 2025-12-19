<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Budget;
use App\Services\BudgetPeriodService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BudgetPeriodService $budgetPeriodService)
    {
    }

    public function index(Request $request): Response
    {
        $budgets = $request->user()
            ->budgets()
            ->with(['category', 'label', 'periods' => function ($query) {
                $query->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->with(['budgetTransactions']);
            }])
            ->get();

        return Inertia::render('budgets/index', [
            'budgets' => $budgets,
        ]);
    }

    public function show(Request $request, Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $currentPeriod = $budget->getCurrentPeriod();

        if (! $currentPeriod) {
            $currentPeriod = $this->budgetPeriodService->generatePeriod($budget);
        }

        $currentPeriod->load(['budgetTransactions']);

        $budget->load(['category', 'label']);

        return Inertia::render('budgets/show', [
            'budget' => $budget,
            'currentPeriod' => $currentPeriod,
        ]);
    }

    public function store(StoreBudgetRequest $request): \Illuminate\Http\RedirectResponse
    {
        $budget = DB::transaction(function () use ($request) {
            $budget = $request->user()->budgets()->create([
                'name' => $request->name,
                'period_type' => $request->period_type,
                'period_duration' => $request->period_duration,
                'period_start_day' => $request->period_start_day,
                'category_id' => $request->category_id,
                'label_id' => $request->label_id,
                'rollover_type' => $request->rollover_type,
            ]);

            $period = $this->budgetPeriodService->generatePeriod($budget, $request->allocated_amount);

            return $budget;
        });

        return redirect()->route('budgets.show', $budget);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $budget);

        DB::transaction(function () use ($request, $budget) {
            $budget->update($request->only([
                'name',
                'period_type',
                'period_duration',
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

    public function destroy(Request $request, Budget $budget): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return redirect()->route('budgets.index');
    }
}
