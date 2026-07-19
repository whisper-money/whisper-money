<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the "Split" entry point in the transaction editor while the feature is
 * rolled out. Reads always honour splits that already exist in the data; this
 * flag only hides the UI/API path to create or change them.
 * Toggle per user / everyone with
 * `php artisan feature:enable App\\Features\\TransactionSplitting <target>`.
 *
 * @api
 */
class TransactionSplitting
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
