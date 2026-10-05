<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Imports\FullImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

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
        // Processing without resolved accounts means an earlier attempt died
        // while creating them: running the preparation again would create
        // them twice. The import fails, and Settings can undo what it wrote.
        if ($this->import->status === ImportStatus::Processing && ! isset($this->import->plan['resolved'])) {
            $this->fail(new RuntimeException('The import stopped while it was preparing its accounts and categories.'));

            return;
        }

        if (! $importer->run($this->import)) {
            self::dispatch($this->import->fresh() ?? $this->import);
        }
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

        app(FullImporter::class)->finish($import, ImportStatus::Failed, 'The import stopped before it finished.');
    }
}
