<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Budget;
use App\Models\BudgetCategory;
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
            ->with(['budgetCategories.category', 'periods' => function ($query) {
                $query->where('start_date', '<=', now())
                    ->where('end_date', '>=', now())
                    ->with(['allocations.budgetTransactions']);
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

        $budget->load([
            'budgetCategories.category',
            'budgetCategories.labels',
            'periods' => function ($query) use ($currentPeriod) {
                $query->where('id', $currentPeriod->id)
                    ->with(['allocations.budgetCategory.category', 'allocations.budgetTransactions']);
            },
        ]);

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
            ]);

            foreach ($request->categories as $categoryData) {
                $budgetCategory = $budget->budgetCategories()->create([
                    'category_id' => $categoryData['category_id'],
                    'rollover_type' => $categoryData['rollover_type'],
                ]);

                if (! empty($categoryData['label_ids'])) {
                    $budgetCategory->labels()->attach($categoryData['label_ids']);
                }
            }

            $period = $this->budgetPeriodService->generatePeriod($budget);

            foreach ($request->categories as $categoryData) {
                $budgetCategory = $budget->budgetCategories()
                    ->where('category_id', $categoryData['category_id'])
                    ->first();

                if ($budgetCategory) {
                    $period->allocations()->create([
                        'budget_category_id' => $budgetCategory->id,
                        'allocated_amount' => $categoryData['allocated_amount'],
                    ]);
                }
            }

            return $budget;
        });

        return redirect()->route('budgets.show', $budget);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $budget);

        DB::transaction(function () use ($request, $budget) {
            $budget->update($request->only(['name', 'period_type', 'period_duration', 'period_start_day']));

            if ($request->has('categories')) {
                $budget->budgetCategories()->delete();

                foreach ($request->categories as $categoryData) {
                    $budgetCategory = $budget->budgetCategories()->create([
                        'category_id' => $categoryData['category_id'],
                        'rollover_type' => $categoryData['rollover_type'],
                    ]);

                    if (! empty($categoryData['label_ids'])) {
                        $budgetCategory->labels()->sync($categoryData['label_ids']);
                    }
                }
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

