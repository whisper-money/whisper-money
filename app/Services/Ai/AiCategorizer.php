<?php

namespace App\Services\Ai;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Orchestrates both tiers of AI auto-categorization for a set of transactions:
 * label each one (tier 1) and then learn a rule from every confident,
 * unambiguous result (tier 2). Shared by the real-time listener and the backfill
 * command.
 */
class AiCategorizer
{
    public function __construct(
        private readonly CategorizeTransactions $categorizer,
        private readonly AiRuleLearner $learner,
    ) {}

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return list<CategorizationOutcome>
     */
    public function run(User $user, Collection $transactions): array
    {
        $outcomes = $this->categorizer->forTransactions($user, $transactions);

        foreach ($outcomes as $outcome) {
            $this->learner->learn($outcome);
        }

        return $outcomes;
    }

    /**
     * Categorize every transaction still awaiting AI categorization for the
     * user, most recent first. A fixed snapshot of ids is chunked so rows left
     * blank (below the confidence bar) are never re-processed. The optional
     * $onProgress callback is invoked once up-front and after each batch with
     * (processed, total, applied) so callers can report live progress.
     *
     * @param  (callable(int, int, int): void)|null  $onProgress
     * @return array{processed: int, total: int, applied: int}
     */
    public function backfill(User $user, ?callable $onProgress = null): array
    {
        $pendingIds = Transaction::query()
            ->where('user_id', $user->id)
            ->pendingAiCategorization()
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->pluck('id');

        $total = $pendingIds->count();
        $processed = 0;
        $applied = 0;

        if ($onProgress !== null) {
            $onProgress($processed, $total, $applied);
        }

        $batchSize = max(1, (int) config('ai_categorization.group_batch_size'));

        foreach ($pendingIds->chunk($batchSize) as $chunkIds) {
            $chunk = Transaction::query()->whereIn('id', $chunkIds->all())->get();

            $outcomes = $this->run($user, $chunk);
            $applied += count(array_filter($outcomes, fn (CategorizationOutcome $outcome): bool => $outcome->applied));
            $processed += $chunkIds->count();

            if ($onProgress !== null) {
                $onProgress($processed, $total, $applied);
            }
        }

        return ['processed' => $processed, 'total' => $total, 'applied' => $applied];
    }
}
