<?php

namespace App\Services;

use App\Jobs\ApplySingleAutomationRuleJob;
use App\Models\AutomationRule;
use App\Models\Space;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Applies an existing automation rule to transactions already in the database.
 * Rules otherwise only run on newly created transactions (see
 * App\Listeners\ApplyAutomationRules), so both the settings screen and the MCP
 * apply_automation_rule tool come through here to backfill history.
 *
 * Resolving the matches means evaluating the rule row by row in PHP, so the id
 * list is cached and re-used by the preview and the apply that follows it.
 */
class AutomationRuleApplier
{
    /**
     * Match counts at or below this are applied inline; anything larger goes to
     * the queue so the request does not hang on it.
     */
    public const SYNC_THRESHOLD = 100;

    private const MATCHES_CACHE_TTL_MINUTES = 15;

    public function __construct(private AutomationRuleService $service) {}

    /**
     * Ids of the transactions this rule matches, newest first.
     *
     * $space is a deliberate divergence for MCP callers: the web matches every
     * transaction the rule's owner has, while an MCP call is scoped to one space
     * so a personal-space rule can never recategorize a shared space's history.
     *
     * @return array<int, string>
     */
    public function matchingIds(AutomationRule $rule, bool $onlyUncategorized, ?Space $space = null): array
    {
        $cacheKey = $this->matchesCacheKey($rule, $onlyUncategorized, $space);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return array_values(array_unique($cached));
        }

        $rule->loadMissing('labels');

        $ids = [];

        $eagerLoads = $this->service->eagerLoadsForRuleEvaluation($rule);
        if ($onlyUncategorized && $rule->action_category_id === null) {
            $eagerLoads[] = 'labels';
        }

        $this->transactions($rule, $space)
            ->with(array_values(array_unique($eagerLoads)))
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->chunk(500, function ($transactions) use ($rule, $onlyUncategorized, &$ids) {
                foreach ($transactions as $transaction) {
                    if ($onlyUncategorized && $this->service->shouldSkipForOnlyUncategorized($rule, $transaction)) {
                        continue;
                    }

                    if ($this->service->ruleMatches($rule, $transaction)) {
                        $ids[] = $transaction->id;
                    }
                }
            });

        $ids = array_values(array_unique($ids));

        Cache::put($cacheKey, $ids, now()->addMinutes(self::MATCHES_CACHE_TTL_MINUTES));

        return $ids;
    }

    /**
     * Apply the rule's actions to the matched transactions right now.
     *
     * @param  array<int, string>  $transactionIds
     * @return array{applied: int, updated: int}
     */
    public function applyNow(AutomationRule $rule, array $transactionIds, bool $onlyUncategorized, ?Space $space = null): array
    {
        $rule->loadMissing('labels');

        $transactions = $this->transactions($rule, $space)
            ->whereIn('id', $transactionIds)
            ->with(['account.bank', 'category', 'labels'])
            ->get();

        $updated = $this->service->applyRuleActionsToTransactions($transactions, $rule, $onlyUncategorized);

        $this->forgetMatches($rule, $onlyUncategorized, $space);

        return ['applied' => $transactions->count(), 'updated' => $updated];
    }

    /**
     * Hand the matched transactions to the queue, returning the job id the web
     * client polls for progress.
     *
     * @param  array<int, string>  $transactionIds
     */
    public function queue(AutomationRule $rule, array $transactionIds, bool $onlyUncategorized, ?Space $space = null): string
    {
        $jobId = (string) Str::uuid();

        Cache::put(
            ApplySingleAutomationRuleJob::cacheKeyForJobId($rule->user_id, $jobId),
            ['status' => 'pending', 'processed' => 0, 'total' => count($transactionIds), 'applied' => 0, 'updated' => 0],
            now()->addHour(),
        );

        ApplySingleAutomationRuleJob::dispatch($rule, $jobId, $transactionIds, $onlyUncategorized);

        $this->forgetMatches($rule, $onlyUncategorized, $space);

        return $jobId;
    }

    public function forgetMatches(AutomationRule $rule, bool $onlyUncategorized, ?Space $space = null): void
    {
        Cache::forget($this->matchesCacheKey($rule, $onlyUncategorized, $space));
    }

    /**
     * The candidate transactions a rule may touch. Encrypted rows are excluded
     * because rule evaluation reads the plaintext description.
     *
     * @return Builder<Transaction>
     */
    private function transactions(AutomationRule $rule, ?Space $space): Builder
    {
        $query = Transaction::query()
            ->where('user_id', $rule->user_id)
            ->whereNull('description_iv');

        if ($space !== null) {
            $query->forSpace($space);
        }

        return $query;
    }

    private function matchesCacheKey(AutomationRule $rule, bool $onlyUncategorized, ?Space $space): string
    {
        $flag = $onlyUncategorized ? '1' : '0';
        $stamp = $rule->updated_at?->getTimestamp() ?? 0;
        $scope = $space === null ? '' : ":space:{$space->id}";

        return "automation_rule_matches:{$rule->user_id}:{$rule->id}:{$flag}:{$stamp}{$scope}";
    }
}
