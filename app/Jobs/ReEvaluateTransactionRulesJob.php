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
    ) {}

    public function handle(AutomationRuleService $service): void
    {
        $query = Transaction::query()
            ->where('user_id', $this->user->id)
            ->whereNull('description_iv');

        if ($this->transactionIds !== null) {
            $query->whereIn('id', $this->transactionIds);
        }

        $total = $query->count();

        $this->updateProgress(status: 'processing', processed: 0, total: $total, updated: 0);

        $processed = 0;
        $updated = 0;

        $query->with(['account.bank', 'category', 'labels'])->chunkById(100, function ($transactions) use ($service, $total, &$processed, &$updated) {
            foreach ($transactions as $transaction) {
                $categoryBefore = $transaction->category_id;
                $labelsBefore = $transaction->labels->pluck('id')->sort()->values()->all();
                $notesBefore = $transaction->notes;

                $service->applyRules($transaction);

                $transaction->refresh();
                $categoryAfter = $transaction->category_id;
                $labelsAfter = $transaction->labels->pluck('id')->sort()->values()->all();
                $notesAfter = $transaction->notes;

                if ($categoryBefore !== $categoryAfter || $labelsBefore !== $labelsAfter || $notesBefore !== $notesAfter) {
                    $updated++;
                }

                $processed++;
                $this->updateProgress(status: 'processing', processed: $processed, total: $total, updated: $updated);
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

    public static function cacheKeyForJobId(string $jobId): string
    {
        return "re_evaluate_rules_job_{$jobId}";
    }

    private function cacheKey(): string
    {
        return self::cacheKeyForJobId($this->jobId);
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
