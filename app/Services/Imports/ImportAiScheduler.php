<?php

namespace App\Services\Imports;

use App\Enums\ImportAiStatus;
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
class ImportAiScheduler
{
    public function __construct(private AiCategorizationGate $gate) {}

    /**
     * Record the decision on the import. It is only acted on once the import
     * is closed ({@see self::dispatchIfQueued()}), so the pass never starts
     * writing progress to a row the import job is still finishing.
     */
    public function decide(Import $import): ImportAiStatus
    {
        $uncategorized = $import->transactions()->whereNull('category_id')->count();
        $user = $import->user;

        $status = match (true) {
            $uncategorized === 0 => ImportAiStatus::Skipped,
            ! $user->isOnboarded() => ImportAiStatus::Onboarding,
            ! $this->gate->allows($user) => ImportAiStatus::Unavailable,
            default => ImportAiStatus::Queued,
        };

        $import->recordStats([
            'uncategorized' => $uncategorized,
            'ai' => ['status' => $status->value, 'total' => $uncategorized, 'processed' => 0, 'applied' => 0],
        ]);

        return $status;
    }

    public function dispatchIfQueued(Import $import, ImportAiStatus $status): void
    {
        if ($status === ImportAiStatus::Queued) {
            CategorizeImportedTransactionsJob::dispatch($import);
        }
    }
}
