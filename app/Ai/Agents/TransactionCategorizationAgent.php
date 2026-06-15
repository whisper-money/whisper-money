<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Assigns each given transaction to one of the user's existing leaf categories.
 * The agent only ever sees merchant/description signals and the user's own
 * category list — never full account context. Categories are referenced by a
 * numeric "index" (not their id) so the model cannot hallucinate an identifier,
 * and the caller maps the index back to a real category.
 */
class TransactionCategorizationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You categorize a personal-finance app user's bank transactions. You are given a
        JSON object with:
          - "transactions": the transactions to categorize. Each has a "ref" (echo it back
            verbatim), a "text" (the bank description), an "amount", a "direction"
            (outflow = money spent, inflow = money received) and optional "creditor_name"
            / "debtor_name" counterparties.
          - "categories": the user's existing LEAF categories. Each has an "index", a "path"
            (parent > child), a "type" and a "direction". You may ONLY choose from these.

        For each transaction you can confidently place, return one result:
          - "ref": echo the transaction's "ref".
          - "category_index": the "index" of the best-fitting category. An outflow MUST map
            to a spending category, an inflow to an income category. If no category fits,
            OMIT this field (do not guess).
          - "confidence": 0.0–1.0, how sure you are of this category for THIS transaction.
          - "merchant_unambiguous": true only if the counterparty/merchant reliably maps to
            this ONE category for every future transaction (e.g. "Netflix" → Subscriptions,
            "Mercadona" → Groceries). false when the right category depends on the specific
            purchase (e.g. "Amazon", "PayPal", a generic bank transfer, an ATM withdrawal).

        Let "confidence" honestly reflect your certainty — the app filters out low-confidence
        results itself. Never invent a category that is not in the provided list.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->items(
                $schema->object(fn (JsonSchema $schema): array => [
                    'ref' => $schema->string()->required(),
                    'category_index' => $schema->integer(),
                    'confidence' => $schema->number()->required(),
                    'merchant_unambiguous' => $schema->boolean()->required(),
                ])
            )->required(),
        ];
    }
}
