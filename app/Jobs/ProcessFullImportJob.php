<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Imports\FullImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes a staged full import. Queued so the user can close the tab: the
 * progress lives on the import row, not in the browser.
 */
class ProcessFullImportJob implements ShouldQueue
{
    use Queueable;

    /**
     * Thousands of movements, each through the automation rules and the
     * budgets. Kept below the database queue's retry_after (QueueConfigTest).
     */
    public int $timeout = 600;

    /**
     * A second run would write the new accounts a second time. A failed
     * import is undone from Settings and started again instead.
     */
    public int $tries = 1;

    public function __construct(public Import $import) {}

    public function handle(FullImporter $importer): void
    {
        $importer->run($this->import);
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
