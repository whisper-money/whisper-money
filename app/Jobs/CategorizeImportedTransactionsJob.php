<?php

namespace App\Jobs;

use App\Enums\ImportAiStatus;
use App\Models\Import;
use App\Models\Transaction;
use App\Services\Ai\AiCategorizationGate;
use App\Services\Ai\AiCategorizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The single AI pass over a full import: whatever the file left uncategorized,
 * once every category it brought exists, so the model can file movements into
 * them too. The per-transaction listener skips imported rows for exactly this.
 *
 * Progress goes onto the import row, which the wizard's last screens and
 * Settings read.
 */
class CategorizeImportedTransactionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A large import can span many model calls, so give the batch plenty of
     * room. Kept below the database queue's retry_after (QueueConfigTest).
     */
    public int $timeout = 600;

    /**
     * Re-running a partially completed pass re-bills the model for work already
     * done, so never retry.
     */
    public int $tries = 1;

    /**
     * Safety TTL for the unique lock in case a worker dies mid-run.
     */
    public int $uniqueFor = 1800;

    public function __construct(public Import $import) {}

    public function uniqueId(): string
    {
        return $this->import->id;
    }

    public function viaQueue(): string
    {
        return (string) config('ai_categorization.queue');
    }

    public function handle(AiCategorizationGate $gate, AiCategorizer $categorizer): void
    {
        $user = $this->import->user;

        // Re-checked at run time: the plan or the consent may have lapsed while
        // the job waited, or the import been undone.
        if ($user === null || $this->import->undone_at !== null || ! $gate->allows($user)) {
            $this->import->recordStats(['ai' => ['status' => ImportAiStatus::Unavailable->value]]);

            return;
        }

        $result = $categorizer->backfill(
            $user,
            fn (int $processed, int $total, int $applied) => $this->import->recordStats(['ai' => [
                'status' => ImportAiStatus::Running->value,
                'processed' => $processed,
                'total' => $total,
                'applied' => $applied,
            ]]),
            fn (Builder $query): Builder => $query->where('import_id', $this->import->id),
        );

        $this->import->recordStats(['ai' => [
            'status' => ImportAiStatus::Done->value,
            ...$result,
        ], 'uncategorized' => Transaction::query()->where('import_id', $this->import->id)->whereNull('category_id')->count()]);
    }

    /**
     * Runs on a fresh instance (see .ai/rules/jobs.md), so it reads the import
     * back rather than trusting anything handle() held.
     */
    public function failed(?Throwable $exception): void
    {
        Import::query()->find($this->import->id)?->recordStats(['ai' => ['status' => ImportAiStatus::Failed->value]]);
    }
}
