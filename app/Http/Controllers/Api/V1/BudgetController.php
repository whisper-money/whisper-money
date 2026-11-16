<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\Listings;
use App\Http\Requests\Api\V1\DeleteBudgetRequest;
use App\Http\Requests\Api\V1\StoreBudgetRequest;
use App\Http\Requests\Api\V1\UpdateBudgetPeriodRequest;
use App\Http\Requests\Api\V1\UpdateBudgetRequest;
use App\Http\Resources\V1\BudgetPeriodAllocationResource;
use App\Http\Resources\V1\BudgetResource;
use App\Models\Budget;
use App\Services\BudgetManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BudgetController extends Controller
{
    public function __construct(private BudgetManagementService $budgets) {}

    /**
     * List budgets
     *
     * Your budgets in a space, newest first, each with the period in progress.
     * Archived budgets are left out; retrieve one by its id to read it. As in
     * the app, another member's budgets are not listed.
     */
    public function index(Request $request, Listings $listings): AnonymousResourceCollection
    {
        return $listings->budgets($this->user($request), $this->space($request))->paginate($request);
    }

    /**
     * Retrieve a budget
     *
     * Archived budgets included.
     *
     * @param  string  $budget  The budget's id.
     */
    public function show(Request $request, string $budget): BudgetResource
    {
        return new BudgetResource($this->inSpace($request, Budget::class, $budget));
    }

    /**
     * Create a budget
     *
     * The budget needs at least one category or label to track, or
     * `is_catch_all: true`. It starts with the period in progress and the one
     * before it. The transactions already in them are added in the background,
     * so `current_period.spent_amount` is provisional while
     * `current_period.processing_historical` is `true`.
     */
    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $created = $this->budgets->create(
            $this->user($request),
            $this->space($request),
            $request->budgetAttributes(),
            $request->categoryIds(),
            $request->labelIds(),
        );

        return (new BudgetResource($created['budget']))->response()->setStatusCode(201);
    }

    /**
     * Update a budget
     *
     * Changes only the fields you send: the name, the limit, the expense type
     * and what it tracks. A new `allocated_amount` applies from today to every
     * later period, except that a period holding a one-off amount keeps it and
     * only its regular allocation moves. A tracking change keeps closed
     * periods as they are and recalculates the active one in the background.
     * `period_type`, `period_start_day`, `rollover_type` and `is_catch_all`
     * are fixed. An archived budget can no longer change: `422` under `budget`.
     *
     * @param  string  $budget  The budget's id.
     */
    public function update(UpdateBudgetRequest $request, string $budget): BudgetResource
    {
        return new BudgetResource($this->budgets->update(
            $this->user($request),
            $this->space($request),
            $request->budget()->id,
            $request->changes(),
            $this->user($request)->localToday()->toImmutable(),
        )['budget']);
    }

    /**
     * Update a single period
     *
     * Sets the allocation of the one period that contains `date`, the one in
     * progress or a future one up to 12 months ahead, and leaves every other
     * period as it is: a one-off month. `reset: true`, instead of an amount,
     * puts the period back on the regular allocation.
     *
     * @param  string  $budget  The budget's id.
     */
    public function updatePeriod(UpdateBudgetPeriodRequest $request, string $budget): BudgetPeriodAllocationResource
    {
        return new BudgetPeriodAllocationResource($this->budgets->updatePeriodAllocation(
            $this->user($request),
            $this->space($request),
            $request->budget()->id,
            $request->periodDate(),
            $request->oneOffAmount(),
            $this->user($request)->localToday()->toImmutable(),
        ));
    }

    /**
     * Delete a budget
     *
     * Archived budgets included. The transactions it tracked are untouched.
     *
     * @param  string  $budget  The budget's id.
     */
    public function destroy(DeleteBudgetRequest $request, string $budget): JsonResponse
    {
        return response()->json([
            'id' => $this->budgets->delete($this->user($request), $this->space($request), $request->budget()->id),
            'deleted' => true,
        ]);
    }
}
