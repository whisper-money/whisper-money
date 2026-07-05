<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Categorize every uncategorized transaction for a user who has just granted AI
 * consent outside of onboarding. Progress is written to the cache so the
 * transactions page can poll it and surface live progress while the batch runs.
 *
 * De-duplicated per user: a second dispatch (double "Enable AI" click, or
 * re-enabling consent while a run is still in flight) would read the same
 * pending-transaction snapshot on a concurrent worker and re-bill the model for
 * work already underway — the exact harm tries=1 targets but cannot prevent,
 * since tries only bounds re-attempts of one dispatch, not duplicate dispatches.
 *
 * ponytail: mirrors CategorizeOnboardingTransactionsJob's selection + chunking;
 * kept separate so the onboarding pass stays progress-free. Fold the two
 * together if a third caller ever needs the same loop.
 */
class CategorizeUncategorizedTransactionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A backfill can span many model calls, so give the batch plenty of room.
     */
    public int $timeout = 300;

    /**
     * Re-running a partially completed backfill resets progress and re-bills the
     * model, so never retry — surface the failure to the client instead.
     */
    public int $tries = 1;

    /**
     * Safety TTL for the unique lock in case a worker dies mid-run; comfortably
     * longer than a full run.
     */
    public int $uniqueFor = 1800;

    public function __construct(public User $user, public string $jobId) {}

    public function uniqueId(): string
    {
        return $this->user->id;
    }

    public function viaQueue(): string
    {
        return (string) config('ai_categorization.queue');
    }

    public static function cacheKeyForJobId(string $userId, string $jobId): string
    {
        return "categorize_transactions_job_{$userId}_{$jobId}";
    }

    public function handle(AiCategorizationGate $gate, AiCategorizer $categorizer): void
    {
        if (! $gate->allows($this->user)) {
            $this->updateProgress('done', 0, 0, 0);

            return;
        }

        $result = $categorizer->backfill(
            $this->user,
            fn (int $processed, int $total, int $applied) => $this->updateProgress('processing', $processed, $total, $applied),
        );

        $this->updateProgress('done', $result['processed'], $result['total'], $result['applied']);
    }

    /**
     * Mark the run as failed so the polling client stops waiting instead of
     * spinning until the cache entry expires.
     */
    public function failed(?Throwable $exception): void
    {
        $progress = Cache::get(self::cacheKeyForJobId($this->user->id, $this->jobId), [
            'processed' => 0,
            'total' => 0,
            'applied' => 0,
        ]);

        $this->updateProgress(
            'failed',
            $progress['processed'] ?? 0,
            $progress['total'] ?? 0,
            $progress['applied'] ?? 0,
        );
    }

    /**
     * @param  'processing'|'done'|'failed'  $status
     */
    private function updateProgress(string $status, int $processed, int $total, int $applied): void
    {
        Cache::put(self::cacheKeyForJobId($this->user->id, $this->jobId), [
            'status' => $status,
            'processed' => $processed,
            'total' => $total,
            'applied' => $applied,
        ], now()->addHour());
    }
}
