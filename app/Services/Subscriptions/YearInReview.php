<?php

namespace App\Services\Subscriptions;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * What a subscriber built in Whisper Money over the past year, as the short
 * list the annual renewal email shows.
 *
 * Counts only, the same kind the paywall quotes back to the user. Like the
 * monthly summary email, nothing here prints an amount of money: an email sits
 * in an inbox for years and shows on lock screens. A line whose count is zero
 * is left out rather than shown as nothing.
 */
class YearInReview
{
    /**
     * @return list<string>
     */
    public function linesFor(User $user, CarbonInterface $since): array
    {
        $addedThisYear = fn () => $user->transactions()->where('created_at', '>=', $since);

        $counts = [
            '{1}:count account tracked|[2,*]:count accounts tracked' => $user->accounts()->count(),
            '{1}:count bank connection syncing on its own|[2,*]:count bank connections syncing on their own' => $user->bankingConnections()->count(),
            '{1}:count transaction added this year|[2,*]:count transactions added this year' => $addedThisYear()->count(),
            '{1}:count transaction categorized this year|[2,*]:count transactions categorized this year' => $addedThisYear()->whereNotNull('category_id')->count(),
            '{1}:count automation rule doing the sorting for you|[2,*]:count automation rules doing the sorting for you' => $user->automationRules()->count(),
            '{1}:count budget|[2,*]:count budgets' => $user->budgets()->count(),
            '{1}:count savings goal|[2,*]:count savings goals' => $user->savingsGoals()->count(),
            '{1}:count achievement unlocked this year|[2,*]:count achievements unlocked this year' => $user->achievements()
                ->where('achieved_on', '>=', $since->toImmutable()->startOfMonth())
                ->count(),
        ];

        $lines = [];

        foreach (array_filter($counts) as $key => $count) {
            $lines[] = trans_choice($key, $count, ['count' => number_format($count)]);
        }

        return $lines;
    }
}
