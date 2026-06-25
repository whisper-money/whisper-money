<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

    /**
     * Re-running a partially completed backfill resets progress and re-bills the
     * model, so never retry — surface the failure to the client instead.
     */
    public int $tries = 1;

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
        $progress = Cache::get(self::cacheKeyForJobId($this->jobId), [
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
        Cache::put(self::cacheKeyForJobId($this->jobId), [
            'status' => $status,
            'processed' => $processed,
            'total' => $total,
            'applied' => $applied,
        ], now()->addHour());
    }
}
