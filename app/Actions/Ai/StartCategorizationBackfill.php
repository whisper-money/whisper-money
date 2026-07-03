<?php

namespace App\Actions\Ai;

use App\Jobs\CategorizeUncategorizedTransactionsJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StartCategorizationBackfill
{
    public function __construct(private readonly AiCategorizationGate $gate) {}

    /**
     * Dispatch a categorization backfill when the user is eligible and has
     * something to categorize, seeding the progress cache the client polls.
     *
     * @return array{job_id: string, total: int}|null
     */
    public function handle(User $user): ?array
    {
        if (! $this->gate->allows($user)) {
            return null;
        }

        $total = Transaction::query()
            ->where('user_id', $user->id)
            ->pendingAiCategorization()
            ->count();

        if ($total === 0) {
            return null;
        }

        $jobId = (string) Str::uuid();

        Cache::put(
            CategorizeUncategorizedTransactionsJob::cacheKeyForJobId($user->id, $jobId),
            ['status' => 'pending', 'processed' => 0, 'total' => $total, 'applied' => 0],
            now()->addHour(),
        );

        CategorizeUncategorizedTransactionsJob::dispatch($user, $jobId);

        return ['job_id' => $jobId, 'total' => $total];
    }
}
