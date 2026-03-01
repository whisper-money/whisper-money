<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkReEvaluateRulesRequest;
use App\Jobs\ReEvaluateTransactionRulesJob;
use App\Models\Transaction;
use App\Services\AutomationRuleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ReEvaluateTransactionRulesController extends Controller
{
    use AuthorizesRequests;

    /**
     * Re-evaluate automation rules for a single transaction.
     */
    public function single(Request $request, Transaction $transaction, AutomationRuleService $service): JsonResponse
    {
        $this->authorize('update', $transaction);

        $service->applyRules($transaction);

        $transaction->refresh()->load('labels:id,name,color', 'category:id,name,icon,color');

        return response()->json([
            'data' => $transaction,
        ]);
    }

    /**
     * Dispatch a background job to re-evaluate all (or selected) transactions.
     *
     * Returns a job ID the client can use to poll the status endpoint.
     */
    public function bulk(BulkReEvaluateRulesRequest $request): JsonResponse
    {
        $user = $request->user();
        $transactionIds = $request->input('transaction_ids');
        $filters = $request->input('filters');

        $jobId = (string) Str::uuid();

        // Set initial pending state so the first poll returns something meaningful
        Cache::put(
            ReEvaluateTransactionRulesJob::cacheKeyForJobId($jobId),
            ['status' => 'pending', 'processed' => 0, 'total' => 0, 'updated' => 0],
            now()->addHour(),
        );

        ReEvaluateTransactionRulesJob::dispatch($user, $jobId, $transactionIds, $filters);

        return response()->json([
            'job_id' => $jobId,
        ], 202);
    }

    /**
     * Return current progress for a bulk re-evaluation job.
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $cacheKey = ReEvaluateTransactionRulesJob::cacheKeyForJobId($jobId);
        $progress = Cache::get($cacheKey);

        if ($progress === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        return response()->json($progress);
    }
}
