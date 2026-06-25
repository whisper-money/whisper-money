<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Categorize every uncategorized transaction for a user who has just granted AI
 * consent outside of onboarding. Progress is written to the cache so the
 * transactions page can poll it and surface live progress while the batch runs.
 *
 * ponytail: mirrors CategorizeOnboardingTransactionsJob's selection + chunking;
 * kept separate so the onboarding pass stays progress-free. Fold the two
 * together if a third caller ever needs the same loop.
 */
class CategorizeUncategorizedTransactionsJob implements ShouldQueue
{
    use Queueable;

    /**
     * A backfill can span many model calls, so give the batch plenty of room.
     */
    public int $timeout = 300;

    public function __construct(public User $user, public string $jobId) {}

    public function viaQueue(): string
    {
        return (string) config('ai_categorization.queue');
    }

    public static function cacheKeyForJobId(string $jobId): string
    {
        return "categorize_transactions_job_{$jobId}";
    }

    public function handle(AiCategorizationGate $gate, AiCategorizer $categorizer): void
    {
        if (! $gate->allows($this->user)) {
            $this->updateProgress('done', 0, 0, 0);

            return;
        }

        $pendingIds = Transaction::query()
            ->where('user_id', $this->user->id)
            ->whereNull('category_id')
            ->whereNull('description_iv')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->pluck('id');

        $total = $pendingIds->count();

        if ($total === 0) {
            $this->updateProgress('done', 0, 0, 0);

            return;
        }

        $this->updateProgress('processing', 0, $total, 0);

        $batchSize = max(1, (int) config('ai_categorization.group_batch_size'));
        $processed = 0;
        $applied = 0;

        // Chunk a fixed snapshot of ids so transactions left blank (below the
        // confidence bar) are never re-processed on a later iteration.
        foreach ($pendingIds->chunk($batchSize) as $chunkIds) {
            $chunk = Transaction::query()->whereIn('id', $chunkIds->all())->get();

            $outcomes = $categorizer->run($this->user, new Collection($chunk->all()));
            $applied += count(array_filter($outcomes, fn ($outcome): bool => $outcome->applied));
            $processed += $chunkIds->count();

            $this->updateProgress('processing', $processed, $total, $applied);
        }

        $this->updateProgress('done', $processed, $total, $applied);
    }

    /**
     * @param  'processing'|'done'  $status
     */
    private function updateProgress(string $status, int $processed, int $total, int $applied): void
    {
        Cache::put(self::cacheKeyForJobId($this->jobId), [
            'status' => $status,
            'processed' => $processed,
            'total' => $total,
            'applied' => $applied,
        ], now()->addHour());
    }
}
