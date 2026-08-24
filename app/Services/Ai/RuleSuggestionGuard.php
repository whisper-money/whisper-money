<?php

namespace App\Services\Ai;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Contracts\TransactionMatcher;
use Illuminate\Support\Collection;

/**
 * The model-independent safety layer. Every raw model suggestion is checked
 * against the user's real transactions before it can ever reach the UI: the
 * token must literally match, it must not be so broad it would mis-categorize
 * en masse, the confidence must clear the floor, and the category must exist
 * and agree with the group's cash-flow direction.
 */
class RuleSuggestionGuard
{
    private const MIN_TOKEN_LENGTH = 3;

    private const SAMPLE_LIMIT = 3;

    public function __construct(private readonly TransactionMatcher $matcher) {}

    /**
     * @param  list<array<string, mixed>>  $rawSuggestions
     * @param  list<array<string, mixed>>  $categoryOptions
     * @return list<array<string, mixed>>
     */
    public function validate(User $user, array $rawSuggestions, array $categoryOptions): array
    {
        $total = $this->matcher->total($user);

        if ($total === 0) {
            return [];
        }

        $floor = (float) config('ai_suggestions.confidence_floor');
        $overbroad = (float) config('ai_suggestions.overbroad_fraction');
        $minMatches = max(1, (int) config('ai_suggestions.min_group_count'));

        /** @var Collection<string, array<string, mixed>> $categoriesById */
        $categoriesById = collect($categoryOptions)->keyBy('id');

        $validated = [];

        foreach ($rawSuggestions as $raw) {
            $candidate = $this->validateOne($user, $raw, $categoriesById, $total, $floor, $overbroad, $minMatches);

            if ($candidate === null) {
                continue;
            }

            $key = $candidate['match_field'].'|'.$candidate['match_operator'].'|'.$candidate['match_token'];

            // Keep only the highest-confidence suggestion per identical matcher.
            if (! isset($validated[$key]) || $validated[$key]['confidence'] < $candidate['confidence']) {
                $validated[$key] = $candidate;
            }
        }

        return array_values($validated);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  Collection<string, array<string, mixed>>  $categoriesById
     * @return array<string, mixed>|null
     */
    private function validateOne(User $user, array $raw, Collection $categoriesById, int $total, float $floor, float $overbroad, int $minMatches): ?array
    {
        $field = is_string($raw['match_field'] ?? null) ? $raw['match_field'] : '';
        $operator = ($raw['match_operator'] ?? '') === 'equals' ? 'equals' : 'contains';
        $token = mb_strtolower(trim((string) ($raw['match_token'] ?? '')));
        $confidence = (float) ($raw['confidence'] ?? 0);

        if (! in_array($field, UncategorizedTransactionMatcher::ALLOWED_FIELDS, true)) {
            return null;
        }

        if (mb_strlen($token) < self::MIN_TOKEN_LENGTH) {
            return null;
        }

        if ($confidence < $floor) {
            return null;
        }

        $matchCount = $this->matcher->countMatching($user, $field, $operator, $token);

        // Token must match enough transactions to be worth a rule (single-match
        // one-offs are dropped), and must not be so broad it mis-categorises en masse.
        if ($matchCount < $minMatches || ($matchCount / $total) > $overbroad) {
            return null;
        }

        $matches = $this->matcher->matching($user, $field, $operator, $token, 25);
        $direction = $this->directionFor($matches);

        $category = $this->resolveCategory($raw, $categoriesById, $direction);

        if ($category === null) {
            return null;
        }

        return [
            'group_key' => is_string($raw['group_key'] ?? null) && $raw['group_key'] !== '' ? $raw['group_key'] : $token,
            'match_field' => $field,
            'match_operator' => $operator,
            'match_token' => $token,
            'proposed_category_id' => $category['proposed_category_id'],
            'new_category_name' => $category['new_category_name'],
            'new_category_parent_id' => null,
            'new_category_direction' => $category['new_category_direction'],
            'confidence' => round($confidence, 3),
            'group_size' => $matchCount,
            'sample_descriptions' => $this->samplesFor($matches, $field),
        ];
    }

    /**
     * Resolve the suggestion's category target: an existing category (sign-checked)
     * or a brand-new-category proposal.
     *
     * @param  array<string, mixed>  $raw
     * @param  Collection<string, array<string, mixed>>  $categoriesById
     * @return array{proposed_category_id: ?string, new_category_name: ?string, new_category_direction: ?string}|null
     */
    private function resolveCategory(array $raw, Collection $categoriesById, string $direction): ?array
    {
        $categoryId = is_string($raw['category_id'] ?? null) ? $raw['category_id'] : '';

        if ($categoryId !== '' && $categoriesById->has($categoryId)) {
            $category = $categoriesById->get($categoryId);

            if ($this->conflictsWithDirection($direction, (string) ($category['type'] ?? ''))) {
                return null;
            }

            return [
                'proposed_category_id' => $categoryId,
                'new_category_name' => null,
                'new_category_direction' => null,
            ];
        }

        $newName = trim((string) ($raw['new_category_name'] ?? ''));

        if ($newName === '' || mb_strlen($newName) > 255) {
            return null;
        }

        $newDirection = ($raw['new_category_direction'] ?? '') === 'inflow' ? 'inflow' : 'outflow';

        // If the model omitted a direction, fall back to the observed cash-flow.
        if (! in_array($raw['new_category_direction'] ?? null, ['inflow', 'outflow'], true)) {
            $newDirection = $direction;
        }

        return [
            'proposed_category_id' => null,
            'new_category_name' => $newName,
            'new_category_direction' => $newDirection,
        ];
    }

    private function conflictsWithDirection(string $direction, string $categoryType): bool
    {
        return ($direction === 'outflow' && $categoryType === 'income')
            || ($direction === 'inflow' && $categoryType === 'expense');
    }

    /**
     * @param  Collection<int, Transaction>  $matches
     */
    private function directionFor(Collection $matches): string
    {
        return $matches->sum(fn (Transaction $transaction): int => (int) $transaction->amount) < 0
            ? 'outflow'
            : 'inflow';
    }

    /**
     * @param  Collection<int, Transaction>  $matches
     * @return list<string>
     */
    private function samplesFor(Collection $matches, string $field): array
    {
        return $matches
            ->map(fn (Transaction $transaction): string => trim((string) ($transaction->description ?: $transaction->{$field})))
            ->filter()
            ->unique()
            ->take(self::SAMPLE_LIMIT)
            ->values()
            ->all();
    }
}
