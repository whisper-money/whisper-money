<?php

namespace App\Services\Ai;

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategorySource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Tier 1 of AI auto-categorization: ask the model to assign each transaction to
 * one of the user's leaf categories and auto-apply the label when it clears the
 * label confidence bar. Returns an outcome per transaction the model placed so
 * the caller can drive tier 2 (rule learning) off the high-confidence ones.
 */
class CategorizeTransactions
{
    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<CategorizationOutcome>
     */
    public function forTransactions(User $user, Collection $transactions): array
    {
        $transactions = $transactions->filter(
            fn (Transaction $transaction): bool => $transaction->description_iv === null,
        )->values();

        if ($transactions->isEmpty()) {
            return [];
        }

        $catalog = CategoryCatalog::forUser($user);

        if ($catalog->isEmpty()) {
            return [];
        }

        $byRef = $transactions->keyBy(fn (Transaction $transaction): string => $transaction->id);
        $results = $this->resolve($transactions, $catalog);

        $labelBar = (float) config('ai_categorization.label_confidence');
        $outcomes = [];

        foreach ($results as $result) {
            $transaction = $byRef->get((string) ($result['ref'] ?? ''));

            if ($transaction === null) {
                continue;
            }

            $categoryId = $catalog->categoryIdForIndex(
                isset($result['category_index']) ? (int) $result['category_index'] : null,
            );

            if ($categoryId === null) {
                continue;
            }

            $confidence = (float) ($result['confidence'] ?? 0.0);
            $applied = $confidence >= $labelBar;

            $this->recordOutcome($transaction, $categoryId, $confidence, $applied);

            $outcomes[] = new CategorizationOutcome(
                transaction: $transaction,
                categoryId: $categoryId,
                confidence: $confidence,
                merchantUnambiguous: (bool) ($result['merchant_unambiguous'] ?? false),
                applied: $applied,
            );
        }

        return $outcomes;
    }

    /**
     * Persist the model's suggestion on the transaction whether or not it clears
     * the label bar. Below the bar the transaction stays uncategorized but the
     * suggestion is kept (for confidence-bar tuning and a future confirm UI);
     * at or above it the category is also auto-applied.
     */
    private function recordOutcome(Transaction $transaction, string $categoryId, float $confidence, bool $applied): void
    {
        $transaction->ai_suggested_category_id = $categoryId;
        $transaction->ai_confidence = $confidence;
        $transaction->ai_suggested_category_at = now();

        if ($applied) {
            $transaction->category_id = $categoryId;
            $transaction->category_source = CategorySource::Ai;
        }

        $transaction->save();
    }

    /**
     * Send the transactions to the model in bounded chunks and merge the
     * results. A chunk that fails after a retry is dropped without discarding
     * the chunks that succeeded.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array<string, mixed>>
     */
    private function resolve(Collection $transactions, CategoryCatalog $catalog): array
    {
        $batchSize = max(1, (int) config('ai_categorization.group_batch_size'));
        $results = [];

        foreach ($transactions->chunk($batchSize) as $chunk) {
            try {
                foreach ($this->resolveChunkWithRetry($chunk, $catalog) as $result) {
                    $results[] = $result;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $results;
    }

    /**
     * @param  Collection<int, Transaction>  $chunk
     * @return list<array<string, mixed>>
     */
    private function resolveChunkWithRetry(Collection $chunk, CategoryCatalog $catalog): array
    {
        try {
            return $this->resolveChunk($chunk, $catalog);
        } catch (Throwable) {
            return $this->resolveChunk($chunk, $catalog);
        }
    }

    /**
     * @param  Collection<int, Transaction>  $chunk
     * @return list<array<string, mixed>>
     */
    private function resolveChunk(Collection $chunk, CategoryCatalog $catalog): array
    {
        $items = $chunk->map(fn (Transaction $transaction): array => [
            'ref' => $transaction->id,
            'text' => (string) $transaction->description,
            'amount' => $transaction->amount / 100,
            'direction' => $transaction->amount < 0 ? 'outflow' : 'inflow',
            'creditor_name' => $transaction->creditor_name,
            'debtor_name' => $transaction->debtor_name,
        ])->values()->all();

        $payload = json_encode([
            'transactions' => $items,
            'categories' => $catalog->options(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = (new TransactionCategorizationAgent)->prompt(
            $payload,
            provider: Lab::Gemini,
            model: (string) config('ai_categorization.model'),
        );

        $results = $response['results'] ?? [];

        return is_array($results) ? array_values($results) : [];
    }
}
