<?php

namespace App\Features;

use App\Models\User;

/**
 * Lets the header import drawer recognise a Trade Republic or MyInvestor
 * export and map it to a cash and an investment account, instead of asking
 * for a column mapping. It only hides the interface; the endpoints behind it
 * (the external id on a created transaction, the duplicate check by external
 * id and the bulk balances write) are open to every signed-in user. Off for
 * everyone until activated per user.
 *
 * Guests never import, so they read it as off without a lookup.
 *
 * @api
 */
class BrokerImports
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
