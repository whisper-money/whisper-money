<?php

namespace App\Services\Ai;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\User;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns accepted suggestion groups into real automation rules and (during
 * onboarding) applies them immediately to the user's uncategorized transactions.
 *
 * Each group becomes ONE rule whose conditions are OR'd together, so several
 * merchants heading to the same category live in a single, reviewable rule.
 * Rules are created narrowest-first (fewest matches, then highest confidence):
 * because evaluation stops at the first matching rule by ascending priority, a
 * specific rule is reached before a broader one and can't be overridden by it.
 */
class ApplyRuleSuggestions
{
    public function __construct(
        private readonly AutomationRuleService $automationRules,
        private readonly TransactionMatcher $matcher,
    ) {}

    /**
     * @param  list<array{
     *     conditions: list<array{field: string, operator: string, token: string}>,
     *     proposed_category_id: ?string,
     *     new_category_name: ?string,
     *     new_category_direction: ?string,
     *     confidence: float,
     * }>  $groups
     * @return array{rules_created: int, transactions_categorized: int}
     */
    public function apply(User $user, array $groups, bool $applyToExisting): array
    {
        $groups = array_values(array_filter($groups, fn (array $group): bool => $group['conditions'] !== []));

        if ($groups === []) {
            return ['rules_created' => 0, 'transactions_categorized' => 0];
        }

        // Narrower rules (fewer matches) first, then higher confidence, so the
        // more specific rule gets the lower priority and wins the overlap.
        $groups = array_map(function (array $group) use ($user): array {
            $group['match_count'] = $this->matcher->countMatchingAny($user, $group['conditions']);

            return $group;
        }, $groups);

        usort($groups, fn (array $a, array $b): int => [$a['match_count'], -$a['confidence']] <=> [$b['match_count'], -$b['confidence']]);

        $priority = (int) AutomationRule::query()->where('user_id', $user->id)->max('priority');
        $rulesCreated = 0;
        $categorized = 0;

        foreach ($groups as $group) {
            $rule = DB::transaction(function () use ($user, $group, &$priority): AutomationRule {
                $categoryId = $this->resolveCategoryId($user, $group);

                return AutomationRule::create([
                    'user_id' => $user->id,
                    'title' => $this->title($group, $categoryId),
                    'priority' => ++$priority,
                    'rules_json' => $this->rulesJson($group['conditions']),
                    'action_category_id' => $categoryId,
                ]);
            });

            $rulesCreated++;

            if ($applyToExisting) {
                $matches = $this->matcher->matchingAny($user, $group['conditions']);

                if ($matches->isNotEmpty()) {
                    $categorized += $this->automationRules->applyRuleActionsToTransactions($matches, $rule);
                }
            }
        }

        return ['rules_created' => $rulesCreated, 'transactions_categorized' => $categorized];
    }

    /**
     * Resolve the rule's target category, creating a proposed new category when
     * the group calls for one.
     *
     * @param  array<string, mixed>  $group
     */
    private function resolveCategoryId(User $user, array $group): string
    {
        if (! empty($group['proposed_category_id'])) {
            return (string) $group['proposed_category_id'];
        }

        $direction = ($group['new_category_direction'] ?? null) === CategoryCashflowDirection::Inflow->value
            ? CategoryCashflowDirection::Inflow
            : CategoryCashflowDirection::Outflow;

        $category = Category::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'parent_id' => null,
                'name' => $group['new_category_name'],
            ],
            [
                'type' => $direction === CategoryCashflowDirection::Inflow
                    ? CategoryType::Income
                    : CategoryType::Expense,
                'cashflow_direction' => $direction,
            ],
        );

        return $category->id;
    }

    /**
     * Build the OR'd JSON-Logic rule for the group's conditions. A single
     * condition stays flat; multiple are wrapped in an `or` (matching the shape
     * the settings rule editor produces and parses back).
     *
     * @param  list<array{field: string, operator: string, token: string}>  $conditions
     * @return array<string, mixed>
     */
    private function rulesJson(array $conditions): array
    {
        $clauses = array_map(function (array $condition): array {
            $variable = ['var' => $condition['field']];

            return $condition['operator'] === 'equals'
                ? ['==' => [$variable, $condition['token']]]
                : ['in' => [$condition['token'], $variable]];
        }, $conditions);

        return count($clauses) === 1 ? $clauses[0] : ['or' => $clauses];
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function title(array $group, string $categoryId): string
    {
        $categoryName = Category::query()->whereKey($categoryId)->value('name') ?? '';

        $tokens = array_map(fn (array $condition): string => Str::title($condition['token']), $group['conditions']);
        $label = implode(', ', array_slice($tokens, 0, 3));

        if (count($tokens) > 3) {
            $label .= '…';
        }

        return trim($label.' → '.$categoryName);
    }
}
