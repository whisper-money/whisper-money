<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the cash-flow forecast ("Previsión") and everything that ships with
 * it, starting with the reorganised user menu. Off for everyone until
 * activated per user: with it off, nothing in the app changes.
 *
 * Guests never see the forecast, so they read it as off without a lookup.
 *
 * @api
 */
class Forecast
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
