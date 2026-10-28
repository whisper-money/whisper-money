<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\StoreSavingsGoalRequest;
use App\Http\Requests\Api\V1\UpdateSavingsGoalRequest;
use App\Http\Resources\V1\SavingsGoalResource;
use App\Models\SavingsGoal;
use App\Services\SavingsGoals\SavingsGoalService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Savings goals', 'A savings goal is tracked through a label: its contributions are the transactions tagged with it. A `one_off` goal saves towards a total, optionally by a date. A `monthly` goal starts over every calendar month with a target that is a fixed `amount` or an `income_rate`, a share of the average income of the three previous complete months, frozen when the month opens. A goal\'s kind is fixed once created. Archiving and deleting goals happen in the Whisper Money app.', weight: 90)]
class SavingsGoalController extends Controller
{
    public function __construct(private SavingsGoalService $goals) {}

    /**
     * List savings goals
     *
     * The goals of a space, newest first, archived ones included. Every
     * member of a space sees all of its goals.
     */
    #[ScopedToSpace]
    #[Paginated(SavingsGoalResource::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            SavingsGoal::query()->forSpace($this->space($request))->with(['user:id,currency_code', 'label' => fn ($query) => $query->withTrashed()]),
            $request,
            [['created_at', 'desc'], ['id', 'desc']],
            SavingsGoalResource::class,
        );
    }

    /**
     * Retrieve a savings goal
     *
     * @param  string  $savings_goal  The goal's id.
     */
    #[ScopedToSpace]
    public function show(Request $request, string $savings_goal): SavingsGoalResource
    {
        return new SavingsGoalResource($this->inSpace($request, SavingsGoal::class, $savings_goal));
    }

    /**
     * Create a savings goal
     *
     * Creates the goal in the space, with a label of the same name to tag its
     * contributions with. A one-off goal needs `target_amount`; a monthly
     * goal needs `monthly_target_type` with its `monthly_target_amount` or
     * `monthly_target_rate`, and its first month opens right away. The
     * fields of the other kind are refused. A monthly goal given
     * `auto_tag_account_id` also gets an automation rule, listed with the
     * others, that tags the transfers into that savings account to the goal,
     * the ones already there since the start of this month included. The
     * rule goes last in your order, so an older rule that matches the same
     * transfers keeps them.
     */
    #[ScopedToSpace]
    public function store(StoreSavingsGoalRequest $request): JsonResponse
    {
        $goal = $this->goals->createFromInput($this->user($request), $request->validated(), $this->space($request));

        return (new SavingsGoalResource($goal->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Update a savings goal
     *
     * Changes only the fields you send, and only the fields of the goal's
     * kind: the others, and `kind` itself, are refused. A new name renames
     * the goal's label too. A new monthly target applies to the month in
     * progress and later ones, never to the months already over. Only the
     * member of the space who created the goal can change it, and an
     * archived goal can no longer change: `422` under `savings_goal`.
     *
     * @param  string  $savings_goal  The goal's id.
     */
    #[ScopedToSpace]
    public function update(UpdateSavingsGoalRequest $request, string $savings_goal): SavingsGoalResource
    {
        return new SavingsGoalResource($this->goals->update($request->savingsGoal(), $request->validated())->refresh());
    }
}
