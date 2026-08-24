<?php

namespace App\Services\Ai;

/**
 * Removes redundant suggestions before they are ever persisted or shown. Two
 * `contains` tokens that point at the SAME category and where one is a substring
 * of the other are wasteful: the broader token already matches everything the
 * narrower one would (e.g. "endesa" already covers "endesa energia" → both
 * Electricity), so the narrower token is dropped.
 *
 * Tokens that overlap but target DIFFERENT categories are left intact — those
 * are resolved later by rule priority (the more specific rule wins), not by
 * pruning.
 */
class RuleSuggestionConsolidator
{
    /**
     * @param  list<array<string, mixed>>  $validated
     * @return list<array<string, mixed>>
     */
    public function consolidate(array $validated): array
    {
        /** @var array<string, list<int>> $byCategory */
        $byCategory = [];

        foreach ($validated as $index => $suggestion) {
            $byCategory[$this->categoryKey($suggestion)][] = $index;
        }

        $drop = [];

        foreach ($byCategory as $indices) {
            foreach ($indices as $broadIndex) {
                if (isset($drop[$broadIndex])) {
                    continue;
                }

                foreach ($indices as $narrowIndex) {
                    if ($broadIndex === $narrowIndex || isset($drop[$narrowIndex])) {
                        continue;
                    }

                    if ($this->subsumes($validated[$broadIndex], $validated[$narrowIndex])) {
                        $drop[$narrowIndex] = true;
                    }
                }
            }
        }

        return array_values(array_filter(
            $validated,
            fn (mixed $suggestion, int $index): bool => ! isset($drop[$index]),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Whether the broad suggestion's token already covers the narrow one: same
     * field, both `contains`, and the broad token is a (shorter) substring of
     * the narrow token.
     *
     * @param  array<string, mixed>  $broad
     * @param  array<string, mixed>  $narrow
     */
    private function subsumes(array $broad, array $narrow): bool
    {
        if ($broad['match_field'] !== $narrow['match_field']) {
            return false;
        }

        if ($broad['match_operator'] !== 'contains' || $narrow['match_operator'] !== 'contains') {
            return false;
        }

        $broadToken = (string) $broad['match_token'];
        $narrowToken = (string) $narrow['match_token'];

        return $broadToken !== $narrowToken && str_contains($narrowToken, $broadToken);
    }

    /**
     * @param  array<string, mixed>  $suggestion
     */
    private function categoryKey(array $suggestion): string
    {
        if (! empty($suggestion['proposed_category_id'])) {
            return 'cat:'.$suggestion['proposed_category_id'];
        }

        $name = mb_strtolower(trim((string) ($suggestion['new_category_name'] ?? '')));
        $direction = (string) ($suggestion['new_category_direction'] ?? '');

        return 'new:'.$direction.':'.$name;
    }
}
