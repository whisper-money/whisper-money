<?php

namespace App\Jobs;

use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Services\AutomationRuleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ApplySingleAutomationRuleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, string>  $transactionIds
     */
    public function __construct(
        public AutomationRule $rule,
        public string $jobId,
        public array $transactionIds,
    ) {}

    public function handle(AutomationRuleService $service): void
    {
        $rule = $this->rule->loadMissing('labels');

        $total = count($this->transactionIds);
        $this->updateProgress(status: 'processing', processed: 0, total: $total, applied: 0, updated: 0);

        $processed = 0;
        $applied = 0;
        $changed = 0;

        Transaction::query()
            ->where('user_id', $rule->user_id)
            ->whereIn('id', $this->transactionIds)
            ->whereNull('description_iv')
            ->with(['account.bank', 'category', 'labels'])
            ->chunkById(100, function ($transactions) use ($service, $rule, $total, &$processed, &$applied, &$changed) {
                foreach ($transactions as $transaction) {
                    if ($service->applyRuleActions($transaction, $rule)) {
                        $changed++;
                    }
                    $applied++;
                    $processed++;
                    $this->updateProgress(status: 'processing', processed: $processed, total: $total, applied: $applied, updated: $changed);
                }
            });

        $this->updateProgress(status: 'done', processed: $processed, total: $total, applied: $applied, updated: $changed);
    }

    public function failed(\Throwable $exception): void
    {
        $cached = Cache::get($this->cacheKey());

        $this->updateProgress(
            status: 'failed',
            processed: $cached['processed'] ?? 0,
            total: $cached['total'] ?? 0,
            applied: $cached['applied'] ?? 0,
            updated: $cached['updated'] ?? 0,
        );
    }

    public static function cacheKeyForJobId(string $jobId): string
    {
        return "apply_automation_rule_job_{$jobId}";
    }

    private function cacheKey(): string
    {
        return self::cacheKeyForJobId($this->jobId);
    }

    /**
     * @param  'pending'|'processing'|'done'|'failed'  $status
     */
    private function updateProgress(string $status, int $processed, int $total, int $applied, int $updated): void
    {
        Cache::put($this->cacheKey(), [
            'status' => $status,
            'processed' => $processed,
            'total' => $total,
            'applied' => $applied,
            'updated' => $updated,
        ], now()->addHour());
    }
}
