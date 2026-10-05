<?php

namespace App\Services\Imports;

use App\Jobs\CategorizeImportedTransactionsJob;
use App\Models\Import;
use App\Services\Ai\AiCategorizationGate;

/**
 * Decides what happens to the movements a full import left uncategorized,
 * once every category from the file exists: one AI pass for an eligible user,
 * nothing for anyone else. A user still onboarding is left to the onboarding's
 * own AI step, which already categorizes everything still blank in one go
 * (CategorizeOnboardingTransactionsJob).
 */
class ImportAiFollowUp
{
    public function __construct(private AiCategorizationGate $gate) {}

    public function handle(Import $import): void
    {
        $uncategorized = $import->transactions()->whereNull('category_id')->count();
        $user = $import->user;

        $status = match (true) {
            $uncategorized === 0 => 'skipped',
            ! $user->isOnboarded() => 'onboarding',
            ! $this->gate->allows($user) => 'unavailable',
            default => 'queued',
        };

        $import->recordStats([
            'uncategorized' => $uncategorized,
            'ai' => ['status' => $status, 'total' => $uncategorized, 'processed' => 0, 'applied' => 0],
        ]);

        if ($status === 'queued') {
            CategorizeImportedTransactionsJob::dispatch($import);
        }
    }
}
