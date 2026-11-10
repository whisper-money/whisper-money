<?php

namespace App\Services\Subscriptions;

use App\Models\User;

/**
 * What a user has built up in the app so far, counted for the screens and
 * emails that argue for keeping the paid plan: the paywall a former subscriber
 * lands on, and the warning to a trial that is set to cancel. Both make the
 * same case — this is yours, and it is the syncing that stops — so they count
 * the same things.
 */
class UserSetupStats
{
    /**
     * @return array{accountsCount: int, transactionsCount: int, categoriesCount: int, rulesCount: int, connectionsCount: int}
     */
    public function for(User $user): array
    {
        return [
            'accountsCount' => $user->accounts()->count(),
            'transactionsCount' => $user->transactions()->count(),
            'categoriesCount' => $user->categories()->count(),
            'rulesCount' => $user->automationRules()->count(),
            'connectionsCount' => $user->bankingConnections()->count(),
        ];
    }
}
