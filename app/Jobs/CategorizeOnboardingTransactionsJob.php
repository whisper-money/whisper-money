<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One-shot AI categorization of everything still uncategorized when onboarding
 * completes. The per-transaction listener is skipped during onboarding, so the
 * AI rules generated in the suggestions step get first crack at the import (they
 * cover the bulk of it); this pass then labels whatever the rules left blank.
 *
 * Runs on the dedicated AI queue so it never delays the rest of the app.
 */
class CategorizeOnboardingTransactionsJob implements ShouldQueue
{
    use Queueable;

    /**
     * A fresh import can span many model calls, so give the batch plenty of room.
     */
    public int $timeout = 300;

    public function __construct(public User $user) {}

    public function viaQueue(): string
    {
        return (string) config('ai_categorization.queue');
    }

    public function handle(AiCategorizationGate $gate, AiCategorizer $categorizer): void
    {
        if (! $gate->allows($this->user)) {
            return;
        }

        $categorizer->backfill($this->user);
    }
}
