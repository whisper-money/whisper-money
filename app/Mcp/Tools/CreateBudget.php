<?php

namespace App\Mcp\Tools;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Mcp\Tools\Concerns\PresentsBudgets;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a budget: a spending limit per period over a set of categories and/or labels. Transactions already in range are attached in the background, so spent_amount may still be filling in.')]
class CreateBudget extends WriteTool
{
    use PresentsBudgets;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Budget name.')->required(),
            'allocated_amount' => $schema->integer()->description('Limit for each period, in minor units (50000 = 500.00).')->required(),
            'period_type' => $schema->string()->enum(array_column(BudgetPeriodType::cases(), 'value'))->description('How often the budget starts over.')->required(),
            'rollover_type' => $schema->string()->enum(array_column(RolloverType::cases(), 'value'))->description('"carry_over" adds what is left to the next period, "reset" starts every period at the limit.')->required(),
            'period_start_day' => $schema->integer()->min(0)->max(31)->description('Day the period starts: day of the month (1-31) when monthly, day of the week (0 = Sunday, 6 = Saturday) when weekly or biweekly. Ignored when yearly.'),
            'category_ids' => $schema->array()->items($schema->string())->description('Categories to track. Tracking a parent also tracks its children. Call list_categories to see valid ids.'),
            'label_ids' => $schema->array()->items($schema->string())->description('Labels to track. Call list_labels to see valid ids.'),
            'is_catch_all' => $schema->boolean()->description('Track every expense category no other budget tracks. Needs no categories or labels, and only one catch-all budget is allowed.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $periodType = $request->string('period_type')->toString();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allocated_amount' => ['required', 'integer', 'min:0'],
            'period_type' => ['required', Rule::enum(BudgetPeriodType::class)],
            'rollover_type' => ['required', Rule::enum(RolloverType::class)],
            // The field means a day of the week for weekly periods and a day of
            // the month for monthly ones, so its range depends on period_type.
            'period_start_day' => ['sometimes', 'nullable', 'integer', 'min:'.($periodType === 'monthly' ? 1 : 0), 'max:'.(in_array($periodType, ['weekly', 'biweekly'], true) ? 6 : 31)],
            'is_catch_all' => ['sometimes', 'boolean'],
        ]);

        // Budgets track their owner's own categories and labels, so an id
        // belonging to a co-member of a shared space is rejected even though
        // list_categories, being space-scoped, does return it.
        $categoryIds = $this->ownedIds($request, 'category_ids', Category::query()->where('user_id', $user->id), 'One or more category_ids are not categories you own. Only your own categories can be budgeted.');
        $labelIds = $this->ownedIds($request, 'label_ids', Label::query()->where('user_id', $user->id), 'One or more label_ids are not labels you own. Only your own labels can be budgeted.');
        $isCatchAll = $request->boolean('is_catch_all');

        $this->assertBudgetTracksSomething($user, $isCatchAll, $categoryIds, $labelIds);

        $budget = app(BudgetService::class)->create(
            $user,
            [
                'name' => $request->string('name')->toString(),
                'period_type' => $request->string('period_type')->toString(),
                'period_start_day' => $request->filled('period_start_day') ? $request->integer('period_start_day') : null,
                'rollover_type' => $request->string('rollover_type')->toString(),
                'is_catch_all' => $isCatchAll,
            ],
            $request->integer('allocated_amount'),
            $categoryIds,
            $labelIds,
        );

        return $this->json(['budget' => $this->presentBudget($budget)]);
    }

    /**
     * A budget needs something to watch: either explicit categories/labels, or
     * the single catch-all budget that absorbs whatever the others leave behind.
     *
     * The web form checks the same two rules (StoreBudgetRequest), with wording
     * aimed at someone looking at the create dialog rather than at an agent
     * deciding what to send next.
     *
     * @param  array<int, string>  $categoryIds
     * @param  array<int, string>  $labelIds
     */
    private function assertBudgetTracksSomething(User $user, bool $isCatchAll, array $categoryIds, array $labelIds): void
    {
        if (! $isCatchAll && $categoryIds === [] && $labelIds === []) {
            throw ValidationException::withMessages([
                'category_ids' => 'Pass at least one category or label to track, or set is_catch_all to true.',
            ]);
        }

        if ($isCatchAll && $user->budgets()->notArchived()->where('is_catch_all', true)->exists()) {
            throw ValidationException::withMessages([
                'is_catch_all' => 'This account already has a catch-all budget.',
            ]);
        }
    }

    /**
     * The ids passed under $key, asserted to all be reachable through $query so
     * an unusable one is reported instead of silently dropped.
     *
     * @param  Builder<covariant Model>  $query
     * @return list<string>
     */
    private function ownedIds(Request $request, string $key, Builder $query, string $failure): array
    {
        $ids = $this->requestedIds($request, $key);

        if ($query->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([$key => $failure]);
        }

        return $ids;
    }
}
