<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\Requests\Api\V1\DeleteBudgetRequest;
use App\Http\Requests\Api\V1\StoreBudgetRequest;
use App\Http\Requests\Api\V1\UpdateBudgetRequest;
use App\Http\Resources\V1\BudgetResource;
use App\Models\Budget;
use App\Services\BudgetService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Budgets', 'A budget is a spending limit per period over some of your categories and labels. Budgets cover your whole account, not a space, and only ever track your own categories and labels. Amounts are in the minor units of your account\'s currency, given as `currency`. A budget\'s period length, start day, rollover and what it tracks are fixed once it is created: to change them, delete the budget and create it again.', weight: 80)]
class BudgetController extends Controller
{
    public function __construct(private BudgetService $budgets) {}

    /**
     * List budgets
     *
     * Your budgets, newest first, each with the period in progress. Archived
     * budgets are left out; retrieve one by its id to read it.
     */
    #[Paginated(BudgetResource::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            Budget::query()->whereBelongsTo($this->user($request))->notArchived()->with([
                'categories:id,name',
                'labels:id,name',
                'currentPeriods' => fn (Relation $periods) => $periods->withSum('budgetTransactions', 'amount'),
            ]),
            $request,
            [['created_at', 'desc'], ['id', 'desc']],
            BudgetResource::class,
        );
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
        return new BudgetResource($this->budget($request, $budget));
    }

    /**
     * Create a budget
     *
     * The budget needs at least one category or label to track, or
     * `is_catch_all: true`. It starts with the period in progress and the one
     * before it. The transactions already in them are added in the
     * background, so `current_period.spent_amount` is provisional while
     * `current_period.processing_historical` is `true`: read the budget again
     * a few seconds later.
     */
    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = $this->budgets->create(
            $this->user($request),
            $request->budgetAttributes(),
            (int) $request->validated('allocated_amount'),
            $request->categoryIds(),
            $request->labelIds(),
        );

        return (new BudgetResource($budget->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Update a budget
     *
     * Changes only the fields you send: the name, the limit and the expense
     * type. A new `allocated_amount` applies to the period in progress and
     * every later one; past periods keep theirs. `period_type`,
     * `period_start_day`, `rollover_type`, `is_catch_all`, `category_ids` and
     * `label_ids` are fixed, since the budget's periods are calculated over
     * its history: sending any of them is a `422` under that field, and the
     * way to change them is to delete the budget and create it again. An
     * archived budget can no longer change: `422` under `budget`.
     *
     * @param  string  $budget  The budget's id.
     */
    public function update(UpdateBudgetRequest $request, string $budget): BudgetResource
    {
        return new BudgetResource($this->budgets->update(
            $request->budget(),
            $request->budgetAttributes(),
            $request->allocatedAmount(),
        )->refresh());
    }

    /**
     * Delete a budget
     *
     * Deletes the budget with its periods and their spending history,
     * archived budgets included. The transactions it tracked are untouched.
     *
     * @param  string  $budget  The budget's id.
     */
    public function destroy(DeleteBudgetRequest $request, string $budget): JsonResponse
    {
        $deleted = $request->budget();
        $this->budgets->delete($deleted);

        return response()->json([
            /**
             * The deleted budget's id.
             *
             * @format uuid
             */
            'id' => $deleted->id,
            /**
             * Always `true`.
             */
            'deleted' => true,
        ]);
    }
}
