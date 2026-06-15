<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
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

    public function handle(TransactionCreated $event): void
    {
        $transaction = $event->transaction;

        if ($transaction->category_id !== null) {
            return;
        }

        if ($transaction->description_iv !== null) {
            return;
        }

        $user = $transaction->user;

        if ($user === null) {
            return;
        }

        // Transactions imported during onboarding are deliberately skipped here:
        // the bulk of them are covered by the AI automation rules generated at the
        // end of onboarding, and whatever is left is categorized in a single batch
        // pass once onboarding completes (CategorizeOnboardingTransactionsJob).
        if (! $user->isOnboarded()) {
            return;
        }

        if (! $this->gate->allows($user)) {
            return;
        }

        $this->categorizer->run($user, new Collection([$transaction]));
    }
}
