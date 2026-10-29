<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\ApplyAutomationRuleRequest;
use App\Http\Requests\Api\V1\DeleteAutomationRuleRequest;
use App\Http\Requests\Api\V1\StoreAutomationRuleRequest;
use App\Http\Requests\Api\V1\UpdateAutomationRuleRequest;
use App\Http\Resources\V1\AutomationRuleResource;
use App\Http\Resources\V1\RuleApplicationResource;
use App\Models\AutomationRule;
use App\Services\AutomationRuleApplier;
use App\Services\AutomationRules\AutomationRuleWriter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Automation rules', 'An automation rule gives a category, labels and a note to the transactions that meet its conditions. Rules are evaluated in `priority` order, lowest first, and a new transaction gets the actions of the first rule it matches and no other. A rule only runs on transactions created after it: run it over the ones already there with the apply endpoint.', weight: 70)]
class AutomationRuleController extends Controller
{
    public function __construct(private AutomationRuleWriter $writer) {}

    /**
     * List automation rules
     *
     * Your rules in a space in the order they are evaluated: `priority`
     * ascending, then `title`. As in the app, another member's rules are not
     * listed.
     */
    #[ScopedToSpace]
    #[Paginated(AutomationRuleResource::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            $this->owned($request, AutomationRule::query())->with('labels:id'),
            $request,
            [['priority', 'asc'], ['title', 'asc'], ['id', 'asc']],
            AutomationRuleResource::class,
        );
    }

    /**
     * Retrieve an automation rule
     *
     * @param  string  $automation_rule  The rule's id.
     */
    #[ScopedToSpace]
    public function show(Request $request, string $automation_rule): AutomationRuleResource
    {
        return new AutomationRuleResource($this->inSpace($request, AutomationRule::class, $automation_rule));
    }

    /**
     * Create an automation rule
     *
     * The rule needs at least one action: `action_category_id`,
     * `action_label_ids` or both. It only runs on the transactions created
     * from now on. Conditions that say something other than what you meant
     * still save and then quietly match nothing, so preview a new rule with
     * the apply endpoint before trusting it.
     */
    #[ScopedToSpace]
    public function store(StoreAutomationRuleRequest $request): JsonResponse
    {
        $rule = $this->writer->create(
            $this->user($request),
            $this->space($request),
            $request->ruleAttributes(),
            $request->labelIds() ?? [],
        );

        return (new AutomationRuleResource($rule->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Update an automation rule
     *
     * Changes only the fields you send. `conditions` and `action_label_ids`
     * replace the rule's whole set. The rule must keep at least one action, a
     * category or labels: leaving it without one is a `422` under
     * `action_category_id`. The transactions it already touched keep what it gave
     * them.
     *
     * @param  string  $automation_rule  The rule's id.
     */
    #[ScopedToSpace]
    public function update(UpdateAutomationRuleRequest $request, string $automation_rule): AutomationRuleResource
    {
        return new AutomationRuleResource($this->writer->update(
            $request->automationRule(),
            $request->ruleAttributes(),
            $request->labelIds(),
        ));
    }

    /**
     * Delete an automation rule
     *
     * The rule stops running on new transactions. The transactions it already
     * categorized or labelled keep their category and labels.
     *
     * @param  string  $automation_rule  The rule's id.
     */
    #[ScopedToSpace]
    public function destroy(DeleteAutomationRuleRequest $request, string $automation_rule): JsonResponse
    {
        $rule = $request->automationRule();
        $rule->delete();

        return response()->json([
            /**
             * The deleted rule's id.
             *
             * @format uuid
             */
            'id' => $rule->id,
            /**
             * Always `true`.
             */
            'deleted' => true,
        ]);
    }

    /**
     * Apply an automation rule to existing transactions
     *
     * Runs the rule over the transactions already in the space, which it
     * otherwise never touches. By default this is a preview (`dry_run: true`):
     * it answers `status: preview` with how many transactions match and the
     * first 20 of them, newest first, and changes nothing.
     *
     * With `dry_run: false` it **overwrites your data**: every match gets the
     * rule's category (replacing the one it had when `only_uncategorized` is
     * `false`), its labels added and its note appended. It cannot be undone.
     * Up to 100 matches are applied right away and answer `200` with
     * `status: done`; more are applied in the background over the next few
     * minutes and answer `202` with `status: queued`. There is no progress
     * endpoint: preview again to see what the rule still matches.
     *
     * Previewing and applying both need a write token, and both only reach
     * your own transactions. The matches are worked
     * out once and reused for up to 15 minutes, so the apply that follows a
     * preview goes over the transactions that preview reported; editing the
     * rule starts over.
     *
     * @param  string  $automation_rule  The rule's id.
     */
    #[ScopedToSpace]
    public function apply(ApplyAutomationRuleRequest $request, string $automation_rule, AutomationRuleApplier $applier): RuleApplicationResource
    {
        return new RuleApplicationResource(
            $applier->applyInSpace($request->automationRule(), $this->space($request), $request->dryRun(), $request->onlyUncategorized()),
        );
    }
}
