<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
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

        $pendingIds = Transaction::query()
            ->where('user_id', $this->user->id)
            ->whereNull('category_id')
            ->whereNull('description_iv')
            ->pluck('id');

        if ($pendingIds->isEmpty()) {
            return;
        }

        $batchSize = max(1, (int) config('ai_categorization.group_batch_size'));

        // Chunk a fixed snapshot of ids so transactions left blank (below the
        // confidence bar) are never re-processed on a later iteration.
        foreach ($pendingIds->chunk($batchSize) as $chunkIds) {
            $chunk = Transaction::query()->whereIn('id', $chunkIds->all())->get();

            $categorizer->run($this->user, new Collection($chunk->all()));
        }
    }
}
