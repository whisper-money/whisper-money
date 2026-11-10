<?php

namespace App\Services\Subscriptions;

use App\Enums\TransactionSource;
use App\Models\User;
use Laravel\Cashier\Subscription;

/**
 * What the plan has done for a trialing user so far: the movements their bank
 * connections synced since the trial started, and how many of those already
 * carry a category. Read by the trial-ending banner and email, which make the
 * case for keeping the plan with these two numbers.
 *
 * Only bank-synced rows count, because syncing is what the plan sells; manual
 * and imported rows would work just as well on the free plan.
 */
class TrialProgress
{
    /** The sources a bank connection writes; everything else the user entered. */
    private const SYNCED_SOURCES = [TransactionSource::EnableBanking, TransactionSource::Wise];

    /**
     * @return array{synced: int, categorizedPercent: int}
     */
    public function for(User $user, ?Subscription $subscription): array
    {
        if ($subscription === null) {
            return ['synced' => 0, 'categorizedPercent' => 0];
        }

        $counts = $user->transactions()
            ->whereIn('source', self::SYNCED_SOURCES)
            ->where('created_at', '>=', $subscription->created_at)
            ->toBase()
            ->selectRaw('count(*) as synced, count(category_id) as categorized')
            ->first();

        $synced = (int) ($counts->synced ?? 0);

        return [
            'synced' => $synced,
            'categorizedPercent' => $synced === 0 ? 0 : (int) floor(((int) $counts->categorized) * 100 / $synced),
        ];
    }
}
