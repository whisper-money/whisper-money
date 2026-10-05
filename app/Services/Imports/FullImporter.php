<?php

namespace App\Services\Imports;

use App\Enums\ImportAccountAction;
use App\Enums\ImportCategoryAction;
use App\Enums\ImportChunkKind;
use App\Enums\ImportMode;
use App\Enums\ImportStage;
use App\Enums\ImportStatus;
use App\Models\Import;
use Illuminate\Support\Facades\Log;

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
     * @param  int  $attempt  the job attempt running it, for the logs
     * @return bool whether the import is finished; false means another run is needed
     */
    public function run(Import $import, int $transactionSeconds = self::TRANSACTIONS_SECONDS_PER_RUN, int $attempt = 1): bool
    {
        // A late retry, or a run queued twice: whatever happened to the
        // import since, it is not this run's to write any more.
        if (! in_array($import->status, [ImportStatus::Queued, ImportStatus::Processing], true)) {
            return true;
        }

        if (! isset($import->plan['resolved'])) {
            $this->prepare($import, $attempt);
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

        $stats = $import->stats ?? [];

        Log::info('Full import finished', $import->logContext([
            'status' => $status->value,
            'imported' => $stats['transactions']['imported'] ?? 0,
            'skipped_duplicates' => $stats['transactions']['duplicates'] ?? 0,
            'banks_created' => $stats['accounts']['banks_created'] ?? 0,
            'categories_created' => $stats['categories']['created'] ?? 0,
            'categories_matched' => $stats['categories']['matched'] ?? 0,
            'balances_imported' => $stats['balances']['imported'] ?? 0,
            'ai_status' => $stats['ai']['status'] ?? null,
            'duration_seconds' => $import->started_at?->diffInSeconds($import->finished_at),
        ]));
    }

    /**
     * Everything before the movements, done once: the wipe, the accounts and
     * the categories. What they resolved to is stored on the plan, for this
     * run and any that follow.
     */
    private function prepare(Import $import, int $attempt): void
    {
        $import->forceFill(['status' => ImportStatus::Processing, 'started_at' => now()])->save();
        $plan = $import->plan ?? [];
        $accounts = collect($plan['accounts'] ?? []);

        Log::info('Full import started', $import->logContext([
            'attempt' => $attempt,
            'expected_transactions' => $plan['expected'][ImportChunkKind::Transactions->value] ?? 0,
            'expected_balances' => $plan['expected'][ImportChunkKind::Balances->value] ?? 0,
            'accounts_new' => $accounts->where('action', ImportAccountAction::Create->value)->count(),
            'accounts_mapped' => $accounts->where('action', ImportAccountAction::Map->value)->count(),
            'categories_new' => collect($plan['categories'] ?? [])->where('action', ImportCategoryAction::Create->value)->count(),
        ]));

        // The wipe deletes the user's accounts, so it runs once whatever
        // happens next: `stats.wiped` is written as soon as it is done.
        if ($import->mode === ImportMode::Wipe && ! isset($import->stats['wiped'])) {
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
