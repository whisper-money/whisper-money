<?php

namespace App\Services\Imports;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Models\Import;

/**
 * Runs a full import from another app once the browser has staged all of it,
 * in the only order that works: wipe (when asked), accounts, categories
 * parents first, movements, balances, and then a single AI pass over whatever
 * is still uncategorized, now that every category from the file exists.
 *
 * Each stage is written to the import row as it starts, which is what the
 * progress screen polls and what lets the user close the tab. A stage that
 * fails leaves what the earlier ones wrote linked to the import, so undoing
 * it from Settings cleans up a half-finished run as well as a finished one.
 */
class FullImporter
{
    public function __construct(
        private ImportWiper $wiper,
        private ImportAccountWriter $accounts,
        private ImportCategoryWriter $categories,
        private ImportTransactionWriter $transactions,
        private ImportBalanceWriter $balances,
        private ImportAiFollowUp $ai,
    ) {}

    public function run(Import $import): void
    {
        $import->forceFill(['status' => ImportStatus::Processing, 'started_at' => now()])->save();
        $plan = $import->plan ?? [];

        if ($import->mode === ImportMode::Wipe) {
            $this->stage($import, 'wipe');
            $import->recordStats(['wiped' => $this->wiper->wipe($import)]);
        }

        $this->stage($import, 'accounts');
        $accounts = $this->accounts->write($import, $plan['accounts'] ?? []);

        $this->stage($import, 'categories');
        $categoryIds = $this->categories->write($import, $plan['categories'] ?? []);

        $this->stage($import, 'transactions');
        $this->transactions->write($import, $accounts, $categoryIds);

        $this->stage($import, 'balances');
        $this->balances->write($import, $accounts);

        $this->stage($import, 'ai');
        $this->ai->handle($import);

        $this->finish($import, ImportStatus::Completed);
    }

    /**
     * Close the import, one way or the other. The staged rows and the plan
     * held the user's file in the meantime and are not kept past this: the
     * row keeps the counts, and the data itself now lives where it was
     * imported to.
     */
    public function finish(Import $import, ImportStatus $status, ?string $error = null): void
    {
        $import->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'plan' => null,
            'error' => $error,
        ])->save();

        $import->recordStats(['stage' => 'done']);
        $import->chunks()->delete();
    }

    private function stage(Import $import, string $stage): void
    {
        $import->recordStats(['stage' => $stage]);
    }
}
