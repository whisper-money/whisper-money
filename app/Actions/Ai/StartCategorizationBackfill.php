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
        // Onboarding has its own pass, and it runs later on purpose:
        // `OnboardingController::categorize` fires it when the user leaves the
        // AI step, after the rules that step drafted have been seen and
        // approved. Consent is recorded on that same step, so without this
        // guard granting it categorized the whole import while the user was
        // still reading "Nothing is applied that you haven't seen on the next
        // screen" — and queued a second batch behind it.
        if (! $user->isOnboarded()) {
            return null;
        }

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
