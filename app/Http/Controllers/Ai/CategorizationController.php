<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Jobs\CategorizeUncategorizedTransactionsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategorizationController extends Controller
{
    /**
     * Return current progress for a consent-triggered categorization backfill.
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $progress = Cache::get(CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($request->user()->id, $jobId));

        if ($progress === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        return response()->json($progress);
    }
}
