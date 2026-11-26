<?php

namespace App\Services\Forecast;

/**
 * The recognition rule a line the user typed in starts with: transactions
 * whose description contains its name, in the JsonLogic format of
 * `AutomationRule::rules_json` that ForecastMatcher evaluates. A transfer
 * gets none: the bank describes it in its own words, so it is recognised by
 * amount and date alone.
 */
final class ForecastMatchRules
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forName(string $name): ?array
    {
        $needle = mb_strtolower(trim($name));

        return $needle === '' ? null : ['in' => [$needle, ['var' => 'description']]];
    }

    /**
     * The text a rule built by forName() looks for, so the page can say what
     * a line is paired with; null for any other rule.
     *
     * @param  array<string, mixed>|null  $rules
     */
    public static function needle(?array $rules): ?string
    {
        $operands = $rules['in'] ?? null;

        if (! is_array($operands) || count($operands) !== 2 || ($operands[1] ?? null) !== ['var' => 'description']) {
            return null;
        }

        return is_string($operands[0]) ? $operands[0] : null;
    }
}
