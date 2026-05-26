<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApplyAutomationRuleRequest;
use App\Jobs\ApplySingleAutomationRuleJob;
use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Services\AutomationRuleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AutomationRuleApplicationController extends Controller
{
    use AuthorizesRequests;

    private const SYNC_THRESHOLD = 100;

    private const MATCHES_CACHE_TTL_MINUTES = 15;

    private const PER_PAGE_DEFAULT = 50;

    private const PER_PAGE_MAX = 100;

    /**
     * Return paginated transactions matching this rule.
     */
    public function matches(
        Request $request,
        AutomationRule $automationRule,
        AutomationRuleService $service,
    ): JsonResponse {
        $this->authorize('update', $automationRule);

        $onlyUncategorized = $request->boolean('only_uncategorized', true);
        $offset = max(0, (int) $request->integer('offset', 0));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) $request->integer('per_page', self::PER_PAGE_DEFAULT)));

        $matchingIds = $this->resolveMatchingIds($automationRule, $service, $onlyUncategorized);
        $total = count($matchingIds);
        $pageIds = array_slice($matchingIds, $offset, $perPage);

        $transactions = Transaction::query()
            ->whereIn('id', $pageIds)
            ->with(['account:id,name,bank_id', 'account.bank:id,name', 'category:id,name,icon,color', 'labels:id,name,color'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->get();

        $byId = $transactions->keyBy('id');
        $ordered = collect($pageIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->values();

        $nextOffset = $offset + $transactions->count();

        return response()->json([
            'data' => $ordered,
            'total' => $total,
            'next_offset' => $nextOffset < $total ? $nextOffset : null,
        ]);
    }

    /**
     * Apply the rule's actions to all matching transactions.
     *
     * Runs synchronously when the match count is below the threshold, otherwise
     * dispatches a queued job and returns a job id for status polling.
     */
    public function apply(
        ApplyAutomationRuleRequest $request,
        AutomationRule $automationRule,
        AutomationRuleService $service,
    ): JsonResponse {
        $this->authorize('update', $automationRule);

        $automationRule->loadMissing('labels');

        $onlyUncategorized = (bool) $request->boolean('only_uncategorized', true);
        $matchingIds = $this->resolveMatchingIds($automationRule, $service, $onlyUncategorized);
        $total = count($matchingIds);

        if ($total === 0) {
            return response()->json([
                'status' => 'done',
                'processed' => 0,
                'total' => 0,
                'applied' => 0,
                'updated' => 0,
            ]);
        }

        if ($total <= self::SYNC_THRESHOLD) {
            $transactions = Transaction::query()
                ->where('user_id', $automationRule->user_id)
                ->whereIn('id', $matchingIds)
                ->whereNull('description_iv')
                ->with(['account.bank', 'category', 'labels'])
                ->get();

            $changed = $service->applyRuleActionsToTransactions($transactions, $automationRule);

            $applied = $transactions->count();

            $this->forgetMatchesCache($automationRule, $onlyUncategorized);

            return response()->json([
                'status' => 'done',
                'processed' => $applied,
                'total' => $total,
                'applied' => $applied,
                'updated' => $changed,
            ]);
        }

        $jobId = (string) Str::uuid();

        Cache::put(
            ApplySingleAutomationRuleJob::cacheKeyForJobId($jobId),
            ['status' => 'pending', 'processed' => 0, 'total' => $total, 'applied' => 0, 'updated' => 0],
            now()->addHour(),
        );

        ApplySingleAutomationRuleJob::dispatch($automationRule, $jobId, $matchingIds);

        $this->forgetMatchesCache($automationRule, $onlyUncategorized);

        return response()->json([
            'job_id' => $jobId,
            'total' => $total,
        ], 202);
    }

    /**
     * Return progress for a running apply job.
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $progress = Cache::get(ApplySingleAutomationRuleJob::cacheKeyForJobId($jobId));

        if ($progress === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        return response()->json($progress);
    }

    /**
     * Resolve and cache the list of transaction IDs matching this rule.
     *
     * @return array<int, string>
     */
    private function resolveMatchingIds(
        AutomationRule $rule,
        AutomationRuleService $service,
        bool $onlyUncategorized,
    ): array {
        $cacheKey = $this->matchesCacheKey($rule, $onlyUncategorized);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return array_values(array_unique($cached));
        }

        $rule->loadMissing('labels');

        $ids = [];

        $eagerLoads = $service->eagerLoadsForRuleEvaluation($rule);
        if ($onlyUncategorized && $rule->action_category_id === null) {
            $eagerLoads[] = 'labels';
        }

        Transaction::query()
            ->where('user_id', $rule->user_id)
            ->whereNull('description_iv')
            ->with(array_values(array_unique($eagerLoads)))
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->chunk(500, function ($transactions) use ($rule, $service, $onlyUncategorized, &$ids) {
                foreach ($transactions as $transaction) {
                    if ($onlyUncategorized && $service->shouldSkipForOnlyUncategorized($rule, $transaction)) {
                        continue;
                    }

                    if ($service->ruleMatches($rule, $transaction)) {
                        $ids[] = $transaction->id;
                    }
                }
            });

        $ids = array_values(array_unique($ids));

        Cache::put($cacheKey, $ids, now()->addMinutes(self::MATCHES_CACHE_TTL_MINUTES));

        return $ids;
    }

    private function matchesCacheKey(AutomationRule $rule, bool $onlyUncategorized): string
    {
        $flag = $onlyUncategorized ? '1' : '0';
        $stamp = $rule->updated_at?->getTimestamp() ?? 0;

        return "automation_rule_matches:{$rule->user_id}:{$rule->id}:{$flag}:{$stamp}";
    }

    private function forgetMatchesCache(AutomationRule $rule, bool $onlyUncategorized): void
    {
        Cache::forget($this->matchesCacheKey($rule, $onlyUncategorized));
    }
}
