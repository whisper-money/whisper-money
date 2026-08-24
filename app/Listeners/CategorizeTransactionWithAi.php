<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use App\Models\Transaction;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;

/**
 * Real-time tier of AI auto-categorization. Runs AFTER the synchronous automation
 * rules (it is queued, so it executes once the transaction is persisted) and only
 * acts when the transaction is still uncategorized and the user is eligible.
 *
 * Queued on its own connection/queue so a backlog never delays bank syncs, and a
 * Gemini outage can't block the import pipeline.
 */
class CategorizeTransactionWithAi implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly AiCategorizationGate $gate,
        private readonly AiCategorizer $categorizer,
    ) {}

    public function viaQueue(): string
    {
        return (string) config('ai_categorization.queue');
    }

    /**
     * Avoid publishing a job that would immediately no-op. In the common case the
     * transaction is already categorized by the synchronous automation rules that
     * run before this listener, or the user simply isn't AI-eligible, so queuing
     * would only cost a wasted jobs-table insert and a wasted worker dequeue.
     */
    public function shouldQueue(TransactionCreated $event): bool
    {
        return $this->shouldCategorize($event->transaction);
    }

    public function handle(TransactionCreated $event): void
    {
        $transaction = $event->transaction;

        // Re-check at run time: eligibility or the category may have changed while
        // the job waited in the queue.
        if (! $this->shouldCategorize($transaction)) {
            return;
        }

        $this->categorizer->run($transaction->user, new Collection([$transaction]));
    }

    private function shouldCategorize(Transaction $transaction): bool
    {
        if ($transaction->category_id !== null) {
            return false;
        }

        if ($transaction->description_iv !== null) {
            return false;
        }

        $user = $transaction->user;

        if ($user === null) {
            return false;
        }

        // Transactions imported during onboarding are deliberately skipped here:
        // the bulk of them are covered by the AI automation rules generated at the
        // end of onboarding, and whatever is left is categorized in a single batch
        // pass once onboarding completes (CategorizeOnboardingTransactionsJob).
        if (! $user->isOnboarded()) {
            return false;
        }

        return $this->gate->allows($user);
    }
}
