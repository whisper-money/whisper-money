<?php

namespace App\Services\Imports;

use App\Enums\ImportMode;
use App\Enums\ImportStage;
use App\Enums\ImportStatus;
use App\Models\Import;

/**
 * Runs a full import from another app once the browser has staged all of it,
 * in the only order that works: wipe (when asked), accounts, categories
 * parents first, movements, balances, and then a single AI pass over whatever
 * is still uncategorized, now that every category from the file exists.
 *
 * The movements can take longer than one queued job may run, so they are
 * written for a while and then handed over: `run()` returns false when it
 * stopped at its deadline and the job dispatches the next run, which picks up
 * the accounts and categories the first one resolved from the import's plan.
 *
 * Each stage is written to the import row as it starts, which is what the
 * progress screen polls and what lets the user close the tab. A stage that
 * fails leaves what the earlier ones wrote linked to the import, so undoing
 * it from Settings cleans up a half-finished run as well as a finished one.
 */
class FullImporter
{
    /**
     * How long one run writes movements before handing over to a fresh job,
     * well inside ProcessFullImportJob's own timeout.
     */
    private const TRANSACTIONS_SECONDS_PER_RUN = 240;

    public function __construct(
        private ImportWiper $wiper,
        private ImportAccountWriter $accounts,
        private ImportCategoryWriter $categories,
        private ImportTransactionWriter $transactions,
        private ImportBalanceWriter $balances,
        private ImportAiScheduler $ai,
    ) {}

    /**
     * @param  int  $transactionSeconds  how long this run may write movements
     * @return bool whether the import is finished; false means another run is needed
     */
    public function run(Import $import, int $transactionSeconds = self::TRANSACTIONS_SECONDS_PER_RUN): bool
    {
        if (! isset($import->plan['resolved'])) {
            $this->prepare($import);
        }

        $accounts = ImportAccountMap::fromArray($import->plan['resolved']['accounts'] ?? []);
        $categoryIds = $import->plan['resolved']['categories'] ?? [];

        $import->recordStage(ImportStage::Transactions);

        if (! $this->transactions->write($import, $accounts, $categoryIds, now()->addSeconds($transactionSeconds))) {
            return false;
        }

        $import->recordStage(ImportStage::Balances);
        $this->balances->write($import, $accounts);

        $import->recordStage(ImportStage::Ai);
        $aiStatus = $this->ai->decide($import);

        $this->finish($import, ImportStatus::Completed);
        $this->ai->dispatchIfQueued($import, $aiStatus);

        return true;
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

        $import->recordStage(ImportStage::Done);
        $import->chunks()->delete();
    }

    /**
     * Everything before the movements, done once: the wipe, the accounts and
     * the categories. What they resolved to is stored on the plan, for this
     * run and any that follow.
     */
    private function prepare(Import $import): void
    {
        $import->forceFill(['status' => ImportStatus::Processing, 'started_at' => now()])->save();
        $plan = $import->plan ?? [];

        if ($import->mode === ImportMode::Wipe) {
            $import->recordStage(ImportStage::Wipe);
            $import->recordStats(['wiped' => $this->wiper->wipe($import)]);
        }

        $import->recordStage(ImportStage::Accounts);
        $accounts = $this->accounts->write($import, $plan['accounts'] ?? []);

        $import->recordStage(ImportStage::Categories);
        $categoryIds = $this->categories->write($import, $plan['categories'] ?? []);

        $import->forceFill(['plan' => [
            ...$plan,
            'resolved' => ['accounts' => $accounts->toArray(), 'categories' => $categoryIds],
        ]])->save();
    }
}
