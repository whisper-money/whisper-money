<?php

namespace App\Services\Subscriptions;

use App\Enums\BankingConnectionStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * What a subscriber built in Whisper Money over the past year, as the short
 * list the annual renewal email shows.
 *
 * Counts only, the same kind the paywall quotes back to the user. Like the
 * monthly summary email, nothing here prints an amount of money: an email sits
 * in an inbox for years and shows on lock screens. A line whose count is zero
 * is left out rather than shown as nothing.
 *
 * Accounts, connections, rules, budgets and goals are what the user has now
 * (archived and broken ones left out); transactions and achievements are the
 * ones from the past year. Built while the mail renders, so the counts and
 * their number format follow the reader's locale.
 */
class YearInReview
{
    /**
     * @return list<string>
     */
    public function linesFor(User $user, CarbonInterface $since): array
    {
        $transactionsSince = fn () => $user->transactions()->where('created_at', '>=', $since);

        $counts = [
            '{1}:count account tracked|[2,*]:count accounts tracked' => $user->accounts()->notArchived()->count(),
            '{1}:count bank connection syncing on its own|[2,*]:count bank connections syncing on their own' => $user->bankingConnections()->where('status', BankingConnectionStatus::Active)->count(),
            '{1}:count transaction added this year|[2,*]:count transactions added this year' => $transactionsSince()->count(),
            '{1}:count transaction categorized this year|[2,*]:count transactions categorized this year' => $transactionsSince()->whereNotNull('category_id')->count(),
            '{1}:count automation rule doing the sorting for you|[2,*]:count automation rules doing the sorting for you' => $user->automationRules()->count(),
            '{1}:count budget|[2,*]:count budgets' => $user->budgets()->notArchived()->count(),
            '{1}:count savings goal|[2,*]:count savings goals' => $user->savingsGoals()->notArchived()->count(),
            '{1}:count achievement unlocked this year|[2,*]:count achievements unlocked this year' => $user->achievements()
                ->where('achieved_on', '>=', $since->toImmutable()->startOfMonth())
                ->count(),
        ];

        $lines = [];

        foreach (array_filter($counts) as $key => $count) {
            $lines[] = trans_choice($key, $count, ['count' => Number::format($count, locale: app()->getLocale())]);
        }

        return $lines;
    }
}
