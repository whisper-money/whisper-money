<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Imports\FullImporter;
use App\Services\Imports\ImportFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sentry\State\Scope;
use Throwable;

use function Sentry\configureScope;

/**
 * Writes a staged full import. Queued so the user can close the tab: the
 * progress lives on the import row, not in the browser.
 *
 * A large file takes several runs: each writes movements for a few minutes
 * and dispatches the next one, which carries on from the chunks still
 * staged. No run outlives the queue's reservation, whatever the file's size.
 */
class ProcessFullImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * One run writes movements for four minutes (FullImporter) plus whatever
     * the chunk in hand and the stages around it take. Kept below the database
     * queue's retry_after (QueueConfigTest).
     */
    public int $timeout = 600;

    /**
     * Safe to retry once the accounts and categories are resolved: the
     * movements and balances are written a committed chunk at a time, and a
     * chunk that was written is no longer staged. A retry that finds the
     * import mid-preparation fails instead (see handle()).
     */
    public int $tries = 3;

    public function __construct(public Import $import) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(FullImporter $importer): void
    {
        configureScope(fn (Scope $scope) => $scope->setTag('full_import_id', (string) $this->import->id));

        // Processing without resolved accounts means an earlier attempt died
        // while creating them, without an exception to show for it (a killed
        // worker): running the preparation again would create them twice.
        if ($this->import->status === ImportStatus::Processing && ! isset($this->import->plan['resolved'])) {
            $this->failWith(new RuntimeException('The import stopped while it was preparing its accounts and categories.'));

            return;
        }

        try {
            $finished = $importer->run($this->import, attempt: $this->attempts());
        } catch (Throwable $exception) {
            // Before the accounts and categories are resolved, a retry could
            // only end in the fail-fast above and bury the real cause under a
            // generic one: the job fails now, with the exception itself.
            if (! $this->isResolved()) {
                $this->failWith($exception);

                return;
            }

            throw $exception;
        }

        if (! $finished) {
            $import = $this->import->fresh() ?? $this->import;

            Log::warning('Full import resumed', $import->logContext([
                'attempt' => $this->attempts(),
                'processed' => $import->stats['transactions']['processed'] ?? 0,
                'total' => $import->stats['transactions']['total'] ?? 0,
            ]));

            self::dispatch($import);
        }
    }

    /**
     * Fail the job by hand. failed() is reached without the worker ever seeing
     * an exception, so it is reported here; one thrown out of handle() is
     * reported by the worker, on every attempt, and failed() adds nothing.
     */
    private function failWith(Throwable $exception): void
    {
        report($exception);
        $this->fail($exception);
    }

    private function isResolved(): bool
    {
        return isset(Import::query()->find($this->import->id)?->plan['resolved']);
    }

    /**
     * Runs on a fresh instance (see .ai/rules/jobs.md), so the import is read
     * back from the database. Whatever the import wrote before failing stays
     * linked to it, which is what lets Settings undo it.
     */
    public function failed(?Throwable $exception): void
    {
        $import = Import::query()->find($this->import->id);

        if ($import === null || $import->status === ImportStatus::Completed) {
            return;
        }

        Log::error('Full import failed', $import->logContext([
            'attempt' => $this->attempts(),
            'stage' => $import->stats['stage'] ?? null,
            ...$exception !== null ? ImportFailure::context($exception) : ['exception' => null, 'message' => null],
        ]));

        app(FullImporter::class)->finish($import, ImportStatus::Failed, Import::failureReason($exception));
    }
}
