<?php

namespace App\Features;

use App\Models\User;

/**
 * @api
 */
class CoinbaseIntegration
{
    /**
     * Off by default. Enable per-user with `php artisan feature:enable CoinbaseIntegration user@example.com`.
     */
    public function resolve(User $user): bool
    {
        return false;
    }
}
