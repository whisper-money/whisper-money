<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the parent/child category tree UI for gradual rollout. The backend
 * always understands nesting; this flag only controls whether users can create
 * and manage nested categories from the interface.
 *
 * @api
 */
class CategoryTree
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return false;
    }
}
