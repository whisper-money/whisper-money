<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-run categorization for a user after a transient AI provider failure
 * (overload / rate limit) dropped one or more chunks. De-duplicated per user so
 * a surge that drops many chunks queues a single retry, and dispatched with a
 * delay so the provider can recover first. It re-reads the user's still-pending
 * transactions, so it is a no-op once everything is categorized.
 *
 * ponytail: the unique lock is held until this job finishes, so a retry that
 * overloads again cannot chain a further one — exactly one deferred attempt per
 * failure. Failed 503s are not billed; lift the lock to ShouldBeUniqueUntilProcessing
 * with an attempt cap if a single retry proves too few for long outages.
 */
class RetryTransientAiCategorizationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * Safety TTL for the unique lock in case a worker dies mid-run; comfortably
     * longer than the retry delay plus a run.
     */
    public int $uniqueFor = 1800;

    public function __construct(public User $user) {}

    public function viaQueue(): string
    {
        return (string) config('ai_categorization.queue');
    }

    public function uniqueId(): string
    {
        return $this->user->id;
    }

    public function handle(AiCategorizationGate $gate, AiCategorizer $categorizer): void
    {
        if (! $gate->allows($this->user)) {
            return;
        }

        $categorizer->backfill($this->user);
    }
}
