<?php

namespace App\Services\AutomationRules;

/**
 * The JsonLogic condition object of an automation rule (`rules_json`), shared
 * by the rule MCP tools and `/api/v1/automation-rules`: which operators and
 * variables are accepted, and how to describe them. The MCP also takes it as a
 * JSON-encoded string and decodes it first; the REST API only takes an object.
 */
class RuleConditions
{
    /**
     * JsonLogic operators both rule engines understand and the visual builder
     * can round-trip. Anything else evaluates to something that is never `true`,
     * so the rule would save fine and then silently match nothing.
     *
     * @var list<string>
     */
    public const array SupportedOperators = ['and', 'or', '!', '==', '!=', '>', '>=', '<', '<=', 'in', 'var'];

    /**
     * The variables a transaction is evaluated against. A typo here is the other
     * way a rule ends up never matching: JsonLogic resolves an unknown variable
     * to null instead of failing.
     *
     * @var list<string>
     */
    public const array SupportedVariables = ['description', 'notes', 'creditor_name', 'debtor_name', 'account_name', 'bank_name', 'category', 'transaction_date', 'amount'];

    /**
     * The format, for the MCP schema and the API reference alike, so an agent
     * reads the same text whichever one it starts from.
     */
    public const string Description = <<<'TEXT'
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
        greater/less than {">":[{"var":"amount"},100]} and {"<":[{"var":"amount"},0]};
        amount equals / is not {"==":[{"var":"amount"},-14]} and {"!=":[{"var":"amount"},-14]}
        (signed: an expense is negative, so a 14 charge is -14).
        "does not contain" and "is not" also match a transaction whose field is empty
        or null, which is what an exception usually means.
        Example, either merchant and only expenses:
        {"and":[{"or":[{"in":["uber",{"var":"description"}]},{"in":["cabify",{"var":"description"}]}]},{"<":[{"var":"amount"},0]}]}
        Example, an exception: everything from amazon except the prime subscription:
        {"and":[{"in":["amazon",{"var":"description"}]},{"!":{"in":["amazon prime",{"var":"description"}]}}]}
        Example, an amount exception: every github charge except the 14 subscription:
        {"and":[{"in":["github",{"var":"description"}]},{"!=":[{"var":"amount"},-14]}]}
        Only these operators are accepted: and, or, !, ==, !=, >, >=, <, <=, in, var.
        TEXT;

    /**
     * Why $conditions is not a condition object the rule engines can act on,
     * or null when it is: an error the caller can correct instead of a rule
     * that quietly never fires.
     *
     * @param  string  $key  the field the conditions came in, which names it in the message
     */
    public static function error(mixed $conditions, string $key): ?string
    {
        if (! is_array($conditions) || $conditions === [] || array_is_list($conditions)) {
            return "{$key} must be a non-empty JsonLogic object.";
        }

        return self::nodeError($conditions);
    }

    /**
     * Conditions stored before the web form stopped double-encoding them come
     * back out of the model's array cast as the JSON string they wrapped.
     *
     * @param  array<string, mixed>|string  $stored
     * @return array<string, mixed>
     */
    public static function decoded(array|string $stored): array
    {
        if (is_array($stored)) {
            return $stored;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function nodeError(mixed $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        foreach ($node as $operator => $value) {
            $error = match (true) {
                is_int($operator) => self::nodeError($value),
                ! in_array($operator, self::SupportedOperators, true) => "Unsupported JsonLogic operator \"{$operator}\". Supported operators: ".implode(', ', self::SupportedOperators).'.',
                $operator === 'var' => self::variableError($value),
                default => self::nodeError($value),
            };

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * A `var` argument is either the name or a [name, default] pair.
     */
    private static function variableError(mixed $value): ?string
    {
        $name = is_array($value) ? ($value[0] ?? null) : $value;

        if (is_string($name) && in_array($name, self::SupportedVariables, true)) {
            return null;
        }

        return 'Unknown rule variable '.json_encode($name).'. Supported variables: '.implode(', ', self::SupportedVariables).'.';
    }
}
