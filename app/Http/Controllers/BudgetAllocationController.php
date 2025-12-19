<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAllocationsRequest;
use App\Models\BudgetPeriod;
use App\Services\BudgetAllocationService;
use Illuminate\Http\Request;
use Inertia\Response;

class BudgetAllocationController extends Controller
{
    public function __construct(protected BudgetAllocationService $allocationService)
    {
    }

    public function show(Request $request, BudgetPeriod $period): Response
    {
        $this->authorize('view', $period->budget);

        $period->load([
            'allocations.budgetCategory.category',
            'allocations.budgetCategory.labels',
            'allocations.budgetTransactions',
        ]);

        $availableToAssign = $this->allocationService->calculateAvailableToAssign($period);

        return response()->json([
            'period' => $period,
            'available_to_assign' => $availableToAssign,
        ]);
    }

    public function update(UpdateAllocationsRequest $request, BudgetPeriod $period): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $period->budget);

        $this->allocationService->bulkAllocate($period, $request->allocations);

        return back();
    }
}

