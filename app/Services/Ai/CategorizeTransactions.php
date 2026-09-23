<?php

namespace App\Services\Ai;

use App\Enums\CategorySource;
use App\Exceptions\Ai\TransientCategorizationException;
use App\Features\JevCategorization;
use App\Jobs\RetryTransientAiCategorizationJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Contracts\CategorizationBackend;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Tier 1 of AI auto-categorization: ask the model to assign each transaction to
 * one of the user's leaf categories and auto-apply the label when it clears the
 * label confidence bar. Returns an outcome per transaction the model placed so
 * the caller can drive tier 2 (rule learning) off the high-confidence ones.
 */
class CategorizeTransactions
{
    public function __construct(
        private readonly GeminiCategorizationBackend $gemini,
        private readonly JevCategorizationBackend $jev,
    ) {}

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<CategorizationOutcome>
     */
    public function forTransactions(User $user, Collection $transactions): array
    {
        if ($transactions->isEmpty()) {
            return [];
        }

        $catalog = CategoryCatalog::forUser($user);

        if ($catalog->isEmpty()) {
            return [];
        }

        $backend = $this->backendFor($user);
        $byRef = $transactions->keyBy(fn (Transaction $transaction): string => $transaction->id);
        $results = $this->resolve($user, $transactions, $catalog, $backend);

        $labelBar = (float) config('ai_categorization.label_confidence');
        $model = $backend->model();
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

            $this->recordOutcome($transaction, $categoryId, $confidence, $applied, $model);

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
     * Users in the JevCategorization rollout go to Jev, everyone else to the
     * default provider. Without an API key the flag is ignored, so enabling it
     * ahead of the key cannot stop categorization.
     */
    private function backendFor(User $user): CategorizationBackend
    {
        if (config('services.typesafe.enabled') && Feature::for($user)->active(JevCategorization::class)) {
            return $this->jev;
        }

        return $this->gemini;
    }

    /**
     * Persist the model's suggestion on the transaction whether or not it clears
     * the label bar. Below the bar the transaction stays uncategorized but the
     * suggestion is kept (for confidence-bar tuning and a future confirm UI);
     * at or above it the category is also auto-applied.
     */
    private function recordOutcome(Transaction $transaction, string $categoryId, float $confidence, bool $applied, string $model): void
    {
        $transaction->ai_suggested_category_id = $categoryId;
        $transaction->ai_confidence = $confidence;
        $transaction->ai_suggested_category_at = now();
        $transaction->ai_model = $model;

        if ($applied) {
            $transaction->category_id = $categoryId;
            $transaction->category_source = CategorySource::Ai;
        }

        $transaction->save();
    }

    /**
     * Send the transactions to the model in bounded chunks and merge the
     * results. A chunk that fails after a retry is dropped without discarding
     * the chunks that succeeded; a transient provider failure additionally
     * schedules a deferred retry of the user's still-pending transactions.
     *
     * Transient covers both the provider answering badly (overload, rate limit)
     * and it not answering at all (DNS, connect timeout): neither is a bug, and
     * both leave the chunk's transactions uncategorized until the retry runs.
     * The trade-off is that a provider which stays unreachable never reaches
     * Sentry either — the warning below is the only trace, and the deferred
     * retry gets one attempt (see {@see RetryTransientAiCategorizationJob}).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return list<array<string, mixed>>
     */
    private function resolve(User $user, Collection $transactions, CategoryCatalog $catalog, CategorizationBackend $backend): array
    {
        $batchSize = max(1, (int) config('ai_categorization.group_batch_size'));
        $results = [];

        foreach ($transactions->chunk($batchSize) as $chunk) {
            try {
                array_push($results, ...$this->resolveChunkWithRetry($chunk, $catalog, $backend));
            } catch (ConnectionException|FailoverableException|TransientCategorizationException $exception) {
                if ($exception instanceof TransientCategorizationException) {
                    array_push($results, ...$exception->results);
                }

                Log::warning('AI categorization chunk dropped: provider transient failure.', [
                    'exception' => $exception->getMessage(),
                ]);

                RetryTransientAiCategorizationJob::dispatch($user)
                    ->delay(now()->addMinutes((int) config('ai_categorization.retry_delay')));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $results;
    }

    /**
     * A partial transient failure is not retried in place: its results already
     * came back (and were billed), and the deferred retry picks up the rest.
     *
     * @param  Collection<int, Transaction>  $chunk
     * @return list<array<string, mixed>>
     */
    private function resolveChunkWithRetry(Collection $chunk, CategoryCatalog $catalog, CategorizationBackend $backend): array
    {
        try {
            return $backend->categorize($chunk, $catalog);
        } catch (TransientCategorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            return $backend->categorize($chunk, $catalog);
        }
    }
}
