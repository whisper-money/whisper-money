<?php

namespace App\Services\Ai;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Enums\RuleSuggestionStatus;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\RuleSuggestion;
use App\Models\User;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\AutomationRuleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns accepted suggestions into real automation rules and (during onboarding)
 * applies them immediately to the user's uncategorized transactions.
 *
 * Rules are created highest-confidence first so that, when two rules could
 * match the same transaction, the more confident one wins — each rule is only
 * applied to transactions that are still uncategorized at the moment it runs.
 */
class ApplyRuleSuggestions
{
    public function __construct(
        private readonly AutomationRuleService $automationRules,
        private readonly TransactionMatcher $matcher,
    ) {}

    /**
     * @param  Collection<int, RuleSuggestion>  $suggestions
     * @return array{rules_created: int, transactions_categorized: int}
     */
    public function apply(User $user, Collection $suggestions, bool $applyToExisting): array
    {
        if ($suggestions->isEmpty()) {
            return ['rules_created' => 0, 'transactions_categorized' => 0];
        }

        $priority = (int) AutomationRule::query()->where('user_id', $user->id)->max('priority');
        $rulesCreated = 0;
        $categorized = 0;

        $ordered = $suggestions
            ->sortByDesc(fn (RuleSuggestion $suggestion): array => [(float) $suggestion->confidence, (int) $suggestion->group_size])
            ->values();

        foreach ($ordered as $suggestion) {
            $rule = DB::transaction(function () use ($user, $suggestion, &$priority) {
                $categoryId = $this->resolveCategoryId($user, $suggestion);

                $rule = AutomationRule::create([
                    'user_id' => $user->id,
                    'title' => $this->title($suggestion, $categoryId),
                    'priority' => ++$priority,
                    'rules_json' => $this->rulesJson($suggestion),
                    'action_category_id' => $categoryId,
                ]);

                $suggestion->forceFill(['status' => RuleSuggestionStatus::Accepted])->save();

                return $rule;
            });

            $rulesCreated++;

            if ($applyToExisting) {
                $matches = $this->matcher->matching(
                    $user,
                    $suggestion->match_field,
                    $suggestion->match_operator,
                    $suggestion->match_token,
                );

                if ($matches->isNotEmpty()) {
                    $categorized += $this->automationRules->applyRuleActionsToTransactions($matches, $rule);
                }
            }
        }

        return ['rules_created' => $rulesCreated, 'transactions_categorized' => $categorized];
    }

    /**
     * Resolve the rule's target category, creating a proposed new category when
     * the suggestion calls for one.
     */
    private function resolveCategoryId(User $user, RuleSuggestion $suggestion): string
    {
        if ($suggestion->proposed_category_id !== null) {
            return $suggestion->proposed_category_id;
        }

        $direction = $suggestion->new_category_direction === CategoryCashflowDirection::Inflow->value
            ? CategoryCashflowDirection::Inflow
            : CategoryCashflowDirection::Outflow;

        $category = Category::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'parent_id' => null,
                'name' => $suggestion->new_category_name,
            ],
            [
                'type' => $direction === CategoryCashflowDirection::Inflow
                    ? CategoryType::Income
                    : CategoryType::Expense,
                'cashflow_direction' => $direction,
            ],
        );

        $suggestion->forceFill(['proposed_category_id' => $category->id])->save();

        return $category->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesJson(RuleSuggestion $suggestion): array
    {
        $variable = ['var' => $suggestion->match_field];

        return $suggestion->match_operator === 'equals'
            ? ['==' => [$variable, $suggestion->match_token]]
            : ['in' => [$suggestion->match_token, $variable]];
    }

    private function title(RuleSuggestion $suggestion, string $categoryId): string
    {
        $categoryName = Category::query()->whereKey($categoryId)->value('name') ?? '';
        $token = Str::title($suggestion->match_token);

        return trim($token.' → '.$categoryName);
    }
}
