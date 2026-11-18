<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ReEvaluateTransactionRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public User $user,
        public string $jobId,
        public ?array $transactionIds = null,
        public ?array $filters = null,
    ) {}

    public function handle(AutomationRuleService $service): void
    {
        $query = Transaction::query()
            ->where('user_id', $this->user->id);

        if ($this->transactionIds !== null) {
            $query->whereIn('id', $this->transactionIds);
        } elseif ($this->filters !== null) {
            $query->applyFilters([...$this->filters, 'user_id' => $this->user->id]);
        }

        $total = $query->count();

        $this->updateProgress(status: 'processing', processed: 0, total: $total, updated: 0);

        $processed = 0;
        $updated = 0;

        $query->with(['account.bank', 'category', 'labels'])->chunkById(100, function ($transactions) use ($service, $total, &$processed, &$updated) {
            $reassignIds = [];

            foreach ($transactions as $transaction) {
                $notesBefore = $transaction->notes;

                // The reassignment is batched per chunk below, so this must not
                // queue a job of its own for every transaction it changes.
                $affectsBudgets = $service->applyRules($transaction, reassignBudgets: false);

                if ($affectsBudgets) {
                    $reassignIds[] = $transaction->id;
                }

                // applyRules() returns true only when it changed the category or
                // attached a label, and it writes a note on the in-memory model, so
                // the outcome is known without reloading the row.
                if ($affectsBudgets || $transaction->notes !== $notesBefore) {
                    $updated++;
                }

                $processed++;
            }

            $this->updateProgress(status: 'processing', processed: $processed, total: $total, updated: $updated);

            // Re-evaluating the whole history is an administrative act, not
            // spending, so it must not raise budget emails.
            if ($reassignIds !== []) {
                ReassignTransactionsToBudgets::dispatch($reassignIds, notify: false);
            }
        });

        $this->updateProgress(status: 'done', processed: $processed, total: $total, updated: $updated);
    }

    public function failed(\Throwable $exception): void
    {
        $cached = Cache::get($this->cacheKey());

        $this->updateProgress(
            status: 'failed',
            processed: $cached['processed'] ?? 0,
            total: $cached['total'] ?? 0,
            updated: $cached['updated'] ?? 0,
        );
    }

    public static function cacheKeyForJobId(string $userId, string $jobId): string
    {
        return "re_evaluate_rules_job_{$userId}_{$jobId}";
    }

    private function cacheKey(): string
    {
        return self::cacheKeyForJobId($this->user->id, $this->jobId);
    }

    /**
     * @param  'pending'|'processing'|'done'|'failed'  $status
     */
    private function updateProgress(string $status, int $processed, int $total, int $updated): void
    {
        Cache::put($this->cacheKey(), [
            'status' => $status,
            'processed' => $processed,
            'total' => $total,
            'updated' => $updated,
        ], now()->addHour());
    }
}
