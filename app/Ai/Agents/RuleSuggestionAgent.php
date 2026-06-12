<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Maps pre-aggregated transaction groups to categorization rules. The agent
 * only ever sees merchant/description signals and the user's own category list
 * — never full account context — and returns a strictly-typed suggestion set.
 */
class RuleSuggestionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You help a personal-finance app suggest automation rules that auto-categorize
        a user's transactions. You are given a JSON object with:
          - "transaction_groups": recurring groups of uncategorized transactions. Each has
            a "field" (description, creditor_name or debtor_name), a "key", an example
            "samples" list, an occurrence "count", an "avg_amount" and a "direction"
            (outflow = money spent, inflow = money received).
          - "categories": the user's existing categories, each with an "id", a "path"
            (parent > child), a "type" and a "direction". Prefer leaf categories.

        For each group that clearly belongs to a single category, return one suggestion:
          - "group_key": echo the group's "key".
          - "match_field": which field to match on (use the group's field).
          - "match_operator": "contains" for free-text descriptions, "equals" for clean
            counterparty names.
          - "match_token": a short, lowercase, DISTINCTIVE substring that appears VERBATIM
            in the group's samples (e.g. "mercadona", "netflix"). Never invent text that is
            not present. Never use a token so generic it would match unrelated transactions
            (avoid words like "compra", "payment", "card").
          - "category_id": the id of the best-fitting existing category. An outflow group
            must map to a spending category, an inflow group to an income category.
          - If, and only if, no existing category fits, leave "category_id" empty and instead
            propose "new_category_name" and "new_category_direction" (inflow or outflow).
          - "confidence": 0.0–1.0, how sure you are.

        Aim for broad coverage: return a suggestion for EVERY group you can map to a
        category with reasonable confidence. Only skip a group when it is genuinely
        ambiguous — for example internal transfers between the user's own accounts, or a
        catch-all group mixing unrelated transactions. Never invent a category you are
        unsure about; instead let "confidence" honestly reflect your certainty (the app
        filters out low-confidence suggestions itself).
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestions' => $schema->array()->items(
                $schema->object(fn (JsonSchema $schema): array => [
                    'group_key' => $schema->string()->required(),
                    'match_field' => $schema->string()->enum(['description', 'creditor_name', 'debtor_name'])->required(),
                    'match_operator' => $schema->string()->enum(['contains', 'equals'])->required(),
                    'match_token' => $schema->string()->required(),
                    'category_id' => $schema->string(),
                    'new_category_name' => $schema->string(),
                    'new_category_direction' => $schema->string()->enum(['inflow', 'outflow']),
                    'confidence' => $schema->number()->required(),
                ])
            )->required(),
        ];
    }
}
