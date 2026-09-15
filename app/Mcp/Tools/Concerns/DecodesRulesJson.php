<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

trait DecodesRulesJson
{
    /**
     * JsonLogic operators both rule engines understand and the visual builder
     * can round-trip. Anything else evaluates to something that is never `true`,
     * so the rule would save fine and then silently match nothing.
     *
     * @var list<string>
     */
    private const SUPPORTED_OPERATORS = ['and', 'or', '!', '==', '!=', '>', '>=', '<', '<=', 'in', 'var'];

    /**
     * The variables a transaction is evaluated against. A typo here is the other
     * way a rule ends up never matching: JsonLogic resolves an unknown variable
     * to null instead of failing.
     *
     * @var list<string>
     */
    private const SUPPORTED_VARIABLES = ['description', 'notes', 'creditor_name', 'debtor_name', 'account_name', 'bank_name', 'category', 'transaction_date', 'amount'];

    /**
     * Decode and validate a JsonLogic condition argument, accepting either a
     * JSON object or a JSON-encoded string.
     *
     * @return array<string, mixed>
     */
    protected function rulesJson(Request $request, string $key = 'rules_json'): array
    {
        $rulesJson = $request->get($key);

        if (is_string($rulesJson)) {
            $rulesJson = json_decode($rulesJson, true);
        }

        if (! is_array($rulesJson) || $rulesJson === [] || array_is_list($rulesJson)) {
            throw ValidationException::withMessages([
                $key => "{$key} must be a non-empty JsonLogic object.",
            ]);
        }

        $this->assertSupportedNode($rulesJson, $key);

        return $rulesJson;
    }

    /**
     * The shared `rules_json` schema description. Both write tools point at the
     * same text so an agent reads the same format whichever one it starts from.
     */
    protected function rulesJsonDescription(): string
    {
        return <<<'TEXT'
            A single JsonLogic object. {"and":[...]} and {"or":[...]} take a list of
            conditions and nest freely, so an "any of these, but not that" rule is one
            "and" whose items are an "or" and a negation.
            Variables, all lowercase: description, notes, creditor_name, debtor_name,
            account_name, bank_name, category, transaction_date (YYYY-MM-DD) and amount.
            Note: amount here is in MAJOR units (e.g. 12.50), not the minor units every
            other tool takes. Text is compared lowercased, so write values in lowercase.
            One condition per operator:
            contains {"in":["netflix",{"var":"description"}]};
            does not contain {"!":{"in":["netflix",{"var":"description"}]}};
            equals {"==":[{"var":"creditor_name"},"netflix international b.v."]};
            is not {"!=":[{"var":"creditor_name"},"netflix international b.v."]};
            is empty {"==":[{"var":"creditor_name"},null]};
            is not empty {"!=":[{"var":"creditor_name"},null]};
            greater/less than {">":[{"var":"amount"},100]} and {"<":[{"var":"amount"},0]}.
            "does not contain" and "is not" also match a transaction whose field is empty
            or null, which is what an exception usually means.
            Example, either merchant and only expenses:
            {"and":[{"or":[{"in":["uber",{"var":"description"}]},{"in":["cabify",{"var":"description"}]}]},{"<":[{"var":"amount"},0]}]}
            Example, an exception: everything from amazon except the prime subscription:
            {"and":[{"in":["amazon",{"var":"description"}]},{"!":{"in":["amazon prime",{"var":"description"}]}}]}
            Only these operators are accepted: and, or, !, ==, !=, >, >=, <, <=, in, var.
            TEXT;
    }

    /**
     * Reject operators and variables the rule engines cannot act on, so the
     * agent gets a correctable error instead of a rule that quietly never fires.
     */
    private function assertSupportedNode(mixed $node, string $key): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $operator => $value) {
            if (is_int($operator)) {
                $this->assertSupportedNode($value, $key);

                continue;
            }

            if (! in_array($operator, self::SUPPORTED_OPERATORS, true)) {
                throw ValidationException::withMessages([
                    $key => "Unsupported JsonLogic operator \"{$operator}\". Supported operators: ".implode(', ', self::SUPPORTED_OPERATORS).'.',
                ]);
            }

            if ($operator === 'var') {
                $this->assertSupportedVariable($value, $key);

                continue;
            }

            $this->assertSupportedNode($value, $key);
        }
    }

    /**
     * A `var` argument is either the name or a [name, default] pair.
     */
    private function assertSupportedVariable(mixed $value, string $key): void
    {
        $name = is_array($value) ? ($value[0] ?? null) : $value;

        if (is_string($name) && in_array($name, self::SUPPORTED_VARIABLES, true)) {
            return;
        }

        throw ValidationException::withMessages([
            $key => 'Unknown rule variable '.json_encode($name).'. Supported variables: '.implode(', ', self::SUPPORTED_VARIABLES).'.',
        ]);
    }
}
