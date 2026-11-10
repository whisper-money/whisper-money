<?php

namespace App\Services\Subscriptions;

use App\Enums\TransactionSource;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Subscription;

/**
 * What the plan has done for a trialing user so far: the movements their bank
 * connections synced since the trial started, and the share of those that
 * already carry a category. Read by the trial-ending banner and email, which
 * make the case for keeping the plan with these two numbers.
 *
 * Only bank-synced rows count, because syncing is what the plan sells; manual
 * and imported rows would work just as well on the free plan.
 */
class TrialProgress
{
    /** The sources a bank connection writes; everything else the user entered. */
    private const SYNCED_SOURCES = [TransactionSource::EnableBanking, TransactionSource::Wise];

    /** Fewer synced movements than this make no case, so nothing is said. */
    private const MIN_SYNCED = 2;

    /**
     * Kept briefly: the banner asks on every page of the trial's last week,
     * and the figures only need to be about right.
     */
    private const CACHE_MINUTES = 30;

    /**
     * Null when there is too little to say. `categorizedPercent` is 0 when
     * nothing is sorted yet, which the readers leave out rather than print.
     *
     * @return array{synced: int, categorizedPercent: int}|null
     */
    public function for(User $user, Subscription $subscription): ?array
    {
        return $this->count($user, $subscription);
    }

    /**
     * The same figures, cached for the screens that ask on every request.
     *
     * @return array{synced: int, categorizedPercent: int}|null
     */
    public function cachedFor(User $user, Subscription $subscription): ?array
    {
        return Cache::remember(
            "trial-progress:{$user->id}:{$subscription->id}",
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): array => ['progress' => $this->count($user, $subscription)],
        )['progress'];
    }

    /**
     * @return array{synced: int, categorizedPercent: int}|null
     */
    private function count(User $user, Subscription $subscription): ?array
    {
        $counts = $user->transactions()
            ->whereIn('source', self::SYNCED_SOURCES)
            ->where('created_at', '>=', $subscription->created_at)
            ->toBase()
            ->selectRaw('count(*) as synced, count(category_id) as categorized')
            ->first();

        $synced = (int) ($counts->synced ?? 0);

        if ($synced < self::MIN_SYNCED) {
            return null;
        }

        return [
            'synced' => $synced,
            'categorizedPercent' => (int) floor(((int) $counts->categorized) * 100 / $synced),
        ];
    }
}
