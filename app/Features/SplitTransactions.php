<?php

namespace App\Features;

use App\Models\User;

/**
 * Splitting one transaction into parts with their own categories and labels.
 *
 * The flag gates only the way IN: creating a split, from the table, the edit
 * dialog or the MCP tool. Merging a split back is deliberately never gated, so
 * turning this off can never leave someone holding parts they cannot undo.
 *
 * @api
 */
class SplitTransactions
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
