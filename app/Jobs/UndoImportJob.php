<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Imports\ImportFailure;
use App\Services\Imports\ImportUndoer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Sentry\State\Scope;
use Throwable;

use function Sentry\configureScope;

/**
 * Takes a full import back out of the user's data. Queued because a large
 * import is too much to delete inside a request; Settings shows the import
 * as being undone and polls until it is.
 */
class UndoImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Deletes go in chunks, so even a large import fits comfortably. Kept
     * below the database queue's retry_after (QueueConfigTest).
     */
    public int $timeout = 600;

    /** Every step only removes what is still there, so a retry is safe. */
    public int $tries = 3;

    public function __construct(public Import $import) {}

    public function uniqueId(): string
    {
        return $this->import->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ImportUndoer $undoer): void
    {
        configureScope(fn (Scope $scope) => $scope->setTag('full_import_id', (string) $this->import->id));

        if ($this->import->status !== ImportStatus::Undoing || $this->import->undone_at !== null) {
            return;
        }

        $undoer->undo($this->import);
    }

    /**
     * Runs on a fresh instance (see .ai/rules/jobs.md), so the import is read
     * back from the database. It goes back to the status it had, so the user
     * can try the undo again; whatever was already removed stays removed.
     */
    public function failed(?Throwable $exception): void
    {
        $import = Import::query()->find($this->import->id);

        if ($import === null || $import->status !== ImportStatus::Undoing) {
            return;
        }

        $import->forceFill([
            'status' => ImportStatus::tryFrom((string) ($import->stats['undo']['previous_status'] ?? '')) ?? ImportStatus::Completed,
        ])->save();

        // The reason stays with the import for whoever investigates; the
        // client only learns that the undo failed (ImportHistoryPresenter).
        $import->recordStats(['undo' => ['failed' => true, 'error' => Import::failureReason($exception)]]);

        Log::error('Full import undo failed', $import->logContext([
            'attempt' => $this->attempts(),
            ...$exception !== null ? ImportFailure::context($exception) : ['exception' => null, 'message' => null],
        ]));
    }
}
