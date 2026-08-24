<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pre-aggregates a user's uncategorized transactions into a small, deduped set
 * of merchant/description groups before anything is sent to the model. This is
 * the cheap, deterministic step that keeps the LLM payload (and cost) bounded.
 */
class RuleSuggestionAggregator
{
    /**
     * The fields, in priority order, used to derive a group's signal.
     */
    private const SAMPLE_LIMIT = 5;

    public function __construct(private readonly DescriptionTokenizer $tokenizer) {}

    /**
     * Build the bounded set of transaction groups worth suggesting a rule for.
     *
     * @return list<array{key: string, field: string, count: int, avg_amount: float, direction: string, samples: list<string>}>
     */
    public function groupsFor(User $user): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereNull('description_iv')
            ->get(['id', 'description', 'creditor_name', 'debtor_name', 'amount']);

        $documentFrequency = $this->descriptionDocumentFrequency($transactions);
        $noiseThreshold = $transactions->count() * (float) config('ai_suggestions.noise_token_fraction');

        $groups = [];

        foreach ($transactions as $transaction) {
            [$field, $rawKey] = $this->groupingSignal($transaction);
            $key = $field === 'description'
                ? $this->tokenizer->distinctiveKey($rawKey, $documentFrequency, $noiseThreshold)
                : $this->normalizeWhitespace($rawKey);

            if ($key === '') {
                continue;
            }

            $bucket = $field.'|'.$key;

            $groups[$bucket] ??= [
                'key' => $key,
                'field' => $field,
                'count' => 0,
                'amount_sum' => 0,
                'samples' => [],
            ];

            $groups[$bucket]['count']++;
            $groups[$bucket]['amount_sum'] += (int) $transaction->amount;

            $sample = $this->normalizeWhitespace((string) ($transaction->description ?? $rawKey));
            if ($sample !== '' && count($groups[$bucket]['samples']) < self::SAMPLE_LIMIT) {
                $groups[$bucket]['samples'][$sample] = $sample;
            }
        }

        $min = (int) config('ai_suggestions.min_group_count');
        $max = (int) config('ai_suggestions.max_groups_sent');

        return collect($groups)
            ->filter(fn (array $group): bool => $group['count'] >= $min)
            ->sortByDesc('count')
            ->take($max)
            ->map(function (array $group): array {
                $avg = $group['amount_sum'] / $group['count'] / 100;

                return [
                    'key' => $group['key'],
                    'field' => $group['field'],
                    'count' => $group['count'],
                    'avg_amount' => round($avg, 2),
                    'direction' => $avg < 0 ? 'outflow' : 'inflow',
                    'samples' => array_values($group['samples']),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The closed list of the user's categories the model must map groups into.
     *
     * @return list<array{id: string, name: string, path: string, type: string, direction: string, is_leaf: bool}>
     */
    public function categoryOptions(User $user): array
    {
        $categories = Category::query()
            ->where('user_id', $user->id)
            ->get();

        $byId = $categories->keyBy('id');
        $parentIds = $categories->pluck('parent_id')->filter()->unique()->flip();

        return $categories
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $this->categoryPath($category, $byId),
                'type' => $category->type->value,
                'direction' => $category->cashflow_direction->value,
                'is_leaf' => ! $parentIds->has($category->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: string} [field, rawValue]
     */
    private function groupingSignal(Transaction $transaction): array
    {
        if (filled($transaction->creditor_name)) {
            return ['creditor_name', (string) $transaction->creditor_name];
        }

        if (filled($transaction->debtor_name)) {
            return ['debtor_name', (string) $transaction->debtor_name];
        }

        return ['description', (string) $transaction->description];
    }

    /**
     * Document frequency of each description token across the user's
     * uncategorized, description-grouped transactions. Used to identify
     * structural noise words without any language-specific list.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, int>
     */
    private function descriptionDocumentFrequency(Collection $transactions): array
    {
        $descriptions = $transactions
            ->map(fn (Transaction $transaction): array => $this->groupingSignal($transaction))
            ->filter(fn (array $signal): bool => $signal[0] === 'description')
            ->map(fn (array $signal): string => $signal[1])
            ->all();

        return $this->tokenizer->documentFrequency($descriptions);
    }

    /**
     * @param  Collection<string, Category>  $byId
     */
    private function categoryPath(Category $category, Collection $byId): string
    {
        $parts = [$category->name];
        $current = $category;
        $guard = 0;

        while ($current->parent_id !== null
            && $byId->has($current->parent_id)
            && $guard++ < Category::MAX_DEPTH) {
            $current = $byId->get($current->parent_id);
            array_unshift($parts, $current->name);
        }

        return implode(' > ', $parts);
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($value)) ?? '');
    }
}
