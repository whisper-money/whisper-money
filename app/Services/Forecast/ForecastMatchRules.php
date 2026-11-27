<?php

namespace App\Services\Forecast;

/**
 * The recognition rules of forecast lines, in the JsonLogic format of
 * `AutomationRule::rules_json` that ForecastMatcher evaluates.
 *
 * A line the user typed in starts with one that looks for its name in the
 * description (forName()); a transfer gets none, since the bank describes it
 * in its own words, so it is recognised by amount and date alone.
 *
 * A line confirmed from detected charges needs every word of their
 * fingerprint in the field the fingerprint was read from, the creditor name
 * when the bank gave one and the description otherwise (forFingerprints()).
 * Several fingerprints (a line merged from several suggestions) recognise a
 * transaction that matches any of them. A suggestion recognised by amount and
 * day alone has no words to look for, so its line gets no rule.
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

    /**
     * @param  list<array{fingerprint: string|null, field: 'creditor_name'|'description'}>  $sources  One per suggestion.
     * @return array<string, mixed>|null
     */
    public static function forFingerprints(array $sources): ?array
    {
        $rules = [];

        foreach ($sources as $source) {
            if ($source['fingerprint'] === null || $source['fingerprint'] === '') {
                return null;
            }

            $rules[] = ['and' => array_map(
                fn (string $word): array => ['in' => [$word, ['var' => $source['field']]]],
                array_values(array_unique(explode(' ', $source['fingerprint']))),
            )];
        }

        if ($rules === []) {
            return null;
        }

        return count($rules) === 1 ? $rules[0] : ['or' => $rules];
    }
}
