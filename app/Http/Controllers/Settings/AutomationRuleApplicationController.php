<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApplyAutomationRuleRequest;
use App\Jobs\ApplySingleAutomationRuleJob;
use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Services\AutomationRuleApplier;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AutomationRuleApplicationController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE_DEFAULT = 50;

    private const PER_PAGE_MAX = 100;

    /**
     * Return paginated transactions matching this rule.
     */
    public function matches(
        Request $request,
        AutomationRule $automationRule,
        AutomationRuleApplier $applier,
    ): JsonResponse {
        $this->authorize('update', $automationRule);

        $onlyUncategorized = $request->boolean('only_uncategorized', true);
        $offset = max(0, (int) $request->integer('offset', 0));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) $request->integer('per_page', self::PER_PAGE_DEFAULT)));

        $matchingIds = $applier->matchingIds($automationRule, $onlyUncategorized);
        $total = count($matchingIds);
        $pageIds = array_slice($matchingIds, $offset, $perPage);

        $transactions = Transaction::query()
            ->whereIn('id', $pageIds)
            ->with(['account.bank', 'category', 'labels'])
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
        AutomationRuleApplier $applier,
    ): JsonResponse {
        $this->authorize('update', $automationRule);

        $onlyUncategorized = (bool) $request->boolean('only_uncategorized', true);
        $matchingIds = $applier->matchingIds($automationRule, $onlyUncategorized);
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

        if ($total <= AutomationRuleApplier::SYNC_THRESHOLD) {
            $result = $applier->applyNow($automationRule, $matchingIds, $onlyUncategorized);

            return response()->json([
                'status' => 'done',
                'processed' => $result['applied'],
                'total' => $total,
                'applied' => $result['applied'],
                'updated' => $result['updated'],
            ]);
        }

        return response()->json([
            'job_id' => $applier->queue($automationRule, $matchingIds, $onlyUncategorized),
            'total' => $total,
        ], 202);
    }

    /**
     * Return progress for a running apply job.
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $progress = Cache::get(ApplySingleAutomationRuleJob::cacheKeyForJobId($request->user()->id, $jobId));

        if ($progress === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        return response()->json($progress);
    }
}
