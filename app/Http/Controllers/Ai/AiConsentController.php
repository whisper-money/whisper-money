<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Jobs\CategorizeUncategorizedTransactionsJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AiConsentController extends Controller
{
    /**
     * Record the user's broad "use AI to help understand my finances" consent
     * and kick off a backfill of their uncategorized transactions.
     */
    public function store(Request $request, AiCategorizationGate $gate): JsonResponse
    {
        $user = $request->user();
        $user->recordAiConsent();

        return response()->json([
            'consented' => true,
            'categorization' => $this->startCategorization($user, $gate),
        ]);
    }

    /**
     * Revoke the user's AI consent.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->revokeAiConsent();

        return response()->json(['consented' => false]);
    }

    /**
     * Dispatch the backfill job when the user is eligible and has anything to
     * categorize, returning the job id the client polls for progress.
     *
     * @return array{job_id: string, total: int}|null
     */
    private function startCategorization(User $user, AiCategorizationGate $gate): ?array
    {
        if (! $gate->allows($user)) {
            return null;
        }

        $total = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereNull('description_iv')
            ->count();

        if ($total === 0) {
            return null;
        }

        $jobId = (string) Str::uuid();

        Cache::put(
            CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($jobId),
            ['status' => 'pending', 'processed' => 0, 'total' => $total, 'applied' => 0],
            now()->addHour(),
        );

        CategorizeUncategorizedTransactionsJob::dispatch($user, $jobId);

        return ['job_id' => $jobId, 'total' => $total];
    }
}
